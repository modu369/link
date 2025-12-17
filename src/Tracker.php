<?php

class Tracker
{
    public function __construct(
        private PDO $db,
        private Redis $redis
    ) {
        $this->ensurePageviewSchema();
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

        return [
            'id' => (int) $this->db->lastInsertId(),
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
        $ip = $payload['ip'] ?? '';
        $ipHash = $ip ? hash('sha256', $ip) : null;
        $uniqueKey = sprintf('unique:%s:%s', $site['id'], date('Y-m-d'));
        $isUnique = false;

        $sessionId = $payload['session_id'] ?? bin2hex(random_bytes(8));
        $duration = max(0, (int) ($payload['duration'] ?? 0));
        $pageCount = max(1, (int) ($payload['page_count'] ?? 1));
        $userAgent = $payload['user_agent'] ?? '';
        $isBot = $this->isBot($userAgent);
        $isMobile = $this->isMobile($userAgent);
        $keyword = $this->extractKeyword($payload['referrer'] ?? '');

        if ($ipHash) {
            $isUnique = (bool) $this->redis->sAdd($uniqueKey, $ipHash);
            $this->redis->expire($uniqueKey, 172800);
        }

        $statement = $this->db->prepare(
            'INSERT INTO pageviews (site_id, host, canonical_host, path, referrer, user_agent, ip_hash, session_id, duration_seconds, page_count, keyword, is_mobile, is_bot, is_unique, occurred_at) VALUES
            (:site_id, :host, :canonical_host, :path, :referrer, :user_agent, :ip_hash, :session_id, :duration_seconds, :page_count, :keyword, :is_mobile, :is_bot, :is_unique, NOW())'
        );
        $statement->execute([
            ':site_id' => $site['id'],
            ':host' => $host,
            ':canonical_host' => $canonicalHost,
            ':path' => $path,
            ':referrer' => $payload['referrer'] ?? null,
            ':user_agent' => $userAgent,
            ':ip_hash' => $ipHash,
            ':session_id' => $sessionId,
            ':duration_seconds' => $duration,
            ':page_count' => $pageCount,
            ':keyword' => $keyword,
            ':is_mobile' => $isMobile ? 1 : 0,
            ':is_bot' => $isBot ? 1 : 0,
            ':is_unique' => $isUnique ? 1 : 0,
        ]);
    }

    public function getOverview(int $siteId, string $range = '7d'): array
    {
        return [
            'totals' => $this->getTotals($siteId, $range),
            'daily' => $this->getDailyStats($siteId, $range),
            'hourly' => $this->getHourlyStats($siteId, $range),
            'predictions' => $this->getPredictions($siteId),
        ];
    }

    public function getContentData(int $siteId, string $range = '7d'): array
    {
        return [
            'top_pages' => $this->getTopPages($siteId, $range),
            'top_referrers' => $this->getTopReferrers($siteId, $range),
            'recent' => $this->getRecentPageviews($siteId, $range),
        ];
    }

    public function getKeywordData(int $siteId, string $range = '7d'): array
    {
        return [
            'keywords' => $this->getKeywords($siteId, $range),
        ];
    }

    public function getBotData(int $siteId, string $range = '7d'): array
    {
        return [
            'bot' => $this->getBotTraffic($siteId, $range),
        ];
    }

    public function getMobileData(int $siteId, string $range = '7d'): array
    {
        return [
            'breakdown' => $this->getMobileBreakdown($siteId, $range),
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
            'averages' => $this->getVisitAverages($siteId, $range),
            'bounce_rate' => $this->getBounceRate($siteId, $range),
        ];
    }

    private function getDailyStats(int $siteId, string $range): array
    {
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

    private function getTopPages(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT path, COUNT(*) as views
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}
            GROUP BY path
            ORDER BY views DESC
            LIMIT 50"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getTopReferrers(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT referrer, COUNT(*) as views
            FROM pageviews
            WHERE site_id = :site_id AND referrer IS NOT NULL AND referrer != '' AND is_bot = 0 {$rangeSql}
            GROUP BY referrer
            ORDER BY views DESC
            LIMIT 50"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
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

    private function getKeywords(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT keyword, COUNT(*) as views
            FROM pageviews
            WHERE site_id = :site_id AND keyword IS NOT NULL AND keyword != '' AND is_bot = 0 {$rangeSql}
            GROUP BY keyword
            ORDER BY views DESC
            LIMIT 100"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getBotTraffic(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT path, referrer, user_agent, occurred_at
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 1 {$rangeSql}
            ORDER BY occurred_at DESC
            LIMIT 100"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getVisitAverages(int $siteId, string $range): array
    {
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

    private function getBounceRate(int $siteId, string $range): float
    {
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
        $statement = $this->db->prepare(
            'SELECT DATE(occurred_at) as day, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips, SUM(is_unique) as uniques
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 AND occurred_at < CURDATE()
            GROUP BY day
            ORDER BY day DESC
            LIMIT 30'
        );
        $statement->execute([':site_id' => $siteId]);
        $rows = $statement->fetchAll();

        if (empty($rows)) {
            return ['views' => 0, 'uniques' => 0, 'ips' => 0];
        }

        $views = array_column($rows, 'views');
        $uniques = array_column($rows, 'uniques');
        $ips = array_column($rows, 'ips');

        $denominator = max(count($rows), 1);

        return [
            'views' => (int) round(array_sum($views) / $denominator),
            'uniques' => (int) round(array_sum($uniques) / $denominator),
            'ips' => (int) round(array_sum($ips) / $denominator),
        ];
    }

    private function isBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        $bots = [
            'bot', 'spider', 'crawl', 'slurp', 'bingpreview', 'curl', 'python-requests',
            'mediapartners-google', 'ahrefs', 'mj12bot', 'semrush', 'yandex', 'sogou'
        ];

        $ua = strtolower($userAgent);
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

        $ensureIndex('idx_site_host', 'site_id, canonical_host, occurred_at');
        $ensureIndex('idx_site_mobile', 'site_id, is_mobile, occurred_at');
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

    private function extractKeyword(?string $referrer): ?string
    {
        if (!$referrer) {
            return null;
        }

        $parsed = parse_url($referrer);
        if (!isset($parsed['host'])) {
            return null;
        }

        parse_str($parsed['query'] ?? '', $params);

        $host = $parsed['host'];
        $keywordParams = [
            'baidu.com' => 'wd',
            'google.' => 'q',
            'bing.com' => 'q',
            'so.com' => 'q',
            'sogou.com' => 'query',
            'sm.cn' => 'q',
        ];

        foreach ($keywordParams as $domain => $param) {
            if (str_contains($host, $domain) && !empty($params[$param])) {
                return urldecode($params[$param]);
            }
        }

        return null;
    }

    private function getHourlyStats(int $siteId, string $range): array
    {
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

        $start = $ranges[$range] ?? $ranges['7d'];

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

    public function deleteSite(int $siteId): void
    {
        $statement = $this->db->prepare('DELETE FROM sites WHERE id = :id');
        $statement->execute([':id' => $siteId]);
    }
}
