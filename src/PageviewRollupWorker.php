<?php
require_once __DIR__ . '/IpResolver.php';

class PageviewRollupWorker
{
    private int $batchSize = 200;
    private int $windowMinutes = 1;
    private int $sleepSeconds = 1;
    private string $queueKey = 'pageview:rollup:queue';
    private string $ipdbPath = '';
    private IpResolver $ipResolver;

    public function __construct(
        private PDO $db,
        private Redis $redis,
        private array $options = []
    ) {
        $this->batchSize = max(1, (int) ($this->options['rollup']['batch_size'] ?? $this->batchSize));
        $this->windowMinutes = max(1, (int) ($this->options['rollup']['window_minutes'] ?? $this->windowMinutes));
        $this->sleepSeconds = max(1, (int) ($this->options['rollup']['sleep_seconds'] ?? $this->sleepSeconds));
        $this->queueKey = $this->options['rollup']['queue_key'] ?? $this->queueKey;
        $this->ipdbPath = $this->options['ipdb']['path'] ?? (__DIR__ . '/../data/qqwry.ipdb');
        $this->ipResolver = new IpResolver($this->ipdbPath);
    }

    public function run(): void
    {
        while (true) {
            $batch = $this->popBatch();
            if (empty($batch)) {
                sleep($this->sleepSeconds);
                continue;
            }

            $this->processBatch($batch);
        }
    }

    private function popBatch(): array
    {
        $items = [];
        for ($i = 0; $i < $this->batchSize; $i += 1) {
            $payload = $this->redis->rPop($this->queueKey);
            if ($payload === false || $payload === null) {
                break;
            }
            $decoded = json_decode($payload, true);
            if (!is_array($decoded) || empty($decoded['id'])) {
                continue;
            }
            $items[] = $decoded;
        }

        return $items;
    }

    private function processBatch(array $batch): void
    {
        $ids = array_values(array_unique(array_filter(array_map(static function ($item) {
            return isset($item['id']) ? (int) $item['id'] : null;
        }, $batch))));

        if (empty($ids)) {
            return;
        }

        $metadata = [];
        foreach ($batch as $item) {
            $id = isset($item['id']) ? (int) $item['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            $metadata[$id] = [
                'duration' => max(0, (int) ($item['duration'] ?? 0)),
                'page_count' => max(1, (int) ($item['page_count'] ?? 1)),
            ];
        }

        $this->hydratePageviews($ids, $metadata);

        $idList = implode(',', $ids);
        $windowStart = sprintf('DATE_SUB(NOW(), INTERVAL %d MINUTE)', $this->windowMinutes);

        $this->runPageviewRollups($idList, $windowStart);
        $this->runDimensionRollups($idList, $windowStart);
        $this->runPageRollups($idList, $windowStart);
        $this->runEntryRollups($idList, $windowStart);
    }

