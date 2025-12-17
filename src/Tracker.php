<?php

class Tracker
{
    private int $retentionDays = 0;
    private int $cleanupHour = 3;
    private array $adminDefaults = ['user' => 'admin', 'pass' => 'admin123'];
    private array $retentionDefaults = ['days' => 0, 'cleanup_hour' => 3];

    public function __construct(
        private PDO $db,
        private Redis $redis,
        private array $options = []
    ) {
        $this->adminDefaults = $this->options['app']['admin'] ?? $this->options['admin'] ?? $this->adminDefaults;
        $this->retentionDefaults = $this->options['retention'] ?? $this->retentionDefaults;
        $this->retentionDays = max(0, (int) ($this->retentionDefaults['days'] ?? 0));
        $this->cleanupHour = min(23, max(0, (int) ($this->retentionDefaults['cleanup_hour'] ?? 3)));

        $this->ensureSiteDomainSchema();
        $this->ensurePageviewSchema();
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
        $ip = $payload['ip'] ?? '';
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

        if ($ipHash) {
            $isUnique = (bool) $this->redis->sAdd($uniqueKey, $ipHash);
            $this->redis->expire($uniqueKey, 172800);
        }

        $statement = $this->db->prepare(
            'INSERT INTO pageviews (site_id, host, canonical_host, path, referrer, user_agent, ip_address, ip_hash, session_id, duration_seconds, page_count, keyword, is_mobile, is_bot, is_unique, occurred_at) VALUES
            (:site_id, :host, :canonical_host, :path, :referrer, :user_agent, :ip_address, :ip_hash, :session_id, :duration_seconds, :page_count, :keyword, :is_mobile, :is_bot, :is_unique, NOW())'
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
        ]);
    }

    public function getOverview(int $siteId, string $range = 'today'): array
    {
        return [
            'totals' => $this->getTotals($siteId, $range),
            'daily' => $this->getDailyStats($siteId, $range),
            'hourly' => $this->getHourlyStats($siteId, $range),
            'predictions' => $this->getPredictions($siteId),
            'regions' => $this->getRegionStats($siteId, $range, 10),
            'devices' => $this->getDeviceBreakdown($siteId, $range),
            'browsers' => $this->getBrowserBreakdown($siteId, $range, 6),
            'new_vs_returning' => $this->getNewVsReturning($siteId, $range),
            'top_referrers' => $this->getTopReferrers($siteId, $range),
            'top_pages' => $this->getTopPages($siteId, $range, 10),
            'entry_pages' => $this->getEntryPages($siteId, $range, 10),
        ];
    }

    public function getContentData(int $siteId, string $range = 'today'): array
    {
        return [
            'top_pages' => $this->getTopPages($siteId, $range),
            'top_referrers' => $this->getTopReferrers($siteId, $range),
            'recent' => $this->getRecentPageviews($siteId, $range),
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

    public function getBotData(int $siteId, string $range = 'today'): array
    {
        return [
            'bot' => $this->getBotTraffic($siteId, $range),
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
            'regions' => $this->getRegionStats($siteId, $range),
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

    public function getReferrerData(int $siteId, string $range = 'today'): array
    {
        return [
            'referrers' => $this->getTopReferrers($siteId, $range),
        ];
    }

    public function getEntryData(int $siteId, string $range = 'today'): array
    {
        return [
            'entries' => $this->getEntryPages($siteId, $range, 100),
        ];
    }

    public function getPageData(int $siteId, string $range = 'today'): array
    {
        return [
            'pages' => $this->getTopPages($siteId, $range, 100),
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

    private function getTopPages(int $siteId, string $range, int $limit = 50): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT path, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}
            GROUP BY path
            ORDER BY views DESC
            LIMIT {$limit}"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getTopReferrers(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT referrer, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips
            FROM pageviews
            WHERE site_id = :site_id AND referrer IS NOT NULL AND referrer != '' AND is_bot = 0 {$rangeSql}
            GROUP BY referrer
            ORDER BY views DESC
            LIMIT 50"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getEntryPages(int $siteId, string $range, int $limit = 20): array
    {
        [$rangeSql, $params] = $this->rangeClause($range, true);
        $statement = $this->db->prepare(
            "SELECT path, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips
            FROM (
                SELECT MIN(id) as first_id, session_id
                FROM pageviews
                WHERE site_id = :site_id AND is_bot = 0 AND session_id IS NOT NULL {$rangeSql}
                GROUP BY session_id
            ) s
            JOIN pageviews p ON p.id = s.first_id
            GROUP BY path
            ORDER BY views DESC
            LIMIT {$limit}"
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

    private function getBotTraffic(int $siteId, string $range): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT path, referrer, user_agent, occurred_at
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 1 {$rangeSql}
            ORDER BY occurred_at DESC
            LIMIT 200"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['engine'] = $this->identifySearchEngine($row['user_agent'], $row['referrer']);
        }

        return $rows;
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
            'bot', 'spider', 'monitor', 'crawler', 'postman', 'curl/', 'bingpreview/', 'wget/',
            'windowspowershell/', 'python-', 'httpclient/', 'go-http-client/', 'libwww-perl',
            'feedburner/', 'headless', 'cloudflare', 'gocolly/', 'scrapy/', 'zgrab/',
            'phantomjs', 'axios', 'apachebench', 'wkhtmltopdf'
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

        $ensureIndex('idx_site_host', 'site_id, canonical_host, occurred_at');
        $ensureIndex('idx_site_mobile', 'site_id, is_mobile, occurred_at');
        $ensureIndex('idx_site_ip', 'site_id, ip_hash, occurred_at');
        $ensureIndex('idx_site_ref', 'site_id, referrer(120), occurred_at');
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
        $haystack = strtolower(($ua ?? '') . ' ' . ($referrer ?? ''));

        $map = [
            '百度' => ['baidu', 'baiduspider'],
            'Google' => ['google', 'adsbot'],
            'Bing' => ['bing', 'bingbot'],
            '360搜索' => ['360spider', 'so.com'],
            '神马' => ['sm.cn', 'yisouspider'],
            '搜狗' => ['sogou', 'sosospider'],
            '头条' => ['toutiao', 'bytedance'],
        ];

        foreach ($map as $label => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, strtolower($needle))) {
                    return $label;
                }
            }
        }

        return '其他来源';
    }

    private function searchEngineCase(): string
    {
        return "CASE
            WHEN LOWER(COALESCE(referrer,'')) LIKE '%baidu%' OR LOWER(COALESCE(user_agent,'')) LIKE '%baiduspider%' THEN '百度'
            WHEN LOWER(COALESCE(referrer,'')) LIKE '%google.' OR LOWER(COALESCE(user_agent,'')) LIKE '%googlebot%' THEN 'Google'
            WHEN LOWER(COALESCE(referrer,'')) LIKE '%bing.' OR LOWER(COALESCE(user_agent,'')) LIKE '%bingbot%' THEN 'Bing'
            WHEN LOWER(COALESCE(referrer,'')) LIKE '%sm.cn%' OR LOWER(COALESCE(referrer,'')) LIKE '%quark.cn%' OR LOWER(COALESCE(user_agent,'')) LIKE '%yisouspider%' THEN '神马'
            WHEN LOWER(COALESCE(referrer,'')) LIKE '%so.com%' OR LOWER(COALESCE(user_agent,'')) LIKE '%360spider%' THEN '360搜索'
            WHEN LOWER(COALESCE(referrer,'')) LIKE '%sogou%' OR LOWER(COALESCE(user_agent,'')) LIKE '%sogou%' THEN '搜狗'
            WHEN LOWER(COALESCE(referrer,'')) LIKE '%toutiao%' OR LOWER(COALESCE(user_agent,'')) LIKE '%bytedance%' THEN '头条'
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
            ORDER BY views DESC"
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
            ORDER BY views DESC
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
                    WHEN user_agent LIKE '%Chrome%' AND user_agent NOT LIKE '%Edg%' THEN 'Chrome'
                    WHEN user_agent LIKE '%Edg%' THEN 'Edge'
                    WHEN user_agent LIKE '%Firefox%' THEN 'Firefox'
                    WHEN user_agent LIKE '%Safari%' AND user_agent NOT LIKE '%Chrome%' THEN 'Safari'
                    WHEN user_agent LIKE '%Opera%' OR user_agent LIKE '%OPR%' THEN 'Opera'
                    WHEN user_agent LIKE '%MSIE%' OR user_agent LIKE '%Trident%' THEN 'IE'
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
            "SELECT region, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips FROM (
                SELECT CASE
                    WHEN ip_address IS NULL OR ip_address = '' THEN '未知'
                    WHEN ip_address LIKE '10.%' OR ip_address LIKE '192.168.%' OR ip_address LIKE '172.1%.' THEN '内网'
                    WHEN ip_address LIKE '127.%' THEN '本地回环'
                    ELSE CONCAT('网段 ', SUBSTRING_INDEX(ip_address, '.', 2))
                END as region, ip_hash
                FROM pageviews
                WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}
            ) t
            GROUP BY region
            ORDER BY views DESC
            LIMIT {$limit}"
        );
        $statement->execute(array_merge([':site_id' => $siteId], $params));

        return $statement->fetchAll();
    }

    private function getIspStats(int $siteId, string $range, int $limit = 50): array
    {
        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT isp, COUNT(*) as views, COUNT(DISTINCT ip_hash) as ips FROM (
                SELECT CASE
                    WHEN ip_address LIKE '100.%' OR ip_address LIKE '39.%' THEN '中国移动(推测)'
                    WHEN ip_address LIKE '101.%' OR ip_address LIKE '36.%' THEN '中国电信(推测)'
                    WHEN ip_address LIKE '42.%' OR ip_address LIKE '58.%' THEN '中国联通(推测)'
                    WHEN ip_address LIKE '10.%' OR ip_address LIKE '192.168.%' THEN '内网'
                    ELSE '未知运营商'
                END as isp,
                ip_hash
                FROM pageviews
                WHERE site_id = :site_id AND is_bot = 0 {$rangeSql}
            ) t
            GROUP BY isp
            ORDER BY views DESC
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

        $refererHost = parse_url($referrer, PHP_URL_HOST) ?? '';
        $refererQuery = parse_url($referrer, PHP_URL_QUERY) ?? '';
        parse_str($refererQuery, $query);

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

        // ==================== 神马 / Quark ====================
        if (preg_match('/(\.sm\.cn|quark\.cn)$/i', $refererHost) || str_contains($refererHost, 'sm.cn') || str_contains($refererHost, 'quark.cn')) {
            if (!empty($query['q'])) {
                return urldecode($query['q']);
            }
        }

        // ==================== 360 搜索 ====================
        if (str_contains($refererHost, 'm.so.com')) {
            if (!empty($query['q'])) {
                return urldecode($query['q']);
            }
        }

        // ==================== Bing ====================
        if (str_contains($refererHost, 'cn.bing.com')) {
            if (!empty($query['q'])) {
                return urldecode($query['q']);
            }
        }

        // ==================== 搜狗移动 ====================
        if (str_contains($refererHost, 'm.sogou.com')) {
            if (!empty($query['keyword'])) {
                return urldecode($query['keyword']);
            }
        }

        // 兜底：常见搜索参数解析
        $fallbackParams = [
            'q', 'query', 'keyword', 'word', 'wd'
        ];

        foreach ($fallbackParams as $param) {
            if (!empty($query[$param])) {
                return urldecode($query[$param]);
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
            $merged['brand_title'] = '简约白 · 统计后台';
        }

        $merged['brand_subtitle'] = trim($merged['brand_subtitle'] ?? '');
        if ($merged['brand_subtitle'] === '') {
            $merged['brand_subtitle'] = '多站点切换 / www 自动兼容 / 亿级数据索引优化';
        }

        return $merged;
    }

    public function updateBrandingSettings(string $baseUrl, string $title, string $subtitle): array
    {
        $payload = [
            'base_url' => trim($baseUrl) ?: 'http://localhost',
            'brand_title' => trim($title) ?: '简约白 · 统计后台',
            'brand_subtitle' => trim($subtitle) ?: '多站点切换 / www 自动兼容 / 亿级数据索引优化',
        ];

        $this->setSetting('branding', $payload);

        return $payload;
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
}
