<?php
require_once __DIR__ . '/IpResolver.php';

class Tracker
{
    private int $retentionDays = 0;
    private int $cleanupHour = 3;
    private array $adminDefaults = ['user' => 'admin', 'pass' => 'admin123'];
    private array $retentionDefaults = ['days' => 0, 'cleanup_hour' => 3];
    private string $defaultLoginEntry = 'admin';
    private string $ipdbPath = '';
    private IpResolver $ipResolver;

    public function __construct(
        private PDO $db,
        private Redis $redis,
        private array $options = []
    ) {
        $this->adminDefaults = $this->options['app']['admin'] ?? $this->options['admin'] ?? $this->adminDefaults;
        $this->retentionDefaults = $this->options['retention'] ?? $this->retentionDefaults;
        $this->defaultLoginEntry = trim($this->options['security']['login_entry'] ?? $this->defaultLoginEntry) ?: $this->defaultLoginEntry;
        $this->retentionDays = max(0, (int) ($this->retentionDefaults['days'] ?? 0));
        $this->cleanupHour = min(23, max(0, (int) ($this->retentionDefaults['cleanup_hour'] ?? 3)));
        $this->ipdbPath = $this->options['ipdb']['path'] ?? (__DIR__ . '/../data/qqwry.ipdb');
        $this->ensureIpDbExists();
        $this->ipResolver = new IpResolver($this->ipdbPath);

        $this->ensureSiteDomainSchema();
        $this->ensurePageviewSchema();
        $this->ensureRollupSchema();
        $this->ensureShareSchema();
        $this->ensureSettingsSchema();
        $this->hydrateRetention();
        $this->maybeCleanupRetention();
    }

    public function createSite(string $name, string $domain): array
    {
        $trackingId = bin2hex(random_bytes(8));
        $normalizedDomain = $this->canonicalHost($domain);
        $statement = $this->db->prepare(
            'INSERT INTO sites (name, domain, tracking_id, created_at) VALUES (:name, :domain, :tracking_id, NOW())'
        );
        $statement->execute([
            ':name' => $name,
            ':domain' => $normalizedDomain,
            ':tracking_id' => $trackingId,
        ]);

        $siteId = (int) $this->db->lastInsertId();
        if ($normalizedDomain) {
            $this->addSiteDomain($siteId, $normalizedDomain);
        }

        return [
            'id' => $siteId,
            'name' => $name,
            'domain' => $normalizedDomain,
            'tracking_id' => $trackingId,
        ];
    }

    public function getSites(): array
    {
        $query = $this->db->query('SELECT id, name, domain, tracking_id, created_at FROM sites ORDER BY created_at DESC');

        return $query->fetchAll();
    }

    public function getSite(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT id, name, domain, tracking_id, created_at FROM sites WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);

        $site = $statement->fetch();

