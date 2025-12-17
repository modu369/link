<?php

class Tracker
{
    public function __construct(
        private PDO $db,
        private Redis $redis
    ) {
    }

    public function createSite(string $name, string $domain): array
    {
        $trackingId = bin2hex(random_bytes(8));
        $statement = $this->db->prepare(
            'INSERT INTO sites (name, domain, tracking_id, created_at) VALUES (:name, :domain, :tracking_id, NOW())'
        );
        $statement->execute([
            ':name' => $name,
            ':domain' => $domain,
            ':tracking_id' => $trackingId,
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'name' => $name,
            'domain' => $domain,
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

        $ip = $payload['ip'] ?? '';
        $ipHash = $ip ? hash('sha256', $ip) : null;
        $uniqueKey = sprintf('unique:%s:%s', $site['id'], date('Y-m-d'));
        $isUnique = false;

        $sessionId = $payload['session_id'] ?? bin2hex(random_bytes(8));
        $duration = max(0, (int) ($payload['duration'] ?? 0));
        $pageCount = max(1, (int) ($payload['page_count'] ?? 1));
        $isBot = $this->isBot($payload['user_agent'] ?? '');
        $keyword = $this->extractKeyword($payload['referrer'] ?? '');

        if ($ipHash) {
            $isUnique = (bool) $this->redis->sAdd($uniqueKey, $ipHash);
            $this->redis->expire($uniqueKey, 172800);
        }

        $statement = $this->db->prepare(
            'INSERT INTO pageviews (site_id, path, referrer, user_agent, ip_hash, session_id, duration_seconds, page_count, keyword, is_bot, is_unique, occurred_at) VALUES
            (:site_id, :path, :referrer, :user_agent, :ip_hash, :session_id, :duration_seconds, :page_count, :keyword, :is_bot, :is_unique, NOW())'
        );
        $statement->execute([
            ':site_id' => $site['id'],
            ':path' => $payload['path'] ?? null,
            ':referrer' => $payload['referrer'] ?? null,
            ':user_agent' => $payload['user_agent'] ?? null,
            ':ip_hash' => $ipHash,
            ':session_id' => $sessionId,
            ':duration_seconds' => $duration,
            ':page_count' => $pageCount,
            ':keyword' => $keyword,
            ':is_bot' => $isBot ? 1 : 0,
            ':is_unique' => $isUnique ? 1 : 0,
        ]);
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

    private function getTotals(int $siteId): array
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) as views, SUM(is_unique) as uniques, COUNT(DISTINCT ip_hash) as ip_count
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0'
        );
        $statement->execute([':site_id' => $siteId]);

        $row = $statement->fetch();

        return [
            'views' => (int) ($row['views'] ?? 0),
            'uniques' => (int) ($row['uniques'] ?? 0),
            'ip_count' => (int) ($row['ip_count'] ?? 0),
            'averages' => $this->getVisitAverages($siteId),
            'bounce_rate' => $this->getBounceRate($siteId),
        ];
    }

    private function getDailyStats(int $siteId): array
    {
        $statement = $this->db->prepare(
            'SELECT DATE(occurred_at) as day, COUNT(*) as views, SUM(is_unique) as uniques, COUNT(DISTINCT ip_hash) as ip_count
            FROM pageviews
            WHERE site_id = :site_id AND occurred_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND is_bot = 0
            GROUP BY day
            ORDER BY day ASC'
        );
        $statement->execute([':site_id' => $siteId]);

        return $statement->fetchAll();
    }

    private function getTopPages(int $siteId): array
    {
        $statement = $this->db->prepare(
            'SELECT path, COUNT(*) as views
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0
            GROUP BY path
            ORDER BY views DESC
            LIMIT 10'
        );
        $statement->execute([':site_id' => $siteId]);

        return $statement->fetchAll();
    }

    private function getTopReferrers(int $siteId): array
    {
        $statement = $this->db->prepare(
            'SELECT referrer, COUNT(*) as views
            FROM pageviews
            WHERE site_id = :site_id AND referrer IS NOT NULL AND referrer != "" AND is_bot = 0
            GROUP BY referrer
            ORDER BY views DESC
            LIMIT 10'
        );
        $statement->execute([':site_id' => $siteId]);

        return $statement->fetchAll();
    }

    private function getRecentPageviews(int $siteId): array
    {
        $statement = $this->db->prepare(
            'SELECT path, referrer, user_agent, occurred_at
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 0
            ORDER BY occurred_at DESC
            LIMIT 20'
        );
        $statement->execute([':site_id' => $siteId]);

        return $statement->fetchAll();
    }

    private function getKeywords(int $siteId): array
    {
        $statement = $this->db->prepare(
            'SELECT keyword, COUNT(*) as views
            FROM pageviews
            WHERE site_id = :site_id AND keyword IS NOT NULL AND keyword != "" AND is_bot = 0
            GROUP BY keyword
            ORDER BY views DESC
            LIMIT 20'
        );
        $statement->execute([':site_id' => $siteId]);

        return $statement->fetchAll();
    }

    private function getBotTraffic(int $siteId): array
    {
        $statement = $this->db->prepare(
            'SELECT path, referrer, user_agent, occurred_at
            FROM pageviews
            WHERE site_id = :site_id AND is_bot = 1
            ORDER BY occurred_at DESC
            LIMIT 30'
        );
        $statement->execute([':site_id' => $siteId]);

        return $statement->fetchAll();
    }

    private function getVisitAverages(int $siteId): array
    {
        $avgDuration = $this->db->prepare(
            'SELECT AVG(duration_seconds) as avg_duration
            FROM (
                SELECT MAX(duration_seconds) as duration_seconds
                FROM pageviews
                WHERE site_id = :site_id AND is_bot = 0 AND session_id IS NOT NULL
                GROUP BY session_id
            ) t'
        );
        $avgDuration->execute([':site_id' => $siteId]);
        $duration = $avgDuration->fetch()['avg_duration'] ?? 0;

        $avgPages = $this->db->prepare(
            'SELECT AVG(pages) as avg_pages
            FROM (
                SELECT MAX(page_count) as pages
                FROM pageviews
                WHERE site_id = :site_id AND is_bot = 0 AND session_id IS NOT NULL
                GROUP BY session_id
            ) t'
        );
        $avgPages->execute([':site_id' => $siteId]);
        $pages = $avgPages->fetch()['avg_pages'] ?? 0;

        return [
            'duration' => round((float) $duration, 2),
            'pages' => round((float) $pages, 2),
        ];
    }

    private function getBounceRate(int $siteId): float
    {
        $statement = $this->db->prepare(
            'SELECT AVG(bounce) as rate FROM (
                SELECT CASE WHEN MAX(page_count) = 1 THEN 1 ELSE 0 END as bounce
                FROM pageviews
                WHERE site_id = :site_id AND is_bot = 0 AND session_id IS NOT NULL
                GROUP BY session_id
            ) t'
        );
        $statement->execute([':site_id' => $siteId]);

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
            LIMIT 14'
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

    public function deleteSite(int $siteId): void
    {
        $statement = $this->db->prepare('DELETE FROM sites WHERE id = :id');
        $statement->execute([':id' => $siteId]);
    }
}