    private function hydratePageviews(array $ids, array $metadata): void
    {
        $idList = implode(',', $ids);
        $statement = $this->db->query(
            "SELECT id, site_id, ip_address, referrer, user_agent, occurred_at
            FROM pageviews
            WHERE id IN ({$idList})"
        );
        $rows = $statement->fetchAll();

        $update = $this->db->prepare(
            "UPDATE pageviews
            SET ip_hash = :ip_hash,
                session_id = COALESCE(NULLIF(session_id, ''), :session_id),
                duration_seconds = :duration_seconds,
                page_count = :page_count,
                keyword = :keyword,
                is_mobile = :is_mobile,
                is_bot = :is_bot,
                is_unique = :is_unique,
                country_name = :country_name,
                region_name = :region_name,
                city_name = :city_name,
                isp_domain = :isp_domain,
                country_code = :country_code,
                continent_code = :continent_code
            WHERE id = :id"
        );

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $ip = $row['ip_address'] ?? '';
            $ipHash = $ip ? hash('sha256', $ip) : null;
            $userAgent = $row['user_agent'] ?? '';
            $referrer = $row['referrer'] ?? '';
            $occurredAt = $row['occurred_at'] ?? null;
            $siteId = (int) ($row['site_id'] ?? 0);

            $meta = $metadata[$id] ?? ['duration' => 0, 'page_count' => 1];
            $duration = $meta['duration'];
            $pageCount = $meta['page_count'];

            $isBot = $this->isBot($userAgent);
            $isMobile = $this->isMobile($userAgent);
            $keyword = $this->extractKeyword($referrer);
            $ipMeta = $this->resolveIpMeta($ip);
            $isUnique = $this->recordUnique($siteId, $ipHash, $occurredAt);

            $update->execute([
                ':id' => $id,
                ':ip_hash' => $ipHash,
                ':session_id' => $ipHash ?: bin2hex(random_bytes(8)),
                ':duration_seconds' => $duration,
                ':page_count' => $pageCount,
                ':keyword' => $keyword,
                ':is_mobile' => $isMobile ? 1 : 0,
                ':is_bot' => $isBot ? 1 : 0,
                ':is_unique' => $isUnique ? 1 : 0,
                ':country_name' => $ipMeta['country_name'] ?? '未知',
                ':region_name' => $ipMeta['region_name'] ?? '未知',
                ':city_name' => $ipMeta['city_name'] ?? '',
                ':isp_domain' => $ipMeta['isp_domain'] ?? '未知运营商',
                ':country_code' => $ipMeta['country_code'] ?? '',
                ':continent_code' => $ipMeta['continent_code'] ?? '',
            ]);
        }
    }

    private function runPageviewRollups(string $idList, string $windowStart): void
    {
        $this->db->exec(
            "INSERT INTO pageview_rollups
                (site_id, bucket_start, views, uniques, ips, sessions, avg_duration, avg_pages, bounce_rate)
            SELECT
                p.site_id,
                DATE_FORMAT(p.occurred_at, '%Y-%m-%d %H:%i:00') as bucket_start,
                COUNT(*) as views,
                SUM(p.is_unique) as uniques,
                COUNT(DISTINCT p.ip_hash) as ips,
                COUNT(DISTINCT p.session_id) as sessions,
                AVG(p.duration_seconds) as avg_duration,
                AVG(p.page_count) as avg_pages,
                AVG(CASE WHEN p.page_count = 1 THEN 1 ELSE 0 END) as bounce_rate
            FROM pageviews p
            WHERE p.id IN ({$idList}) AND p.occurred_at >= {$windowStart} AND p.is_bot = 0
            GROUP BY p.site_id, bucket_start"
        );
    }

    private function runDimensionRollups(string $idList, string $windowStart): void
    {
        $engineCase = $this->searchEngineCase('p.');

        $this->db->exec(
            "INSERT INTO pageview_dimension_rollups
                (site_id, bucket_start, dimension, value, views, ips, uniques, sessions)
            SELECT p.site_id,
                DATE_FORMAT(p.occurred_at, '%Y-%m-%d %H:%i:00') as bucket_start,
                'keyword' as dimension,
                COALESCE(p.keyword, '') as value,
                COUNT(*) as views,
                COUNT(DISTINCT p.ip_hash) as ips,
                SUM(p.is_unique) as uniques,
                COUNT(DISTINCT p.session_id) as sessions
            FROM pageviews p
            WHERE p.id IN ({$idList}) AND p.occurred_at >= {$windowStart} AND p.is_bot = 0
            GROUP BY p.site_id, bucket_start, value"
        );

        $this->db->exec(
            "INSERT INTO pageview_dimension_rollups
                (site_id, bucket_start, dimension, value, views, ips, uniques, sessions)
            SELECT p.site_id,
                DATE_FORMAT(p.occurred_at, '%Y-%m-%d %H:%i:00') as bucket_start,
                'engine' as dimension,
                {$engineCase} as value,
                COUNT(*) as views,
                COUNT(DISTINCT p.ip_hash) as ips,
                SUM(p.is_unique) as uniques,
                COUNT(DISTINCT p.session_id) as sessions
            FROM pageviews p
            WHERE p.id IN ({$idList}) AND p.occurred_at >= {$windowStart} AND p.is_bot = 0
            GROUP BY p.site_id, bucket_start, value"
        );

        $this->db->exec(
            "INSERT INTO pageview_dimension_rollups
                (site_id, bucket_start, dimension, value, views, ips, uniques, sessions)
            SELECT p.site_id,
                DATE_FORMAT(p.occurred_at, '%Y-%m-%d %H:%i:00') as bucket_start,
                'referrer' as dimension,
                COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(p.referrer, '/', 3), '//', -1), ''), '直接访问') as value,
                COUNT(*) as views,
                COUNT(DISTINCT p.ip_hash) as ips,
                SUM(p.is_unique) as uniques,
                COUNT(DISTINCT p.session_id) as sessions
            FROM pageviews p
            WHERE p.id IN ({$idList}) AND p.occurred_at >= {$windowStart} AND p.is_bot = 0
            GROUP BY p.site_id, bucket_start, value"
        );

        $this->db->exec(
            "INSERT INTO pageview_dimension_rollups
                (site_id, bucket_start, dimension, value, views, ips, uniques, sessions)
            SELECT p.site_id,
                DATE_FORMAT(p.occurred_at, '%Y-%m-%d %H:%i:00') as bucket_start,
                'host' as dimension,
                COALESCE(p.canonical_host, '') as value,
                COUNT(*) as views,
                COUNT(DISTINCT p.ip_hash) as ips,
                SUM(p.is_unique) as uniques,
                COUNT(DISTINCT p.session_id) as sessions
            FROM pageviews p
            WHERE p.id IN ({$idList}) AND p.occurred_at >= {$windowStart} AND p.is_bot = 0
            GROUP BY p.site_id, bucket_start, value"
        );

        $this->db->exec(
            "INSERT INTO pageview_dimension_rollups
                (site_id, bucket_start, dimension, value, views, ips, uniques, sessions)
            SELECT p.site_id,
                DATE_FORMAT(p.occurred_at, '%Y-%m-%d %H:%i:00') as bucket_start,
                'device' as dimension,
                CASE WHEN p.is_mobile = 1 THEN 'mobile' ELSE 'desktop' END as value,
                COUNT(*) as views,
                COUNT(DISTINCT p.ip_hash) as ips,
                SUM(p.is_unique) as uniques,
                COUNT(DISTINCT p.session_id) as sessions
            FROM pageviews p
            WHERE p.id IN ({$idList}) AND p.occurred_at >= {$windowStart} AND p.is_bot = 0
            GROUP BY p.site_id, bucket_start, value"
        );

        $this->db->exec(
            "INSERT INTO pageview_dimension_rollups
                (site_id, bucket_start, dimension, value, views, ips, uniques, sessions)
            SELECT p.site_id,
                DATE_FORMAT(p.occurred_at, '%Y-%m-%d %H:%i:00') as bucket_start,
                'browser' as dimension,
                CASE
                    WHEN LOWER(p.user_agent) REGEXP 'micromessenger' THEN 'WeChat'
                    WHEN LOWER(p.user_agent) REGEXP 'bytedancewebview|aweme' THEN 'Douyin'
                    WHEN LOWER(p.user_agent) REGEXP 'baiduboxapp' THEN 'Baidu'
                    WHEN LOWER(p.user_agent) REGEXP 'edg(a|ios)' THEN 'Edge'
                    WHEN LOWER(p.user_agent) REGEXP 'chrome|crios' THEN 'Chrome'
                    WHEN LOWER(p.user_agent) REGEXP 'firefox|fxios' THEN 'Firefox'
                    WHEN LOWER(p.user_agent) REGEXP 'safari' AND LOWER(p.user_agent) NOT REGEXP 'chrome|crios|edg' THEN 'Safari'
                    WHEN LOWER(p.user_agent) REGEXP 'qqbrowser' THEN 'QQ'
                    WHEN LOWER(p.user_agent) REGEXP 'ucbrowser' THEN 'UC'
                    WHEN LOWER(p.user_agent) REGEXP 'quark' THEN 'Quark'
                    WHEN LOWER(p.user_agent) REGEXP 'miuibrowser' THEN 'Mi'
                    WHEN LOWER(p.user_agent) REGEXP 'huaweibrowser' THEN 'Huawei'
                    WHEN LOWER(p.user_agent) REGEXP 'vivobrowser' THEN 'Vivo'
                    WHEN LOWER(p.user_agent) REGEXP 'heytapbrowser' THEN 'OPPO'
                    WHEN LOWER(p.user_agent) REGEXP '360se|360ee' THEN '360'
                    WHEN LOWER(p.user_agent) REGEXP 'msie|trident' THEN 'IE'
                    ELSE '其他浏览器'
                END as value,
                COUNT(*) as views,
                COUNT(DISTINCT p.ip_hash) as ips,
                SUM(p.is_unique) as uniques,
                COUNT(DISTINCT p.session_id) as sessions
            FROM pageviews p
            WHERE p.id IN ({$idList}) AND p.occurred_at >= {$windowStart} AND p.is_bot = 0
            GROUP BY p.site_id, bucket_start, value"
        );

        $this->db->exec(
            "INSERT INTO pageview_dimension_rollups
                (site_id, bucket_start, dimension, value, views, ips, uniques, sessions)
            SELECT p.site_id,
                DATE_FORMAT(p.occurred_at, '%Y-%m-%d %H:%i:00') as bucket_start,
                'region' as dimension,
                CASE
                    WHEN COALESCE(p.country_name,'') LIKE '中国%' THEN COALESCE(NULLIF(p.region_name,''), '未知')
                    WHEN COALESCE(p.country_name,'') = '' THEN '未知'
                    ELSE COALESCE(p.country_name, '未知')
                END as value,
                COUNT(*) as views,
                COUNT(DISTINCT p.ip_hash) as ips,
                SUM(p.is_unique) as uniques,
                COUNT(DISTINCT p.session_id) as sessions
            FROM pageviews p
            WHERE p.id IN ({$idList}) AND p.occurred_at >= {$windowStart} AND p.is_bot = 0
            GROUP BY p.site_id, bucket_start, value"
        );

        $this->db->exec(
            "INSERT INTO pageview_dimension_rollups
                (site_id, bucket_start, dimension, value, views, ips, uniques, sessions)
            SELECT p.site_id,
                DATE_FORMAT(p.occurred_at, '%Y-%m-%d %H:%i:00') as bucket_start,
                'isp' as dimension,
                COALESCE(NULLIF(p.isp_domain,''), '未知运营商') as value,
                COUNT(*) as views,
                COUNT(DISTINCT p.ip_hash) as ips,
                SUM(p.is_unique) as uniques,
                COUNT(DISTINCT p.session_id) as sessions
            FROM pageviews p
            WHERE p.id IN ({$idList}) AND p.occurred_at >= {$windowStart} AND p.is_bot = 0
            GROUP BY p.site_id, bucket_start, value"
        );

        $this->db->exec(
            "INSERT INTO pageview_dimension_rollups
                (site_id, bucket_start, dimension, value, views, ips, uniques, sessions)
            SELECT p.site_id,
                DATE_FORMAT(p.occurred_at, '%Y-%m-%d %H:%i:00') as bucket_start,
                'audience' as dimension,
                CASE WHEN p.is_unique = 1 THEN 'new' ELSE 'return' END as value,
                COUNT(*) as views,
                COUNT(DISTINCT p.ip_hash) as ips,
                SUM(p.is_unique) as uniques,
                COUNT(DISTINCT p.session_id) as sessions
            FROM pageviews p
            WHERE p.id IN ({$idList}) AND p.occurred_at >= {$windowStart} AND p.is_bot = 0
            GROUP BY p.site_id, bucket_start, value"
        );

        $this->db->exec(
            "INSERT INTO pageview_dimension_rollups
                (site_id, bucket_start, dimension, value, views, ips, uniques, sessions)
            SELECT p.site_id,
                DATE_FORMAT(p.occurred_at, '%Y-%m-%d %H:%i:00') as bucket_start,
                'country' as dimension,
                COALESCE(NULLIF(p.country_name,''), '未知') as value,
                COUNT(*) as views,
                COUNT(DISTINCT p.ip_hash) as ips,
                SUM(p.is_unique) as uniques,
                COUNT(DISTINCT p.session_id) as sessions
            FROM pageviews p
            WHERE p.id IN ({$idList}) AND p.occurred_at >= {$windowStart} AND p.is_bot = 0
            GROUP BY p.site_id, bucket_start, value"
        );
    }

    private function runPageRollups(string $idList, string $windowStart): void
    {
        $this->db->exec(
            "INSERT INTO pageview_page_rollups
                (site_id, bucket_start, path, views, ips, uniques, sessions, avg_pages, avg_duration, bounce_rate)
            SELECT p.site_id,
                DATE_FORMAT(p.occurred_at, '%Y-%m-%d %H:%i:00') as bucket_start,
                COALESCE(p.path, '/') as path,
                COUNT(*) as views,
                COUNT(DISTINCT p.ip_hash) as ips,
                SUM(p.is_unique) as uniques,
                COUNT(DISTINCT p.session_id) as sessions,
                AVG(p.page_count) as avg_pages,
                AVG(p.duration_seconds) as avg_duration,
                AVG(CASE WHEN p.page_count = 1 THEN 1 ELSE 0 END) as bounce_rate
            FROM pageviews p
            WHERE p.id IN ({$idList}) AND p.occurred_at >= {$windowStart} AND p.is_bot = 0
            GROUP BY p.site_id, bucket_start, path"
        );
    }

    private function runEntryRollups(string $idList, string $windowStart): void
    {
        $base = "FROM (
                SELECT MIN(id) as first_id, session_id
                FROM pageviews
                WHERE id IN ({$idList}) AND occurred_at >= {$windowStart} AND is_bot = 0 AND session_id IS NOT NULL
                GROUP BY session_id
            ) s
            JOIN pageviews p ON p.id = s.first_id";

        $this->db->exec(
            "INSERT INTO pageview_entry_rollups
                (site_id, bucket_start, path, sessions, ips, uniques, views, avg_pages, avg_duration, bounce_rate)
            SELECT p.site_id,
                DATE_FORMAT(p.occurred_at, '%Y-%m-%d %H:%i:00') as bucket_start,
                COALESCE(p.path, '/') as path,
                COUNT(*) as sessions,
                COUNT(DISTINCT p.ip_hash) as ips,
                SUM(p.is_unique) as uniques,
                SUM(p.page_count) as views,
                AVG(p.page_count) as avg_pages,
                AVG(p.duration_seconds) as avg_duration,
                AVG(CASE WHEN p.page_count = 1 THEN 1 ELSE 0 END) as bounce_rate
            {$base}
            GROUP BY p.site_id, bucket_start, path"
        );
    }

    private function searchEngineCase(string $prefix = ''): string
    {
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

    private function recordUnique(int $siteId, ?string $ipHash, ?string $occurredAt): bool
    {
        if (!$ipHash || $siteId <= 0) {
            return false;
        }

        $timestamp = $occurredAt ? strtotime($occurredAt) : time();
        $date = date('Y-m-d', $timestamp);
        $uniqueKey = sprintf('unique:%s:%s', $siteId, $date);
        $isUnique = (bool) $this->redis->sAdd($uniqueKey, $ipHash);
        $this->redis->expire($uniqueKey, 172800);

        return $isUnique;
    }

    private function isBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        $botPatterns = [
            'bot',
            'spider',
            'crawl',
            'slurp',
            'baiduspider',
            'yandex',
            'bingbot',
            'sogou',
            'duckduckbot',
            'googlebot',
            'bingpreview',
            'baiduspider',
            'bytespider',
            '360spider',
            'sosospider',
            'yisouspider',
            'sogou web spider',
            'qihoobot',
            'sogou inst spider',
            'sogou blog spider',
            'sogou news spider',
            'sogou spider2',
        ];

        $ua = strtolower($userAgent);
        foreach ($botPatterns as $pattern) {
            if (str_contains($ua, $pattern)) {
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

        $pattern = '/mobile|android|iphone|ipad|phone|ipod|blackberry|iemobile|opera mini|opera mobi|windows phone|kindle/i';

        return (bool) preg_match($pattern, $userAgent);
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

        if (str_contains($refererHost, 'quark.cn')) {
            if (!empty($query['q'])) {
                return urldecode($query['q']);
            }
        }

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
}