        return $site ?: null;
    }

    public function getSiteByTrackingId(string $trackingId): ?array
    {
        $cached = $this->redis->get("site:{$trackingId}");
        if ($cached) {
            return json_decode($cached, true);
        }

        $statement = $this->db->prepare('SELECT id, name, domain, tracking_id FROM sites WHERE tracking_id = :tracking_id LIMIT 1');
        $statement->execute([':tracking_id' => $trackingId]);
        $site = $statement->fetch();

        if ($site) {
            $this->redis->setex("site:{$trackingId}", 3600, json_encode($site));
        }

        return $site ?: null;
    }

    public function updateSite(int $id, string $name, string $domain): void
    {
        $normalizedDomain = $this->canonicalHost($domain);
        $statement = $this->db->prepare('SELECT tracking_id FROM sites WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $tracking = $statement->fetchColumn();

        $update = $this->db->prepare('UPDATE sites SET name = :name, domain = :domain WHERE id = :id');
        $update->execute([
            ':name' => $name,
            ':domain' => $normalizedDomain,
            ':id' => $id,
        ]);

        if ($normalizedDomain) {
            $this->addSiteDomain($id, $normalizedDomain);
        }

        if ($tracking) {
            $this->redis->del("site:{$tracking}");
        }
    }

    public function getSiteDomains(int $siteId): array
    {
        $statement = $this->db->prepare('SELECT id, domain FROM site_domains WHERE site_id = :site_id ORDER BY id DESC');
        $statement->execute([':site_id' => $siteId]);

        return $statement->fetchAll();
    }

    public function addSiteDomain(int $siteId, string $domain): void
    {
        $normalized = $this->canonicalHost($domain);
        if (!$normalized) {
            return;
        }

        $insert = $this->db->prepare(
            'INSERT IGNORE INTO site_domains (site_id, domain, created_at) VALUES (:site_id, :domain, NOW())'
        );
        $insert->execute([
            ':site_id' => $siteId,
            ':domain' => $normalized,
        ]);
    }

    public function deleteSiteDomain(int $siteId, int $domainId): void
    {
        $delete = $this->db->prepare('DELETE FROM site_domains WHERE site_id = :site_id AND id = :id LIMIT 1');
        $delete->execute([
            ':site_id' => $siteId,
            ':id' => $domainId,
        ]);
    }

    public function recordPageview(string $trackingId, array $payload): void
    {
        $site = $this->getSiteByTrackingId($trackingId);
        if (!$site) {
            return;
        }

        $parsedUrl = $this->parseUrl($payload['path'] ?? null, $site['domain'] ?? null);
        $path = $parsedUrl['path'];
        $host = $parsedUrl['host'];
        $canonicalHost = $parsedUrl['canonical'];
        $allowedDomains = $this->getAllSiteDomains((int) $site['id']);
        if ($canonicalHost && !empty($allowedDomains) && !in_array($canonicalHost, $allowedDomains, true)) {
            return;
        }
        $ip = $this->sanitizeIp($payload['ip'] ?? null);
        $ipHash = $ip ? hash('sha256', $ip) : null;
        $uniqueKey = sprintf('unique:%s:%s', $site['id'], date('Y-m-d'));
        $isUnique = false;

        $sessionId = $payload['session_id'] ?? ($ipHash ?: bin2hex(random_bytes(8)));
        $duration = max(0, (int) ($payload['duration'] ?? 0));
        $pageCount = max(1, (int) ($payload['page_count'] ?? 1));
        $userAgent = $payload['user_agent'] ?? '';
        $isBot = $this->isBot($userAgent);
        $isMobile = $this->isMobile($userAgent);
        $keyword = $this->extractKeyword($payload['referrer'] ?? '');
        $ipMeta = $this->resolveIpMeta($ip);
        $countryName = $ipMeta['country_name'] ?? '未知';
        $regionName = $ipMeta['region_name'] ?? '未知';
        $cityName = $ipMeta['city_name'] ?? '';
        $ispName = $ipMeta['isp_domain'] ?? '未知运营商';
        $countryCode = $ipMeta['country_code'] ?? '';
        $continentCode = $ipMeta['continent_code'] ?? '';

        if ($ipHash) {
            $isUnique = (bool) $this->redis->sAdd($uniqueKey, $ipHash);
            $this->redis->expire($uniqueKey, 172800);
        }

        $statement = $this->db->prepare(
            'INSERT INTO pageviews (site_id, host, canonical_host, path, referrer, user_agent, ip_address, ip_hash, session_id, duration_seconds, page_count, keyword, is_mobile, is_bot, is_unique, country_name, region_name, city_name, isp_domain, country_code, continent_code, occurred_at) VALUES
            (:site_id, :host, :canonical_host, :path, :referrer, :user_agent, :ip_address, :ip_hash, :session_id, :duration_seconds, :page_count, :keyword, :is_mobile, :is_bot, :is_unique, :country_name, :region_name, :city_name, :isp_domain, :country_code, :continent_code, NOW())'
        );
        $statement->execute([
            ':site_id' => $site['id'],
            ':host' => $host,
            ':canonical_host' => $canonicalHost,
            ':path' => $path,
            ':referrer' => $payload['referrer'] ?? null,
            ':user_agent' => $userAgent,
            ':ip_address' => $ip,
            ':ip_hash' => $ipHash,
            ':session_id' => $sessionId,
            ':duration_seconds' => $duration,
            ':page_count' => $pageCount,
            ':keyword' => $keyword,
            ':is_mobile' => $isMobile ? 1 : 0,
            ':is_bot' => $isBot ? 1 : 0,
            ':is_unique' => $isUnique ? 1 : 0,
            ':country_name' => $countryName,
            ':region_name' => $regionName,
            ':city_name' => $cityName,
            ':isp_domain' => $ispName,
            ':country_code' => $countryCode,
            ':continent_code' => $continentCode,
        ]);

        $this->updateRollups(
            (int) $site['id'],
            new DateTimeImmutable('now'),
            $duration,
            $pageCount,
            $isUnique,
            $sessionId
        );
    }

    private function updateRollups(int $siteId, DateTimeImmutable $occurredAt, int $duration, int $pageCount, bool $isUnique, ?string $sessionId): void
    {
        $bucketStart = $occurredAt->setTime((int) $occurredAt->format('H'), 0, 0);
        $bucketKey = $bucketStart->format('Y-m-d H:i:s');

        $sessionIncrement = 0;
        $bounceIncrement = 0;
        $durationIncrement = 0;
        $pageIncrement = 0;

        if ($sessionId) {
            $sessionKey = sprintf('rollup:session:%d:%s', $siteId, $bucketStart->format('YmdH'));
            $isFirstSession = (bool) $this->redis->sAdd($sessionKey, $sessionId);
            $this->redis->expire($sessionKey, 172800);

            if ($isFirstSession) {
                $sessionIncrement = 1;
                $durationIncrement = $duration;
                $pageIncrement = $pageCount;
                $bounceIncrement = ($pageCount <= 1) ? 1 : 0;
            }
        }

        $uvIncrement = $isUnique ? 1 : 0;
        $ipIncrement = $uvIncrement;

        $rollup = $this->db->prepare(
            'INSERT INTO pageview_rollups (site_id, bucket_start, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
             VALUES (:site_id, :bucket_start, 1, :uv, :ip_count, :session_count, :duration_sum, :page_sum, :bounce_count)
             ON DUPLICATE KEY UPDATE
                pv = pv + 1,
                uv = uv + VALUES(uv),
                ip_count = ip_count + VALUES(ip_count),
                session_count = session_count + VALUES(session_count),
                duration_sum = duration_sum + VALUES(duration_sum),
                page_sum = page_sum + VALUES(page_sum),
                bounce_count = bounce_count + VALUES(bounce_count)'
        );

        $rollup->execute([
            ':site_id' => $siteId,
            ':bucket_start' => $bucketKey,
            ':uv' => $uvIncrement,
            ':ip_count' => $ipIncrement,
            ':session_count' => $sessionIncrement,
            ':duration_sum' => $durationIncrement,
            ':page_sum' => $pageIncrement,
            ':bounce_count' => $bounceIncrement,
        ]);
    }

    private function rollupRangeBounds(string $range): array
    {
        $now = new DateTimeImmutable('now');
        $ranges = [
            'today' => $now->setTime(0, 0),
            'yesterday' => $now->modify('-1 day')->setTime(0, 0),
            '7d' => $now->modify('-6 day')->setTime(0, 0),
            '30d' => $now->modify('-29 day')->setTime(0, 0),
            'all' => new DateTimeImmutable('1970-01-01 00:00:00'),
        ];

        $start = $ranges[$range] ?? $ranges['today'];
        $end = ($range === 'yesterday') ? $now->setTime(0, 0) : $now;

        return [$start, $end];
    }

    private function aggregateRollups(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $statement = $this->db->prepare(
            'SELECT SUM(pv) as views, SUM(uv) as uniques, SUM(ip_count) as ip_count, SUM(session_count) as session_count,
                SUM(duration_sum) as duration_sum, SUM(page_sum) as page_sum, SUM(bounce_count) as bounce_count,
                COUNT(*) as buckets
             FROM pageview_rollups
             WHERE site_id = :site_id AND bucket_start >= :start AND bucket_start < :end'
        );

        $statement->execute([
            ':site_id' => $siteId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
        ]);

        $row = $statement->fetch() ?: [];

        return [
            'has_data' => ((int) ($row['buckets'] ?? 0)) > 0,
            'views' => (int) ($row['views'] ?? 0),
            'uniques' => (int) ($row['uniques'] ?? 0),
            'ip_count' => (int) ($row['ip_count'] ?? 0),
            'session_count' => (int) ($row['session_count'] ?? 0),
            'duration_sum' => (int) ($row['duration_sum'] ?? 0),
            'page_sum' => (int) ($row['page_sum'] ?? 0),
            'bounce_count' => (int) ($row['bounce_count'] ?? 0),
        ];
    }

    private function rollupSummaryStats(array $rollupTotals): array
    {
        $sessions = max(0, (int) ($rollupTotals['session_count'] ?? 0));
        $views = (int) ($rollupTotals['views'] ?? 0);
        $uniques = (int) ($rollupTotals['uniques'] ?? 0);
        $ips = (int) ($rollupTotals['ip_count'] ?? 0);
        $durationSum = (int) ($rollupTotals['duration_sum'] ?? 0);
        $pageSum = (int) ($rollupTotals['page_sum'] ?? 0);
        $bounceCount = (int) ($rollupTotals['bounce_count'] ?? 0);

        return [
            'ips' => $ips,
            'views' => $views,
            'uv' => $uniques,
            'new' => $uniques,
            'sessions' => $sessions,
            'avg_pages' => $sessions > 0 ? round($pageSum / $sessions, 2) : 0,
            'avg_duration' => $sessions > 0 ? ($durationSum / $sessions) : 0,
            'bounce_rate' => $sessions > 0 ? ($bounceCount / $sessions) : 0,
        ];
    }

    private function getRollupDailyStats(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $statement = $this->db->prepare(
            "SELECT DATE(bucket_start) as day, SUM(pv) as views, SUM(uv) as uniques, SUM(ip_count) as ip_count
             FROM pageview_rollups
             WHERE site_id = :site_id AND bucket_start >= :start AND bucket_start < :end
             GROUP BY day
             ORDER BY day ASC"
        );

        $statement->execute([
            ':site_id' => $siteId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
        ]);

        return $statement->fetchAll();
    }

    private function getRollupHourlyStats(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $statement = $this->db->prepare(
            "SELECT bucket_start as hour, pv as views, uv as uniques, ip_count as ips
             FROM pageview_rollups
             WHERE site_id = :site_id AND bucket_start >= :start AND bucket_start < :end
             ORDER BY bucket_start ASC"
        );

        $statement->execute([
            ':site_id' => $siteId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
        ]);

        return $statement->fetchAll();
    }

    public function getOverview(int $siteId, string $range = 'today'): array
    {
        return [
            'totals' => $this->getTotals($siteId, $range),
            'daily' => $this->getDailyStats($siteId, $range),
            'hourly' => $this->getHourlyStats($siteId, $range),
            'trend' => $this->getTrendLines($siteId, $range),
            'predictions' => $this->getPredictions($siteId),
            'regions' => $this->getRegionStats($siteId, $range, 20),
            'china_map' => $this->getRegionStats($siteId, $range, 200),
            'country_map' => $this->getCountryStats($siteId, $range, 200),
            'devices' => $this->getDeviceBreakdown($siteId, $range),
            'browsers' => $this->getBrowserBreakdown($siteId, $range, 6),
            'new_vs_returning' => $this->getNewVsReturning($siteId, $range),
            'top_referrers' => $this->getTopReferrers($siteId, $range),
            'top_pages' => $this->getTopPages($siteId, $range, 10),
            'entry_pages' => $this->getEntryPages($siteId, $range, 15),
        ];
    }

    public function getContentData(int $siteId, string $range = 'today', array $filters = [], int $page = 1, int $perPage = 50): array
    {
        return [
            'active' => $this->getActiveSessions($siteId),
            'details' => $this->getVisitDetails($siteId, $filters, $page, $perPage),
            'total_sessions' => $this->getVisitDetailCount($siteId, $filters),
        ];
    }

    public function getKeywordData(int $siteId, string $range = 'today'): array
    {
        return [
            'keywords' => $this->getKeywords($siteId, $range),
        ];
    }

    public function getSearchEngineData(int $siteId, string $range = 'today'): array
    {
        return [
            'engines' => $this->getSearchEngines($siteId, $range),
        ];
    }

    public function getExternalLinkData(int $siteId, string $range = 'today'): array
    {
        return [
            'links' => $this->getExternalLinks($siteId, $range),
        ];
    }

    public function getBotData(int $siteId, string $range = 'today', ?string $engine = null): array
    {
        return [
            'bot' => $this->getBotTraffic($siteId, $range, $engine),
            'engines' => $this->getBotEngines($siteId, $range),
        ];
    }

    public function getMobileData(int $siteId, string $range = 'today'): array
    {
        return [
            'breakdown' => $this->getMobileBreakdown($siteId, $range),
        ];
    }

    public function getTrendData(int $siteId, string $range = 'today'): array
    {
        return [
            'daily' => $this->getDailyStats($siteId, $range),
            'hourly' => $this->getHourlyStats($siteId, $range),
        ];
    }

    public function getTrendLines(int $siteId, string $range = 'today'): array
    {
        $now = new DateTimeImmutable('now');
        $granularity = 'day';
        $primaryLabel = '当前区间';
        $compareLabel = null;

        if ($range === 'today') {
            $granularity = 'hour';
            $primaryLabel = '今天';
            $compareLabel = '昨天';

            $dayStart = $now->setTime(0, 0);
            $primary = $this->normalizeHourlySeries($this->getHourlyStats($siteId, 'today'), $dayStart);
            $compare = $this->normalizeHourlySeries(
                $this->getHourlyStatsForWindow($siteId, $dayStart->modify('-1 day'), $dayStart),
                $dayStart->modify('-1 day')
            );

            return [
                'granularity' => $granularity,
                'labels' => $primary['labels'],
                'primary_label' => $primaryLabel,
                'primary' => $primary['series'],
                'compare_label' => $compareLabel,
                'compare' => $compare['series'],
            ];
        }

        if ($range === 'yesterday') {
            $granularity = 'hour';
            $primaryLabel = '昨天';
            $compareLabel = '前天';

            $yesterdayStart = $now->modify('-1 day')->setTime(0, 0);
            $primary = $this->normalizeHourlySeries($this->getHourlyStats($siteId, 'yesterday'), $yesterdayStart);
            $compare = $this->normalizeHourlySeries(
                $this->getHourlyStatsForWindow($siteId, $yesterdayStart->modify('-1 day'), $yesterdayStart),
                $yesterdayStart->modify('-1 day')
            );

            return [
                'granularity' => $granularity,
                'labels' => $primary['labels'],
                'primary_label' => $primaryLabel,
                'primary' => $primary['series'],
                'compare_label' => $compareLabel,
                'compare' => $compare['series'],
            ];
        }

        $window = $this->rangeWindow($range);
        $primary = $this->normalizeDailySeries($siteId, $range, new DateTimeImmutable($window['start']), new DateTimeImmutable($window['end']));

        return [
            'granularity' => 'day',
            'labels' => $primary['labels'],
            'primary_label' => $primaryLabel,
            'primary' => $primary['series'],
            'compare_label' => null,
            'compare' => null,
        ];
    }

    public function getVisitorEnv(int $siteId, string $range = 'today'): array
    {
        return [
            'devices' => $this->getDeviceBreakdown($siteId, $range),
            'browsers' => $this->getBrowserBreakdown($siteId, $range),
        ];
    }

    public function getRegionData(int $siteId, string $range = 'today'): array
    {
        return [
            'regions' => $this->getRegionStats($siteId, $range, 200),
            'countries' => $this->getCountryStats($siteId, $range, 200),
        ];
    }

    public function getIspData(int $siteId, string $range = 'today'): array
    {
        return [
            'isps' => $this->getIspStats($siteId, $range),
        ];
    }

    public function getAudienceData(int $siteId, string $range = 'today'): array
    {
        return [
            'new_vs_returning' => $this->getNewVsReturning($siteId, $range),
        ];
    }

    public function getReferrerData(int $siteId, string $range = 'today', array $filters = []): array
    {
        $refs = $this->getReferrerSummary($siteId, $range, $filters);
        return [
            'referrers' => $refs['rows'],
            'ref_summary' => $refs['summary'],
        ];
    }

    public function getEntryData(int $siteId, string $range = 'today', array $filters = []): array
    {
        $entry = $this->getEntrySummary($siteId, $range);
        return [
            'entries' => $entry['rows'],
            'entry_summary' => $entry['summary'],
        ];
    }

    public function getPageData(int $siteId, string $range = 'today'): array
    {
        $pages = $this->getPageSummary($siteId, $range);
        return [
            'pages' => $pages['rows'],
            'page_summary' => $pages['summary'],
        ];
    }

    public function getDashboardData(int $siteId): array
    {
        return [
            'totals' => $this->getTotals($siteId),
            'daily' => $this->getDailyStats($siteId),
            'top_pages' => $this->getTopPages($siteId),
            'top_referrers' => $this->getTopReferrers($siteId),
            'recent' => $this->getRecentPageviews($siteId),
            'keywords' => $this->getKeywords($siteId),
            'bot' => $this->getBotTraffic($siteId),
            'predictions' => $this->getPredictions($siteId),
        ];
    }

    private function getTotals(int $siteId, string $range): array
    {
        [$start, $end] = $this->rollupRangeBounds($range);
        $rollupTotals = $this->aggregateRollups($siteId, $start, $end);

        if ($rollupTotals['has_data']) {
            return [
                'views' => $rollupTotals['views'],
                'uniques' => $rollupTotals['uniques'],
                'ip_count' => $rollupTotals['ip_count'],
                'averages' => $this->getVisitAverages($siteId, $range, $rollupTotals),
                'bounce_rate' => $this->getBounceRate($siteId, $range, $rollupTotals),
            ];
        }

        $rawTotals = $this->getTotalsFromRaw($siteId, $range);

        return [
            'views' => $rawTotals['views'],
            'uniques' => $rawTotals['uniques'],
            'ip_count' => $rawTotals['ip_count'],
            'averages' => $this->getVisitAverages($siteId, $range, $rollupTotals),
            'bounce_rate' => $this->getBounceRate($siteId, $range, $rollupTotals),
        ];
    }

    private function getTotalsFromRaw(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT COUNT(*) as views, SUM(is_unique) as uniques, COUNT(DISTINCT ip_hash) as ip_count
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        $row = $statement->fetch();

        return [
            'views' => (int) ($row['views'] ?? 0),
            'uniques' => (int) ($row['uniques'] ?? 0),
            'ip_count' => (int) ($row['ip_count'] ?? 0),
        ];
    }

    private function getDailyStats(int $siteId, string $range): array
    {
        [$start, $end] = $this->rollupRangeBounds($range);
        $rollup = $this->getRollupDailyStats($siteId, $start, $end);
        if (!empty($rollup)) {
            return $rollup;
        }

        [$rangeSql, $params] = $this->rangeClause($range, true);
        $statement = $this->db->prepare(
            "SELECT DATE(occurred_at) as day, COUNT(*) as views, SUM(is_unique) as uniques, COUNT(DISTINCT ip_hash) as ip_count
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}
            GROUP BY day
            ORDER BY day ASC"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getTopPages(int $siteId, string $range, int $limit = 50): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT path, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}
            GROUP BY path
            ORDER BY ips DESC
            LIMIT {$limit}"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getTopReferrers(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $domains = $this->getAllSiteDomains($siteId);
        $statement = $this->db->prepare(
            "SELECT referrer, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips
            FROM pageviews
            WHERE site_id = :site_id AND referrer IS NOT NULL AND referrer != '' AND is_bot = 0 {$rangeSql}
            GROUP BY referrer
            ORDER BY ips DESC
            LIMIT 50"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        $rows = $statement->fetchAll();

        if ($domains) {
            $rows = array_values(array_filter($rows, function ($row) use ($domains) {
                return !$this->isOwnReferrer($row['referrer'] ?? '', $domains);
            }));
        }

        return $rows;
    }

    private function getAllSiteDomains(int $siteId): array
    {
        $domains = [];
        $site = $this->getSite($siteId);
        if ($site && !empty($site['domain'])) {
            $domains[] = $this->canonicalHost($site['domain']);
        }

        foreach ($this->getSiteDomains($siteId) as $row) {
            if (!empty($row['domain'])) {
                $domains[] = $this->canonicalHost($row['domain']);
            }
        }

        $domains = array_filter(array_unique($domains));

        return array_values($domains);
    }

    private function isOwnReferrer(string $referrer, array $domains): bool
    {
        if (!$referrer || !$domains) {
            return false;
        }

        $host = parse_url($referrer, PHP_URL_HOST) ?? '';
        if (!$host) {
            $host = $referrer;
        }
        $host = strtolower(trim($host));

        foreach ($domains as $domain) {
            $domain = strtolower($domain);
            if (!$domain) {
                continue;
            }

            if ($host === $domain || preg_match('/(^|\.)' . preg_quote($domain, '/') . '$/i', $host)) {
                return true;
            }
        }

        return false;
    }

    private function getEntryPages(int $siteId, string $range, int $limit = 20): array
    {
        [$rangeSql, $params] = $this->rangeClause($range, true);
        $statement = $this->db->prepare(
            "SELECT p.path, COUNT(*) as views, COUNT(DISTINCT p.ip_hash) as ips, SUM(p.is_unique) as uniques,
                AVG(p.page_count) as avg_pages, AVG(p.duration_seconds) as avg_duration,
                AVG(CASE WHEN p.page_count = 1 THEN 1 ELSE 0 END) as bounce_rate
            FROM (
                SELECT MIN(id) as first_id, session_id
                FROM pageviews
                WHERE site_id = :site_id AND is_bot = 0 AND session_id IS NOT NULL {$rangeSql}
                GROUP BY session_id
            ) s
            JOIN pageviews p ON p.id = s.first_id
            GROUP BY p.path
            ORDER BY ips DESC
            LIMIT {$limit}"
        );

        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getEntrySummary(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range, true);
        $base = "FROM (
                SELECT MIN(id) as first_id, session_id
                FROM pageviews
                WHERE site_id = :site_id AND is_bot = 0 AND session_id IS NOT NULL {$rangeSql}
                GROUP BY session_id
            ) s
            JOIN pageviews p ON p.id = s.first_id";

        [$rollupStart, $rollupEnd] = $this->rollupRangeBounds($range);
        $rollupTotals = $this->aggregateRollups($siteId, $rollupStart, $rollupEnd);
        $summary = $rollupTotals['has_data'] ? $this->rollupSummaryStats($rollupTotals) : null;

        if ($summary === null) {
            $summaryStmt = $this->db->prepare(
                "SELECT COUNT(*) as sessions, COUNT(DISTINCT p.ip_hash) as ips, SUM(p.is_unique) as uniques,
                    SUM(p.page_count) as views, AVG(p.page_count) as avg_pages,
                    AVG(p.duration_seconds) as avg_duration,
                    AVG(CASE WHEN p.page_count = 1 THEN 1 ELSE 0 END) as bounce_rate
                {$base}"
            );
            $summaryStmt->execute(array_merge([':site_id' => $siteId], $params));
            $summary = $summaryStmt->fetch() ?: [];
        }

        $rowsStmt = $this->db->prepare(
            "SELECT p.path, COUNT(*) as sessions, COUNT(DISTINCT p.ip_hash) as ips,
                SUM(p.is_unique) as uniques, SUM(p.page_count) as views,
                AVG(p.page_count) as avg_pages, AVG(p.duration_seconds) as avg_duration,
                AVG(CASE WHEN p.page_count = 1 THEN 1 ELSE 0 END) as bounce_rate
            {$base}
            GROUP BY p.path
            ORDER BY ips DESC
            LIMIT 200"
        );
        $rowsStmt->execute($this->filterParams($rowsStmt->queryString, array_merge([':site_id' => $siteId], $params)));
        $rows = $rowsStmt->fetchAll();

        return [
            'summary' => [
                'ips' => (int) ($summary['ips'] ?? 0),
                'views' => (int) ($summary['views'] ?? 0),
                'uv' => (int) ($summary['uv'] ?? ($summary['uniques'] ?? 0)),
                'new' => (int) ($summary['new'] ?? ($summary['uniques'] ?? 0)),
                'sessions' => (int) ($summary['sessions'] ?? 0),
                'avg_pages' => round((float) ($summary['avg_pages'] ?? 0), 2),
                'avg_duration' => (float) ($summary['avg_duration'] ?? 0),
                'bounce_rate' => (float) ($summary['bounce_rate'] ?? 0),
            ],
            'rows' => $rows,
        ];
    }

    private function getPageSummary(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $baseWhere = "site_id = :site_id AND is_bot = 0 {$rangeSql}";

        [$rollupStart, $rollupEnd] = $this->rollupRangeBounds($range);
        $rollupTotals = $this->aggregateRollups($siteId, $rollupStart, $rollupEnd);
        $summary = $rollupTotals['has_data'] ? $this->rollupSummaryStats($rollupTotals) : null;

        if ($summary === null) {
            $summaryStmt = $this->db->prepare(
                "SELECT COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips, SUM(is_unique) as uniques,
                    COUNT(DISTINCT session_id) as sessions,
                    AVG(page_count) as avg_pages, AVG(duration_seconds) as avg_duration,
                    AVG(CASE WHEN page_count = 1 THEN 1 ELSE 0 END) as bounce_rate
                FROM pageviews WHERE {$baseWhere}"
            );
            $summaryStmt->execute(array_merge([':site_id' => $siteId], $params));
            $summary = $summaryStmt->fetch() ?: [];
        }

        $rowsStmt = $this->db->prepare(
            "SELECT COALESCE(path,'/') as path, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips,
                SUM(is_unique) as uniques, AVG(page_count) as avg_pages, AVG(duration_seconds) as avg_duration,
                AVG(CASE WHEN page_count = 1 THEN 1 ELSE 0 END) as bounce_rate
            FROM pageviews
            WHERE {$baseWhere}
            GROUP BY path
            ORDER BY ips DESC
            LIMIT 200"
        );
        $rowsStmt->execute(array_merge([':site_id' => $siteId], $params));

        return [
            'summary' => [
                'ips' => (int) ($summary['ips'] ?? 0),
                'views' => (int) ($summary['views'] ?? 0),
                'uv' => (int) ($summary['uv'] ?? ($summary['uniques'] ?? 0)),
                'new' => (int) ($summary['new'] ?? ($summary['uniques'] ?? 0)),
                'sessions' => (int) ($summary['sessions'] ?? 0),
                'avg_pages' => round((float) ($summary['avg_pages'] ?? 0), 2),
                'avg_duration' => (float) ($summary['avg_duration'] ?? 0),
                'bounce_rate' => (float) ($summary['bounce_rate'] ?? 0),
            ],
            'rows' => $rowsStmt->fetchAll(),
        ];
    }

    private function getReferrerSummary(int $siteId, string $range, array $filters = []): array
    {
        [$rangeSql, $params] = $this->rangeClause($range, true);
        $conditions = [
            'p.is_bot = 0',
            'p.session_id IS NOT NULL',
            'p.referrer IS NOT NULL',
            "p.referrer != ''",
        ];

        if (!empty($filters['device'])) {
            if ($filters['device'] === 'desktop') {
                $conditions[] = 'p.is_mobile = 0';
            } elseif ($filters['device'] === 'mobile') {
                $conditions[] = 'p.is_mobile = 1';
            }
        }

        if (!empty($filters['visitor'])) {
            if ($filters['visitor'] === 'new') {
                $conditions[] = 'p.is_unique = 1';
            } elseif ($filters['visitor'] === 'return') {
                $conditions[] = 'p.is_unique = 0';
            }
        }

        $base = "FROM (
                SELECT MIN(id) as first_id, session_id
                FROM pageviews
                WHERE site_id = :site_id AND is_bot = 0 AND session_id IS NOT NULL {$rangeSql}
                GROUP BY session_id
            ) s
            JOIN pageviews p ON p.id = s.first_id";

        $where = implode(' AND ', $conditions);
        $domains = $this->getAllSiteDomains($siteId);

        $rowsStmt = $this->db->prepare(
            "SELECT p.referrer, COUNT(*) as sessions, COUNT(DISTINCT p.ip_hash) as ips,
                SUM(p.is_unique) as uniques, SUM(p.page_count) as views,
                AVG(p.page_count) as avg_pages, AVG(p.duration_seconds) as avg_duration,
                AVG(CASE WHEN p.page_count = 1 THEN 1 ELSE 0 END) as bounce_rate
            {$base}
            WHERE {$where}
            GROUP BY p.referrer
            ORDER BY ips DESC
            LIMIT 200"
        );
        $rowsStmt->execute($this->filterParams($rowsStmt->queryString, array_merge([':site_id' => $siteId], $params)));
        $rows = $rowsStmt->fetchAll();

        $filtered = [];
        foreach ($rows as $row) {
            if ($domains && $this->isOwnReferrer($row['referrer'], $domains)) {
                continue;
            }
            $filtered[] = $row;
        }

        $totals = [
            'ips' => 0,
            'views' => 0,
            'uv' => 0,
            'new' => 0,
            'sessions' => 0,
            'avg_pages' => 0,
            'avg_duration' => 0,
            'bounce_rate' => 0,
        ];

        $weightedPages = 0;
        $weightedDuration = 0;
        $weightedBounce = 0;

        foreach ($filtered as $row) {
            $sessions = (int) ($row['sessions'] ?? 0);
            $totals['ips'] += (int) ($row['ips'] ?? 0);
            $totals['views'] += (int) ($row['views'] ?? 0);
            $totals['uv'] += (int) ($row['uniques'] ?? 0);
            $totals['new'] += (int) ($row['uniques'] ?? 0);
            $totals['sessions'] += $sessions;
            $weightedPages += (float) ($row['avg_pages'] ?? 0) * $sessions;
            $weightedDuration += (float) ($row['avg_duration'] ?? 0) * $sessions;
            $weightedBounce += (float) ($row['bounce_rate'] ?? 0) * $sessions;
        }

        if ($totals['sessions'] > 0) {
            $totals['avg_pages'] = round($weightedPages / $totals['sessions'], 2);
            $totals['avg_duration'] = round($weightedDuration / $totals['sessions'], 2);
            $totals['bounce_rate'] = $weightedBounce / $totals['sessions'];
        }

        return [
            'summary' => $totals,
            'rows' => $filtered,
        ];
    }

    private function getRecentPageviews(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT path, referrer, user_agent, occurred_at
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}
            ORDER BY occurred_at DESC
            LIMIT 20"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getActiveSessions(int $siteId): array
    {
        $windows = [5, 15, 30];
        $results = [];

        foreach ($windows as $minutes) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(DISTINCT session_id) as sessions
                 FROM pageviews
                 WHERE site_id = :site_id AND is_bot = 0 AND session_id IS NOT NULL
                   AND occurred_at >= DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)"
            );
            $stmt->execute([':site_id' => $siteId]);
            $row = $stmt->fetch();
            $results[$minutes] = (int)($row['sessions'] ?? 0);
        }

        return $results;
    }

    private function visitFiltersWindow(array $filters): array
    {
        $today = new DateTimeImmutable('today');
        $earliest = $today->modify('-14 days');
        $dateStr = $filters['date'] ?? $today->format('Y-m-d');
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $dateStr) ?: $today;
        if ($parsed < $earliest) {
            $parsed = $earliest;
        }

        $start = $parsed->setTime(0, 0, 0);
        $end = $start->modify('+1 day');

        return [$start, $end];
    }

    private function getVisitDetailCount(int $siteId, array $filters): int
    {
        [$start, $end] = $this->visitFiltersWindow($filters);

        $conditions = ['1=1'];
        $params = [
            ':site_id' => $siteId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
        ];

        if (!empty($filters['ip'])) {
            $conditions[] = 'p.ip_address LIKE :ip';
            $params[':ip'] = '%' . $filters['ip'] . '%';
        }
        if (!empty($filters['keyword'])) {
            $conditions[] = 'p.keyword LIKE :keyword';
            $params[':keyword'] = '%' . $filters['keyword'] . '%';
        }
        if (!empty($filters['entry'])) {
            $conditions[] = 'entry.path LIKE :entry';
            $params[':entry'] = '%' . $filters['entry'] . '%';
        }
        if (!empty($filters['session'])) {
            $conditions[] = 'p.session_id LIKE :session';
            $params[':session'] = '%' . $filters['session'] . '%';
        }
        if (!empty($filters['visitor']) && in_array($filters['visitor'], ['new', 'return'], true)) {
            $conditions[] = $filters['visitor'] === 'new' ? 'p.is_unique = 1' : 'p.is_unique = 0';
        }

        $engineCase = $this->searchEngineCase('p');
        $engineHaving = '';
        if (!empty($filters['engine'])) {
            $engineHaving = 'HAVING engine = :engine';
            $params[':engine'] = $filters['engine'];
        }

        $sql = "SELECT COUNT(*) as total FROM (
                    SELECT p.session_id, {$engineCase} as engine
                    FROM (
                        SELECT MIN(id) as first_id, session_id
                        FROM pageviews
                        WHERE site_id = :site_id AND is_bot = 0 AND session_id IS NOT NULL
                          AND occurred_at BETWEEN :start AND :end
                        GROUP BY session_id
                    ) s
                    JOIN pageviews p ON p.id = s.first_id
                    LEFT JOIN pageviews entry ON entry.id = s.first_id
                    WHERE " . implode(' AND ', $conditions) . "
                    {$engineHaving}
                ) t";

        $stmt = $this->db->prepare($sql);
        $filtered = $this->filterParams($sql, $params);
        $filtered[':site_id'] = $siteId;
        $stmt->execute($filtered);
        $row = $stmt->fetch();

        return (int)($row['total'] ?? 0);
    }

    private function getVisitDetails(int $siteId, array $filters, int $page = 1, int $perPage = 50): array
    {
        [$start, $end] = $this->visitFiltersWindow($filters);
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $conditions = ['1=1'];
        $params = [
            ':site_id' => $siteId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
            ':limit' => $perPage,
            ':offset' => $offset,
        ];

        if (!empty($filters['ip'])) {
            $conditions[] = 'p.ip_address LIKE :ip';
            $params[':ip'] = '%' . $filters['ip'] . '%';
        }
        if (!empty($filters['keyword'])) {
            $conditions[] = 'p.keyword LIKE :keyword';
            $params[':keyword'] = '%' . $filters['keyword'] . '%';
        }
        if (!empty($filters['entry'])) {
            $conditions[] = 'entry.path LIKE :entry';
            $params[':entry'] = '%' . $filters['entry'] . '%';
        }
        if (!empty($filters['session'])) {
            $conditions[] = 'p.session_id LIKE :session';
            $params[':session'] = '%' . $filters['session'] . '%';
        }
        if (!empty($filters['visitor']) && in_array($filters['visitor'], ['new', 'return'], true)) {
            $conditions[] = $filters['visitor'] === 'new' ? 'p.is_unique = 1' : 'p.is_unique = 0';
        }
        if (!empty($filters['city'])) {
            $conditions[] = "COALESCE(NULLIF(p.city_name,''), NULLIF(p.region_name,''), '未知') LIKE :city";
            $params[':city'] = '%' . $filters['city'] . '%';
        }

        $engineCase = $this->searchEngineCase('p');
        $engineSelect = ", {$engineCase} as engine";
        $engineHaving = '';
        if (!empty($filters['engine'])) {
            $engineHaving = 'HAVING engine = :engine';
            $params[':engine'] = $filters['engine'];
        }

        $sql = "SELECT
                    p.occurred_at,
                    p.session_id,
                    p.ip_address,
                    p.is_unique,
                    p.user_agent,
                    p.referrer,
                    p.path,
                    p.keyword,
                    p.duration_seconds,
                    p.page_count,
                    entry.path as entry_path,
                    COALESCE(NULLIF(p.city_name,''), NULLIF(p.region_name,''), '未知') as region
                    {$engineSelect}
                FROM (
                    SELECT MIN(id) as first_id, session_id
                    FROM pageviews
                    WHERE site_id = :site_id AND is_bot = 0 AND session_id IS NOT NULL
                      AND occurred_at BETWEEN :start AND :end
                    GROUP BY session_id
                ) s
                JOIN pageviews p ON p.id = s.first_id
                LEFT JOIN pageviews entry ON entry.id = s.first_id
                WHERE " . implode(' AND ', $conditions) . "
                {$engineHaving}
                ORDER BY p.occurred_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        $filtered = $this->filterParams($sql, $params);
        $filtered[':site_id'] = $siteId;
        $stmt->execute($filtered);

        return $stmt->fetchAll();
    }

    private function getKeywords(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $engineCase = $this->searchEngineCase();
        $statement = $this->db->prepare(
            "SELECT keyword, {$engineCase} as engine, COALESCE(path, '/') as path, COUNT(*) as views
            FROM pageviews
            WHERE site_id = :site_id AND keyword IS NOT NULL AND keyword != '' AND is_bot = 0 {$rangeSql}
            GROUP BY keyword, engine, path
            ORDER BY views DESC
            LIMIT 500"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        $rows = $statement->fetchAll();
        $keywords = [];

        foreach ($rows as $row) {
            $keyword = $row['keyword'];
            if (!isset($keywords[$keyword])) {
                $keywords[$keyword] = [
                    'keyword' => $keyword,
                    'views' => 0,
                    'engines' => [],
                    'entry' => $row['path'],
                ];
            }

            $keywords[$keyword]['views'] += (int) $row['views'];
            $keywords[$keyword]['engines'][] = $row['engine'];

            if ($row['views'] >= $keywords[$keyword]['views']) {
                $keywords[$keyword]['entry'] = $row['path'];
            }
        }

        return array_values(array_map(function ($item) {
            $item['engines'] = implode(' / ', array_unique($item['engines']));
            return $item;
        }, $keywords));
    }

    private function getBotTraffic(int $siteId, string $range, ?string $engine = null): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $engineCase = $this->searchEngineCase();
        $engineFilter = '';

        if ($engine !== null && $engine !== '' && $engine !== 'all') {
            $engineFilter = " AND {$engineCase} = :engine";
            $params[':engine'] = $engine;
        }

        $statement = $this->db->prepare(
            "SELECT path, referrer, user_agent, occurred_at, {$engineCase} as engine
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 1 {$rangeSql}{$engineFilter}
            ORDER BY occurred_at DESC
            LIMIT 200"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getBotEngines(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $engineCase = $this->searchEngineCase();

        $statement = $this->db->prepare(
            "SELECT engine, COUNT(*) as total FROM (
                SELECT {$engineCase} as engine
                FROM pageviews
                WHERE site_id = :site_id AND is_bot = 1 {$rangeSql}
            ) t
            GROUP BY engine
            ORDER BY total DESC"
        );

        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getVisitAverages(int $siteId, string $range, array $rollupTotals = []): array
    {
        if (!empty($rollupTotals['has_data']) && ($rollupTotals['session_count'] ?? 0) > 0) {
            $sessions = max(1, (int) $rollupTotals['session_count']);

            return [
                'duration' => round(((float) ($rollupTotals['duration_sum'] ?? 0)) / $sessions, 2),
                'pages' => round(((float) ($rollupTotals['page_sum'] ?? 0)) / $sessions, 2),
            ];
        }

        [$rangeSql, $params] = $this->rangeClause($range);
        $avgDuration = $this->db->prepare(
            "SELECT AVG(duration_seconds) as avg_duration
            FROM (
                SELECT MAX(duration_seconds) as duration_seconds
                FROM pageviews
                WHERE site_id = :site_id AND is_bot = 0 AND session_id IS NOT NULL {$rangeSql}
                GROUP BY session_id
            ) t"
        );
        $avgDuration->execute(array_merge([':site_id' => $siteId], $params));
        $duration = $avgDuration->fetch()['avg_duration'] ?? 0;

        $avgPages = $this->db->prepare(
            "SELECT AVG(pages) as avg_pages
            FROM (
                SELECT MAX(page_count) as pages
                FROM pageviews
                WHERE site_id = :site_id AND is_bot = 0 AND session_id IS NOT NULL {$rangeSql}
                GROUP BY session_id
            ) t"
        );
        $avgPages->execute(array_merge([':site_id' => $siteId], $params));
        $pages = $avgPages->fetch()['avg_pages'] ?? 0;

        return [
            'duration' => round((float) $duration, 2),
            'pages' => round((float) $pages, 2),
        ];
    }

    private function getBounceRate(int $siteId, string $range, array $rollupTotals = []): float
    {
        if (!empty($rollupTotals['has_data']) && ($rollupTotals['session_count'] ?? 0) > 0) {
            $sessions = max(1, (int) $rollupTotals['session_count']);
            $bounces = (float) ($rollupTotals['bounce_count'] ?? 0);

            return $bounces / $sessions;
        }

        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT AVG(bounce) as rate FROM (
                SELECT CASE WHEN MAX(page_count) = 1 THEN 1 ELSE 0 END as bounce
                FROM pageviews
                WHERE site_id = :site_id AND is_bot = 0 AND session_id IS NOT NULL {$rangeSql}
                GROUP BY session_id
            ) t"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return (float) ($statement->fetch()['rate'] ?? 0.0);
    }

    private function getPredictions(int $siteId): array
    {
        $now = new DateTimeImmutable('now');
        $minuteBucket = (int) floor($now->getTimestamp() / 300); // 5 分钟粒度缓存
        $cacheKey = "predictions:{$siteId}:{$minuteBucket}";

        $cached = $this->redis->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }

        $todayStart = $now->setTime(0, 0, 0);
        $yesterdayStart = $todayStart->sub(new DateInterval('P1D'));
        $yesterdaySameTime = $yesterdayStart->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));

        $today = $this->getRangeStats($siteId, $todayStart, $now);
        $yesterdayFull = $this->getRangeStats($siteId, $yesterdayStart, $todayStart);
        $yesterdayPace = $this->getRangeStats($siteId, $yesterdayStart, $yesterdaySameTime);
        $averages = $this->getHistoricalAverages($siteId, 30);

        $predictions = [
            'views' => $this->projectDayMetric($today['views'], $yesterdayFull['views'], $yesterdayPace['views'], $averages['views']),
            'uniques' => $this->projectDayMetric($today['uniques'], $yesterdayFull['uniques'], $yesterdayPace['uniques'], $averages['uniques']),
            'ips' => $this->projectDayMetric($today['ips'], $yesterdayFull['ips'], $yesterdayPace['ips'], $averages['ips']),
        ];

        $this->redis->setex($cacheKey, 300, json_encode($predictions));

        return $predictions;
    }

    private function getHistoricalAverages(int $siteId, int $days): array
    {
        $statement = $this->db->prepare(
            'SELECT DATE(occurred_at) as day, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips, SUM(is_unique) as uniques
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 AND occurred_at < CURDATE()
            GROUP BY day
            ORDER BY day DESC
            LIMIT :limit'
        );
        $statement->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $days, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        if (empty($rows)) {
            return ['views' => 0, 'uniques' => 0, 'ips' => 0];
        }

        $denominator = max(count($rows), 1);

        return [
            'views' => (int) round(array_sum(array_column($rows, 'views')) / $denominator),
            'uniques' => (int) round(array_sum(array_column($rows, 'uniques')) / $denominator),
            'ips' => (int) round(array_sum(array_column($rows, 'ips')) / $denominator),
        ];
    }

    private function getRangeStats(int $siteId, DateTimeInterface $start, DateTimeInterface $end): array
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips, SUM(is_unique) as uniques
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 AND occurred_at >= :start AND occurred_at < :end'
        );
        $statement->execute([
            ':site_id' => $siteId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
        ]);

        $row = $statement->fetch();

        return [
            'views' => (int) ($row['views'] ?? 0),
            'ips' => (int) ($row['ips'] ?? 0),
            'uniques' => (int) ($row['uniques'] ?? 0),
        ];
    }

    private function projectDayMetric(int $today, int $yesterdayFull, int $yesterdayPartial, int $average): int
    {
        $baseline = $yesterdayFull > 0 ? $yesterdayFull : ($average > 0 ? $average : $today);
        $pace = $yesterdayPartial > 0 ? max(0.1, $today / $yesterdayPartial) : 1.0;
        $estimate = (int) round($baseline * $pace);

        return max($today, $estimate);
    }

    private function isBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        $ua = strtolower($userAgent);
        $bots = [
            'bot', 'spider', 'monitor', 'crawler', 'postman', 'curl/', 'bingpreview/', 'wget/',
            'windowspowershell/', 'python-', 'httpclient/', 'go-http-client/', 'libwww-perl',
            'feedburner/', 'headless', 'cloudflare', 'gocolly/', 'scrapy/', 'zgrab/',
            'phantomjs', 'axios', 'apachebench', 'wkhtmltopdf',
            'baiduspider', 'googlebot', 'bingbot', '360spider', 'bytespider', 'sogouspider', 'sogou web spider',
            'yisouspider'
        ];

        foreach ($bots as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isMobile(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        $ua = strtolower($userAgent);
        $needles = ['mobile', 'android', 'iphone', 'ipad', 'ipod', 'micromessenger', 'windows phone', 'harmony', 'huawei'];
        foreach ($needles as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function canonicalHost(?string $host): ?string
    {
        if (!$host) {
            return null;
        }

        $lower = strtolower($host);
        if (str_starts_with($lower, 'www.')) {
            return substr($lower, 4);
        }

        return $lower;
    }

    private function parseUrl(?string $url, ?string $fallbackDomain): array
    {
        $host = $fallbackDomain ? $this->canonicalHost($fallbackDomain) : null;
        $canonical = $host;
        $path = $url ?? '';

        if ($url && str_starts_with($url, 'http')) {
            $parsed = parse_url($url);
            if (!empty($parsed['host'])) {
                $host = strtolower($parsed['host']);
                $canonical = $this->canonicalHost($host);
            }

            $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
        }

        if ($host && !$canonical) {
            $canonical = $this->canonicalHost($host);
        }

        return [
            'host' => $host,
            'canonical' => $canonical,
            'path' => $path,
        ];
    }

    private function sanitizeIp(?string $ip): string
    {
        if ($ip === null) {
            return '';
        }

        $parts = preg_split('/\s*,\s*/', $ip) ?: [];
        foreach ($parts as $candidate) {
            $trimmed = trim($candidate);
            if ($trimmed === '') {
                continue;
            }

            if (filter_var($trimmed, FILTER_VALIDATE_IP)) {
                return $trimmed;
            }
        }

        $fallback = trim($parts[0] ?? '');

        return $fallback === '' ? '' : substr($fallback, 0, 45);
    }

    private function resolveIpMeta(?string $ip): array
    {
        if (!$ip) {
            return [];
        }

        $cacheKey = 'ipmeta:' . $ip;
        $cached = $this->redis->get($cacheKey);
        if ($cached) {
            $decoded = json_decode($cached, true);
            if ($this->isValidGeo($decoded)) {
                return $decoded;
            }
            // 无效缓存删除，避免污染
            $this->redis->del($cacheKey);
        }

        $meta = $this->ipResolver->resolve($ip);
        if (!$this->isValidGeo($meta)) {
            return [
                'country_name' => '未知',
                'region_name' => '未知',
                'city_name' => '',
                'isp_domain' => '未知运营商',
                'country_code' => '',
                'continent_code' => '',
            ];
        }

        $sanitized = [
            'country_name' => trim($meta['country_name'] ?? '') ?: '未知',
            'region_name' => trim($meta['region_name'] ?? '') ?: '未知',
            'city_name' => trim($meta['city_name'] ?? ''),
            'isp_domain' => trim($meta['isp_domain'] ?? '') ?: '未知运营商',
            'country_code' => trim($meta['country_code'] ?? ''),
            'continent_code' => trim($meta['continent_code'] ?? ''),
        ];

        $this->redis->setex($cacheKey, 604800, json_encode($sanitized, JSON_UNESCAPED_UNICODE));

        return $sanitized;
    }

    private function isValidGeo($meta): bool
    {
        if (!is_array($meta)) {
            return false;
        }

        $country = trim($meta['country_name'] ?? '');
        if ($country === '' || $country === '未知' || $country === '保留地址' || stripos($country, 'ip_version') !== false || stripos($country, 'node_count') !== false) {
            return false;
        }

        return true;
    }

    private function ensureSiteDomainSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS site_domains (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id INT UNSIGNED NOT NULL,
                domain VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_site_domain (site_id, domain),
                CONSTRAINT fk_site_domains_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );
    }

    private function ensurePageviewSchema(): void
    {
        $columnExists = function (string $column): bool {
            $query = $this->db->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pageviews' AND COLUMN_NAME = :column"
            );
            $query->execute([':column' => $column]);

            return (int) $query->fetchColumn() > 0;
        };

        $ensureColumn = function (string $column, string $definition, ?string $position = null) use ($columnExists) {
            if ($columnExists($column)) {
                return;
            }

            $posClause = $position ? " {$position}" : '';
            $this->db->exec("ALTER TABLE pageviews ADD COLUMN {$column} {$definition}{$posClause}");
        };

        $ensureIndex = function (string $index, string $definition) {
            $query = $this->db->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pageviews' AND INDEX_NAME = :index"
            );
            $query->execute([':index' => $index]);

            if ((int) $query->fetchColumn() === 0) {
                $this->db->exec("ALTER TABLE pageviews ADD INDEX {$index} ({$definition})");
            }
        };

        $ensureColumn('host', 'VARCHAR(255)', 'AFTER site_id');
        $ensureColumn('canonical_host', 'VARCHAR(255)', $columnExists('host') ? 'AFTER host' : 'AFTER site_id');
        $ensureColumn('session_id', 'VARCHAR(64)');
        $ensureColumn('duration_seconds', 'INT DEFAULT 0');
        $ensureColumn('page_count', 'INT DEFAULT 1');
        $ensureColumn('keyword', 'VARCHAR(255)');
        $ensureColumn('is_mobile', 'TINYINT(1) DEFAULT 0');
        $ensureColumn('is_bot', 'TINYINT(1) DEFAULT 0');
        $ensureColumn('is_unique', 'TINYINT(1) DEFAULT 0');
        $ensureColumn('occurred_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
        $ensureColumn('ip_address', 'VARCHAR(45)');
        $ensureColumn('country_name', 'VARCHAR(128)');
        $ensureColumn('region_name', 'VARCHAR(128)');
        $ensureColumn('city_name', 'VARCHAR(128)');
        $ensureColumn('isp_domain', 'VARCHAR(128)');
        $ensureColumn('country_code', 'VARCHAR(8)');
        $ensureColumn('continent_code', 'VARCHAR(8)');

        $ensureIndex('idx_site_host', 'site_id, canonical_host, occurred_at');
        $ensureIndex('idx_site_mobile', 'site_id, is_mobile, occurred_at');
        $ensureIndex('idx_site_ip', 'site_id, ip_hash, occurred_at');
        $ensureIndex('idx_site_ref', 'site_id, referrer(120), occurred_at');
        $ensureIndex('idx_site_country', 'site_id, country_name, occurred_at');
        $ensureIndex('idx_site_region', 'site_id, region_name, occurred_at');
        $ensureIndex('idx_site_isp', 'site_id, isp_domain, occurred_at');
    }

    private function ensureRollupSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS pageview_rollups (
                site_id INT UNSIGNED NOT NULL,
                bucket_start DATETIME NOT NULL,
                pv BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uv BIGINT UNSIGNED NOT NULL DEFAULT 0,
                ip_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                session_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                duration_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
                page_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
                bounce_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (site_id, bucket_start),
                INDEX idx_bucket_time (bucket_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );
    }

    private function ensureIpDbExists(): void
    {
        $dir = dirname($this->ipdbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        if (is_file($this->ipdbPath) && filesize($this->ipdbPath) > 0) {
            return;
        }

        $url = 'https://raw.githubusercontent.com/nmgliangwei/qqwry.ipdb/main/qqwry.ipdb';
        try {
            $data = @file_get_contents($url);
            if ($data !== false && strlen($data) > 1024) {
                @file_put_contents($this->ipdbPath, $data);
            }
        } catch (\Throwable $e) {
            // ignore download failure; resolver will fallback
        }
    }

    private function ensureShareSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS share_pages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                site_ids TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );
    }

    private function ensureSettingsSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(64) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );

        // 种子管理员账号
        $existingAdmin = $this->getSetting('admin');
        if (!$existingAdmin && !empty($this->adminDefaults['user'])) {
            $this->setSetting('admin', [
                'user' => $this->adminDefaults['user'],
                'pass_hash' => password_hash($this->adminDefaults['pass'] ?? 'admin123', PASSWORD_BCRYPT),
            ]);
        }

        // 种子数据保留策略
        if (!$this->getSetting('retention')) {
            $this->setSetting('retention', [
                'days' => (int) ($this->retentionDefaults['days'] ?? 0),
                'cleanup_hour' => (int) ($this->retentionDefaults['cleanup_hour'] ?? 3),
            ]);
        }
    }

    private function hydrateRetention(): void
    {
        $retention = $this->getRetentionSettings($this->retentionDefaults);
        $this->retentionDays = max(0, (int) ($retention['days'] ?? 0));
        $this->cleanupHour = min(23, max(0, (int) ($retention['cleanup_hour'] ?? 3)));
    }

    private function getMobileBreakdown(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT COALESCE(canonical_host, '未知域名') as domain, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips,
                SUM(is_mobile) as mobile_views, COUNT(DISTINCT IF(is_mobile = 1, ip_hash, NULL)) as mobile_ips
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}
            GROUP BY canonical_host
            ORDER BY views DESC
            LIMIT 100"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        $rows = $statement->fetchAll();
        $totals = [
            'domain' => '汇总',
            'views' => 0,
            'ips' => 0,
            'mobile_views' => 0,
            'mobile_ips' => 0,
        ];

        foreach ($rows as $row) {
            $totals['views'] += (int) $row['views'];
            $totals['ips'] += (int) $row['ips'];
            $totals['mobile_views'] += (int) $row['mobile_views'];
            $totals['mobile_ips'] += (int) $row['mobile_ips'];
        }

        return array_merge([$totals], $rows);
    }

    private function identifySearchEngine(?string $ua, ?string $referrer = null): string
    {
        $uaLower = strtolower($ua ?? '');
        $refHost = strtolower(parse_url($referrer ?? '', PHP_URL_HOST) ?? '');

        $spiderMap = [
            'baiduspider' => '百度蜘蛛',
            'googlebot' => '谷歌蜘蛛',
            'bingbot' => '必应蜘蛛',
            '360spider' => '360蜘蛛',
            'bytespider' => '头条蜘蛛',
            'sogouspider' => '搜狗蜘蛛',
            'sogou web spider' => '搜狗蜘蛛',
            'yisouspider' => '神马蜘蛛',
        ];

        foreach ($spiderMap as $needle => $label) {
            if ($needle !== '' && str_contains($uaLower, $needle)) {
                return $label;
            }
        }

        $searchMap = [
            'baidu.com' => '百度',
            'google' => '谷歌',
            'bing.com' => '必应',
            'so.com' => '360',
            'toutiao.com' => '头条',
            'sogou.com' => '搜狗',
            'sm.cn' => '神马',
            'quark.cn' => '夸克',
        ];

        foreach ($searchMap as $needle => $label) {
            if ($needle !== '' && str_contains($refHost, $needle)) {
                return $label;
            }
        }

        return '其他来源';
    }

    private function searchEngineCase(string $alias = ''): string
    {
        $prefix = $alias ? $alias . '.' : '';

        return "CASE
            WHEN LOWER(COALESCE({$prefix}referrer,'')) LIKE '%baidu.com%' OR LOWER(COALESCE({$prefix}user_agent,'')) LIKE '%baiduspider%' THEN '百度'
            WHEN LOWER(COALESCE({$prefix}referrer,'')) LIKE '%google%' OR LOWER(COALESCE({$prefix}user_agent,'')) LIKE '%googlebot%' THEN '谷歌'
            WHEN LOWER(COALESCE({$prefix}referrer,'')) LIKE '%bing.com%' OR LOWER(COALESCE({$prefix}user_agent,'')) LIKE '%bingbot%' THEN '必应'
            WHEN LOWER(COALESCE({$prefix}referrer,'')) LIKE '%so.com%' OR LOWER(COALESCE({$prefix}user_agent,'')) LIKE '%360spider%' THEN '360'
            WHEN LOWER(COALESCE({$prefix}referrer,'')) LIKE '%toutiao.com%' OR LOWER(COALESCE({$prefix}user_agent,'')) LIKE '%bytespider%' THEN '头条'
            WHEN LOWER(COALESCE({$prefix}referrer,'')) LIKE '%sogou.com%' OR LOWER(COALESCE({$prefix}user_agent,'')) LIKE '%sogouspider%' THEN '搜狗'
            WHEN LOWER(COALESCE({$prefix}referrer,'')) LIKE '%sm.cn%' OR LOWER(COALESCE({$prefix}user_agent,'')) LIKE '%yisouspider%' THEN '神马'
            WHEN LOWER(COALESCE({$prefix}referrer,'')) LIKE '%quark.cn%' THEN '夸克'
            ELSE '其他'
        END";
    }

    private function getSearchEngines(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $engineCase = $this->searchEngineCase();
        $statement = $this->db->prepare(
            "SELECT engine, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips
            FROM (
                SELECT {$engineCase} as engine, ip_hash
                FROM pageviews
                WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}
            ) t
            GROUP BY engine
            HAVING engine != '其他'
            ORDER BY ips DESC"
        );

        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getExternalLinks(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $site = $this->getSite($siteId);
        $domain = $site['domain'] ?? '';

        $statement = $this->db->prepare(
            "SELECT host, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips
            FROM (
                SELECT COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '/', 3), '//', -1), ''), '直接访问') as host, ip_hash
                FROM pageviews
                WHERE site_id = :site_id AND referrer IS NOT NULL AND referrer != '' AND is_bot = 0 {$rangeSql}
            ) t
            WHERE host != '直接访问'
            GROUP BY host
            ORDER BY ips DESC
            LIMIT 200"
        );

        $statement->execute(array_merge([':site_id' => $siteId], $params));
        $rows = $statement->fetchAll();

        $blocked = ['baidu', 'google', 'bing.', 'sm.cn', 'quark.cn', 'so.com', 'sogou', 'bytedance', 'toutiao'];
        $filtered = [];
        foreach ($rows as $row) {
            $host = strtolower($row['host'] ?? '');
            $skip = false;
            foreach ($blocked as $needle) {
                if (str_contains($host, $needle)) {
                    $skip = true;
                    break;
                }
            }
            if ($domain && (str_ends_with($host, $domain) || str_ends_with($host, 'www.' . ltrim($domain, '.')))) {
                $skip = true;
            }
            if (!$skip) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    private function getDeviceBreakdown(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT SUM(is_mobile = 0) as desktop_views, SUM(is_mobile = 1) as mobile_views,
                COUNT(DISTINCT IF(is_mobile = 0, ip_hash, NULL)) as desktop_ips,
                COUNT(DISTINCT IF(is_mobile = 1, ip_hash, NULL)) as mobile_ips
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        $row = $statement->fetch();

        return [
            'desktop' => [
                'views' => (int) ($row['desktop_views'] ?? 0),
                'ips' => (int) ($row['desktop_ips'] ?? 0),
            ],
            'mobile' => [
                'views' => (int) ($row['mobile_views'] ?? 0),
                'ips' => (int) ($row['mobile_ips'] ?? 0),
            ],
        ];
    }

    private function getBrowserBreakdown(int $siteId, string $range, int $limit = 10): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT browser, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips FROM (
                SELECT CASE
                    WHEN LOWER(user_agent) REGEXP 'micromessenger' THEN 'WeChat'
                    WHEN LOWER(user_agent) REGEXP 'bytedancewebview|aweme' THEN 'Douyin'
                    WHEN LOWER(user_agent) REGEXP 'baiduboxapp' THEN 'Baidu'
                    WHEN LOWER(user_agent) REGEXP 'mqqbrowser|qqbrowser' THEN 'QQ'
                    WHEN LOWER(user_agent) REGEXP 'ucbrowser' THEN 'UC'
                    WHEN LOWER(user_agent) REGEXP 'quark' THEN 'Quark'
                    WHEN LOWER(user_agent) REGEXP 'xiaomi|miuibrowser' THEN 'Mi'
                    WHEN LOWER(user_agent) REGEXP 'huawei' THEN 'Huawei'
                    WHEN LOWER(user_agent) REGEXP 'vivobrowser' THEN 'Vivo'
                    WHEN LOWER(user_agent) REGEXP 'heytapbrowser|oppobrowser' THEN 'OPPO'
                    WHEN LOWER(user_agent) REGEXP 'edg(a|ios)' THEN 'Edge'
                    WHEN LOWER(user_agent) REGEXP 'chrome|crios' THEN 'Chrome'
                    WHEN LOWER(user_agent) REGEXP 'firefox|fxios' THEN 'Firefox'
                    WHEN LOWER(user_agent) REGEXP 'safari' AND LOWER(user_agent) NOT REGEXP 'chrome|crios|edg' THEN 'Safari'
                    WHEN LOWER(user_agent) REGEXP '360se|360ee' THEN '360'
                    WHEN LOWER(user_agent) REGEXP 'msie|trident' THEN 'IE'
                    ELSE '其他浏览器'
                END as browser,
                ip_hash
                FROM pageviews
                WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}
            ) t
            GROUP BY browser
            ORDER BY views DESC
            LIMIT {$limit}"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getRegionStats(int $siteId, string $range, int $limit = 50): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT
                CASE
                    WHEN COALESCE(country_name,'') LIKE '中国%' THEN COALESCE(NULLIF(region_name,''), '未知')
                    WHEN COALESCE(country_name,'') = '' THEN '未知'
                    ELSE COALESCE(country_name, '未知')
                END as region,
                COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 {$rangeSql} AND (COALESCE(country_name,'') LIKE '中国%' OR COALESCE(country_name,'') = '')
            GROUP BY region
            ORDER BY ips DESC
            LIMIT {$limit}"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getCountryStats(int $siteId, string $range, int $limit = 200): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT
                COALESCE(NULLIF(country_name,''), '未知') as country,
                COALESCE(NULLIF(country_code,''), '') as country_code,
                COUNT(DISTINCT ip_hash) as ips
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}
            GROUP BY country, country_code
            ORDER BY ips DESC
            LIMIT {$limit}"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getIspStats(int $siteId, string $range, int $limit = 50): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT COALESCE(NULLIF(isp_domain,''), '未知运营商') as isp, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}
            GROUP BY isp
            ORDER BY ips DESC
            LIMIT {$limit}"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getNewVsReturning(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT 
                SUM(is_unique) as new_users,
                COUNT(*) - SUM(is_unique) as returning,
                COUNT(DISTINCT CASE WHEN is_unique = 1 THEN ip_hash END) as new_ips,
                COUNT(DISTINCT CASE WHEN is_unique = 0 THEN ip_hash END) as returning_ips
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));
        $row = $statement->fetch();

        return [
            'new' => (int) ($row['new_users'] ?? 0),
            'returning' => (int) ($row['returning'] ?? 0),
            'new_ips' => (int) ($row['new_ips'] ?? 0),
            'returning_ips' => (int) ($row['returning_ips'] ?? 0),
        ];
    }

    private function extractKeyword(?string $referrer): ?string
    {
        if (!$referrer) {
            return null;
        }

        $refererHost = strtolower(parse_url($referrer, PHP_URL_HOST) ?? '');
        $refererQuery = parse_url($referrer, PHP_URL_QUERY) ?? '';
        parse_str($refererQuery, $query);

        $allowedHosts = [
            'baidu.com', 'google', 'bing.com', 'so.com', 'toutiao.com', 'sogou.com', 'sm.cn', 'quark.cn'
        ];

        $matchedHost = null;
        foreach ($allowedHosts as $needle) {
            if ($needle !== '' && str_contains($refererHost, $needle)) {
                $matchedHost = $needle;
                break;
            }
        }

        if (!$matchedHost) {
            return null;
        }

        // ==================== 百度来源（加密 eqid 处理） ====================
        if (str_contains($refererHost, 'baidu.com')) {
            if (!empty($query['eqid'])) {
                require_once __DIR__ . '/baidukey_referer_eqid.php';
                $keyword = getBaiduKeywordByEqid($query['eqid']);
                if ($keyword !== '') {
                    return $keyword;
                }
            }

            if (!empty($query['wd'])) {
                return urldecode($query['wd']);
            }
        }

        // ==================== 夸克搜索（quark.cn），显式读取 q 参数 ====================
        if (str_contains($refererHost, 'quark.cn')) {
            if (!empty($query['q'])) {
                return urldecode($query['q']);
            }
        }

        // ==================== 谷歌/必应/360/头条/搜狗/神马/夸克 ====================
        $paramQ = !empty($query['q']) ? urldecode($query['q']) : null;
        $paramKeyword = !empty($query['keyword']) ? urldecode($query['keyword']) : null;
        $paramWord = !empty($query['word']) ? urldecode($query['word']) : null;

        if (str_contains($refererHost, 'google') || str_contains($refererHost, 'bing.com') || str_contains($refererHost, 'so.com') || str_contains($refererHost, 'sm.cn') || str_contains($refererHost, 'quark.cn')) {
            return $paramQ ?: $paramKeyword ?: $paramWord;
        }

        if (str_contains($refererHost, 'toutiao.com')) {
            return $paramKeyword ?: $paramQ ?: $paramWord;
        }

        if (str_contains($refererHost, 'sogou.com')) {
            return $paramKeyword ?: $paramQ ?: $paramWord;
        }

        return null;
    }

    private function getHourlyStats(int $siteId, string $range): array
    {
        [$start, $end] = $this->rollupRangeBounds($range);
        $rollup = $this->getRollupHourlyStats($siteId, $start, $end);
        if (!empty($rollup)) {
            return $rollup;
        }

        [$rangeSql, $params] = $this->rangeClause($range, false, true);
        $statement = $this->db->prepare(
            "SELECT DATE_FORMAT(occurred_at, '%Y-%m-%d %H:00:00') as hour,
                COUNT(*) as views,
                SUM(is_unique) as uniques,
                COUNT(DISTINCT ip_hash) as ips
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}
            GROUP BY hour
            ORDER BY hour ASC"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getHourlyStatsForWindow(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $rollup = $this->getRollupHourlyStats($siteId, $start, $end);
        if (!empty($rollup)) {
            return $rollup;
        }

        $statement = $this->db->prepare(
            "SELECT DATE_FORMAT(occurred_at, '%Y-%m-%d %H:00:00') as hour,
                COUNT(*) as views,
                SUM(is_unique) as uniques,
                COUNT(DISTINCT ip_hash) as ips
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 AND occurred_at >= :start AND occurred_at < :end
            GROUP BY hour
            ORDER BY hour ASC"
        );

        $statement->execute([
            ':site_id' => $siteId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
        ]);

        return $statement->fetchAll();
    }

    private function normalizeHourlySeries(array $rows, DateTimeImmutable $dayStart): array
    {
        $map = [];
        foreach ($rows as $row) {
            $key = (new DateTimeImmutable($row['hour']))->format('H:00');
            $map[$key] = [
                'views' => (int) $row['views'],
                'uniques' => (int) $row['uniques'],
                'ips' => (int) $row['ips'],
            ];
        }

        $labels = [];
        $series = ['views' => [], 'uniques' => [], 'ips' => []];

        for ($i = 0; $i < 24; $i++) {
            $label = $dayStart->modify("+{$i} hour")->format('H:00');
            $labels[] = $label;
            $series['views'][] = $map[$label]['views'] ?? 0;
            $series['uniques'][] = $map[$label]['uniques'] ?? 0;
            $series['ips'][] = $map[$label]['ips'] ?? 0;
        }

        return ['labels' => $labels, 'series' => $series];
    }

    private function normalizeDailySeries(int $siteId, string $range, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $rows = $this->getDailyStats($siteId, $range);
        $map = [];
        foreach ($rows as $row) {
            $map[$row['day']] = [
                'views' => (int) $row['views'],
                'uniques' => (int) $row['uniques'],
                'ips' => (int) $row['ip_count'],
            ];
        }

        $labels = [];
        $series = ['views' => [], 'uniques' => [], 'ips' => []];
        $period = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));

        foreach ($period as $date) {
            $day = $date->format('Y-m-d');
            $labels[] = $day;
            $series['views'][] = $map[$day]['views'] ?? 0;
            $series['uniques'][] = $map[$day]['uniques'] ?? 0;
            $series['ips'][] = $map[$day]['ips'] ?? 0;
        }

        return ['labels' => $labels, 'series' => $series];
    }

    public function rangeWindow(string $range): array
    {
        $now = new DateTimeImmutable('now');
        $ranges = [
            'today' => $now->setTime(0, 0),
            'yesterday' => $now->modify('-1 day')->setTime(0, 0),
            '7d' => $now->modify('-6 day')->setTime(0, 0),
            '30d' => $now->modify('-29 day')->setTime(0, 0),
        ];

        $start = $ranges[$range] ?? $ranges['today'];
        $end = ($range === 'yesterday') ? $now->setTime(0, 0) : $now;

        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ];
    }

    private function rangeClause(string $range, bool $forceLowerBound = false, bool $hourly = false): array
    {
        $params = [];
        $sql = '';
        $now = new DateTimeImmutable('now');

        $ranges = [
            'today' => $now->setTime(0, 0),
            'yesterday' => $now->modify('-1 day')->setTime(0, 0),
            '7d' => $now->modify('-6 day')->setTime(0, 0),
            '30d' => $now->modify('-29 day')->setTime(0, 0),
        ];

        $start = $ranges[$range] ?? $ranges['today'];

        if ($range === 'yesterday') {
            $end = $now->setTime(0, 0);
            $sql = ' AND occurred_at >= :start AND occurred_at < :end';
            $params[':start'] = $start->format('Y-m-d H:i:s');
            $params[':end'] = $end->format('Y-m-d H:i:s');
            return [$sql, $params];
        }

        if ($forceLowerBound || $hourly || $range !== 'all') {
            $sql = ' AND occurred_at >= :start';
            $params[':start'] = $start->format('Y-m-d H:i:s');
        }

        return [$sql, $params];
    }

    private function getSetting(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();

        return $value ? (json_decode($value, true) ?: null) : null;
    }

    private function setSetting(string $key, array $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:key, :value, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        $stmt->execute([
            ':key' => $key,
            ':value' => json_encode($value, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function getAdminAccount(array $fallback): array
    {
        $stored = $this->getSetting('admin');
        if ($stored && !empty($stored['user']) && !empty($stored['pass_hash'])) {
            return $stored;
        }

        $fallbackUser = $fallback['user'] ?? $this->adminDefaults['user'];
        $fallbackPass = $fallback['pass'] ?? $this->adminDefaults['pass'];
        $hash = password_hash($fallbackPass, PASSWORD_BCRYPT);

        $payload = ['user' => $fallbackUser, 'pass_hash' => $hash];
        $this->setSetting('admin', $payload);

        return $payload;
    }

    public function updateAdminAccount(string $user, string $password): array
    {
        $payload = [
            'user' => $user,
            'pass_hash' => password_hash($password, PASSWORD_BCRYPT),
        ];
        $this->setSetting('admin', $payload);

        return $payload;
    }

    public function verifyAdminCredentials(string $user, string $password, array $fallback): bool
    {
        $account = $this->getAdminAccount($fallback);
        if ($user !== ($account['user'] ?? '')) {
            return false;
        }

        if (!empty($account['pass_hash'])) {
            return password_verify($password, $account['pass_hash']);
        }

        return false;
    }

    public function getRetentionSettings(array $fallback): array
    {
        $stored = $this->getSetting('retention') ?? [];
        $merged = array_merge($fallback, $stored);
        $merged['days'] = max(0, (int) ($merged['days'] ?? 0));
        $merged['cleanup_hour'] = min(23, max(0, (int) ($merged['cleanup_hour'] ?? 3)));

        return $merged;
    }

    public function getBrandingSettings(array $fallback): array
    {
        $stored = $this->getSetting('branding') ?? [];
        $merged = array_merge($fallback, $stored);

        $merged['base_url'] = trim($merged['base_url'] ?? '');
        if ($merged['base_url'] === '') {
            $merged['base_url'] = 'http://localhost';
        }

        $merged['brand_title'] = trim($merged['brand_title'] ?? '');
        if ($merged['brand_title'] === '') {
            $merged['brand_title'] = 'V6统计后台';
        }

        $merged['brand_subtitle'] = trim($merged['brand_subtitle'] ?? '');
        if ($merged['brand_subtitle'] === '') {
            $merged['brand_subtitle'] = '亿级数据索引优化';
        }

        return $merged;
    }

    public function updateBrandingSettings(string $baseUrl, string $title, string $subtitle): array
    {
        $payload = [
            'base_url' => trim($baseUrl) ?: 'http://localhost',
            'brand_title' => trim($title) ?: 'V6统计后台',
            'brand_subtitle' => trim($subtitle) ?: '亿级数据索引优化',
        ];

        $this->setSetting('branding', $payload);

        return $payload;
    }

    public function getLoginEntry(): string
    {
        $stored = $this->getSetting('login_entry') ?? [];
        $entry = trim($stored['entry'] ?? '');

        if ($entry === '') {
            $entry = $this->defaultLoginEntry;
        }

        return $entry ?: 'admin';
    }

    public function updateLoginEntry(string $entry): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($entry));
        if ($sanitized === '') {
            $sanitized = $this->defaultLoginEntry ?: 'admin';
        }

        $this->setSetting('login_entry', ['entry' => $sanitized]);

        return $sanitized;
    }

    public function updateRetentionSettings(int $days, int $hour): array
    {
        $payload = [
            'days' => max(0, $days),
            'cleanup_hour' => min(23, max(0, $hour)),
        ];
        $this->setSetting('retention', $payload);
        $this->hydrateRetention();

        return $payload;
    }

    public function manualCleanup(int $days): void
    {
        if ($days <= 0) {
            return;
        }

        $stmt = $this->db->prepare('DELETE FROM pageviews WHERE occurred_at < DATE_SUB(NOW(), INTERVAL :days DAY)');
        $stmt->execute([':days' => $days]);
    }

    public function createSharePage(string $name, array $siteIds): array
    {
        $siteIds = array_values(array_unique(array_filter(array_map('intval', $siteIds))));
        if (empty($siteIds)) {
            throw new InvalidArgumentException('请选择至少一个域名');
        }

        $token = bin2hex(random_bytes(12));
        $statement = $this->db->prepare(
            'INSERT INTO share_pages (name, token, site_ids, created_at) VALUES (:name, :token, :site_ids, NOW())'
        );
        $statement->execute([
            ':name' => $name,
            ':token' => $token,
            ':site_ids' => json_encode($siteIds),
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'name' => $name,
            'token' => $token,
            'site_ids' => $siteIds,
        ];
    }

    public function getSharePages(): array
    {
        $query = $this->db->query('SELECT id, name, token, site_ids, created_at FROM share_pages ORDER BY created_at DESC');
        $pages = $query->fetchAll();

        $idList = [];
        foreach ($pages as &$page) {
            $page['site_ids'] = json_decode($page['site_ids'], true) ?? [];
            $idList = array_merge($idList, $page['site_ids']);
        }

        $siteNames = [];
        if (!empty($idList)) {
            $placeholders = implode(',', array_fill(0, count($idList), '?'));
            $stmt = $this->db->prepare("SELECT id, name FROM sites WHERE id IN ({$placeholders})");
            $stmt->execute($idList);
            foreach ($stmt->fetchAll() as $row) {
                $siteNames[(int) $row['id']] = $row['name'];
            }
        }

        foreach ($pages as &$page) {
            $page['site_names'] = array_values(array_filter(array_map(function ($id) use ($siteNames) {
                return $siteNames[(int) $id] ?? null;
            }, $page['site_ids'])));
        }

        return $pages;
    }

    public function deleteSharePage(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM share_pages WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function getShareByToken(string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT id, name, token, site_ids, created_at FROM share_pages WHERE token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
        $share = $stmt->fetch();
        if (!$share) {
            return null;
        }

        $share['site_ids'] = json_decode($share['site_ids'], true) ?? [];

        return $share;
    }

    public function getShareReport(string $token, string $range = 'today'): ?array
    {
        $share = $this->getShareByToken($token);
        if (!$share || empty($share['site_ids'])) {
            return null;
        }

        [$rangeSql, $params] = $this->rangeClause($range);
        $siteParams = [];
        $placeholders = [];
        foreach ($share['site_ids'] as $i => $sid) {
            $key = ':sid' . $i;
            $placeholders[] = $key;
            $siteParams[$key] = (int) $sid;
        }

        $statement = $this->db->prepare(
            "SELECT COALESCE(canonical_host, '未知域名') as domain, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips,
                SUM(is_mobile) as mobile_views, COUNT(DISTINCT IF(is_mobile = 1, ip_hash, NULL)) as mobile_ips
            FROM pageviews
            WHERE site_id IN (" . implode(',', $placeholders) . ") AND is_bot = 0 {$rangeSql}
            GROUP BY canonical_host
            ORDER BY views DESC"
        );

        $statement->execute(array_merge($siteParams, $params));
        $rows = $statement->fetchAll();

        $totals = [
            'domain' => '汇总',
            'views' => 0,
            'ips' => 0,
            'mobile_views' => 0,
            'mobile_ips' => 0,
        ];

        foreach ($rows as $row) {
            $totals['views'] += (int) $row['views'];
            $totals['ips'] += (int) $row['ips'];
            $totals['mobile_views'] += (int) $row['mobile_views'];
            $totals['mobile_ips'] += (int) $row['mobile_ips'];
        }

        return [
            'share' => $share,
            'rows' => array_merge([$totals], $rows),
        ];
    }

    public function deleteSite(int $siteId): void
    {
        $this->db->beginTransaction();
        try {
            $deleteStats = $this->db->prepare('DELETE FROM pageviews WHERE site_id = :id');
            $deleteStats->execute([':id' => $siteId]);

            $shares = $this->getSharePages();
            foreach ($shares as $share) {
                $siteIds = array_filter($share['site_ids'], fn($sid) => (int) $sid !== (int) $siteId);
                if (empty($siteIds)) {
                    $del = $this->db->prepare('DELETE FROM share_pages WHERE id = :id');
                    $del->execute([':id' => $share['id']]);
                } elseif (count($siteIds) !== count($share['site_ids'])) {
                    $upd = $this->db->prepare('UPDATE share_pages SET site_ids = :sites WHERE id = :id');
                    $upd->execute([':sites' => json_encode(array_values($siteIds)), ':id' => $share['id']]);
                }
            }

            $statement = $this->db->prepare('DELETE FROM sites WHERE id = :id');
            $statement->execute([':id' => $siteId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function maybeCleanupRetention(): void
    {
        if ($this->retentionDays <= 0) {
            return;
        }

        $key = 'retention:cleanup:' . date('Y-m-d');
        $hour = (int) date('G');
        if ($hour < $this->cleanupHour) {
            return;
        }

        if ($this->redis->setnx($key, '1')) {
            $this->redis->expire($key, 86400);
            $stmt = $this->db->prepare('DELETE FROM pageviews WHERE occurred_at < DATE_SUB(NOW(), INTERVAL :days DAY)');
            $stmt->execute([':days' => $this->retentionDays]);
        }
    }

    /**
     * 仅保留当前 SQL 中出现的命名参数，避免出现多余参数导致的 HY093 错误
     */
    private function filterParams(string $sql, array $params): array
    {
        preg_match_all('/:\\w+/', $sql, $matches);
        $allowed = array_unique($matches[0] ?? []);
        $filtered = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $params)) {
                $filtered[$key] = $params[$key];
            }
        }
        return $filtered;
    }
}
