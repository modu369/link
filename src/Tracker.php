<?php
require_once __DIR__ . '/IpResolver.php';
require_once __DIR__ . '/AsnResolver.php';

class Tracker
{
    private int $retentionDays = 0;
    private int $pageviewRetentionDays = 0;
    private int $cleanupHour = 3;
    private array $adminDefaults = ['user' => 'admin', 'pass' => 'admin123'];
    private array $retentionDefaults = ['days' => 0, 'pageviews_days' => 0, 'cleanup_hour' => 3];
    private string $defaultLoginEntry = 'admin';
    private string $ipdbPath = '';
    private string $ipdbUrl = '';
    private int $ipdbRefreshHours = 168;
    private IpResolver $ipResolver;
    private string $asnDbPath = '';
    private string $asnDbPathV4 = '';
    private string $asnDbPathV6 = '';
    private string $asnDbUrl = '';
    private string $asnDbUrlV4 = '';
    private string $asnDbUrlV6 = '';
    private int $asnDbRefreshHours = 168;
    private bool $asnEnabled = true;
    private AsnResolver $asnResolver;
    private int $asnNextRefreshAt = 0;
    private bool $hasIpHashColumn = false;
    private bool $hasGeoColumns = false;
    private bool $rollupOnly = true;
    private string $ingestMode = 'direct';
    private string $ingestQueueKey = 'tracker:ingest:pageviews';
    private string $ingestProcessingKey = 'tracker:ingest:pageviews:processing';
    private int $ingestMaxQueueLength = 100000;
    private string $botQueueKey = 'tracker:ingest:bot_logs';
    private string $botProcessingKey = 'tracker:ingest:bot_logs:processing';
    private int $botMaxQueueLength = 50000;
    private string $blockedDomainQueueKey = 'tracker:ingest:blocked_domains';
    private string $blockedDomainProcessingKey = 'tracker:ingest:blocked_domains:processing';
    private int $blockedDomainMaxQueueLength = 50000;
    private bool $ingestAutoDrain = true;
    private int $ingestAutoDrainEvery = 20;
    private int $ingestAutoDrainBatch = 50;
    private int $ingestStalledAfter = 300;
    private array $ingestFilterDefaults = ['ip_filters' => '', 'keyword_filters' => '', 'asn_filters' => '', 'ua_filters' => ''];
    private array $ingestFilters = ['ip_filters' => '', 'keyword_filters' => '', 'asn_filters' => '', 'ua_filters' => ''];
    private int $proxyCleanupInterval = 600;
    private int $proxyCleanupBatch = 10;
    private int $proxyRecoverySeconds = 3600;
    private int $proxyProbationSeconds = 3600;
    private int $proxyRiskHoldSeconds = 3600;
    private array $rollupCoverageCache = [];
    private array $rollupSpanCache = [];
    private bool $forceRefresh = false;
    private int $cacheTtl = 3600;
    
    public function __construct(
        private PDO $db,
        private Redis $redis,
        private array $options = []
    ) {
        $this->adminDefaults = $this->options['app']['admin'] ?? $this->options['admin'] ?? $this->adminDefaults;
        $this->retentionDefaults = $this->options['retention'] ?? $this->retentionDefaults;
        $this->defaultLoginEntry = trim($this->options['security']['login_entry'] ?? $this->defaultLoginEntry) ?: $this->defaultLoginEntry;
        $this->retentionDays = max(0, (int) ($this->retentionDefaults['days'] ?? 0));
        $this->pageviewRetentionDays = max(0, (int) ($this->retentionDefaults['pageviews_days'] ?? 0));
        $this->cleanupHour = min(23, max(0, (int) ($this->retentionDefaults['cleanup_hour'] ?? 3)));
        $this->rollupOnly = (bool) ($this->options['rollup_only'] ?? $this->rollupOnly);
        $this->ipdbPath = $this->options['ipdb']['path'] ?? (__DIR__ . '/../data/qqwry.ipdb');
        $this->ipdbUrl = trim((string) ($this->options['ipdb']['url'] ?? 'https://raw.githubusercontent.com/nmgliangwei/qqwry.ipdb/main/qqwry.ipdb'));
        $this->ipdbRefreshHours = max(1, (int) ($this->options['ipdb']['refresh_hours'] ?? $this->ipdbRefreshHours));
        $asn = $this->options['asn'] ?? [];
        $this->asnEnabled = (bool) ($asn['enabled'] ?? true);
        $this->asnDbPath = $asn['path'] ?? (__DIR__ . '/../data/asn.ipdb');
        $this->asnDbPathV4 = $asn['path_v4'] ?? $this->asnDbPath;
        $this->asnDbPathV6 = $asn['path_v6'] ?? ($asn['path_v4'] ?? '');
        $this->asnDbUrl = trim((string) ($asn['url'] ?? ''));
        $this->asnDbUrlV4 = trim((string) ($asn['url_v4'] ?? $this->asnDbUrl));
        $this->asnDbUrlV6 = trim((string) ($asn['url_v6'] ?? ''));
        $this->asnDbRefreshHours = max(1, (int) ($asn['refresh_hours'] ?? $this->asnDbRefreshHours));
        $ingest = $this->options['ingest'] ?? [];
        $this->ingestMode = strtolower($ingest['mode'] ?? $this->ingestMode);
        $this->ingestQueueKey = $ingest['queue_key'] ?? $this->ingestQueueKey;
        $this->ingestProcessingKey = $ingest['processing_key'] ?? $this->ingestProcessingKey;
        $this->ingestMaxQueueLength = max(0, (int) ($ingest['max_queue_length'] ?? $this->ingestMaxQueueLength));
        $this->botQueueKey = $ingest['bot_queue_key'] ?? $this->botQueueKey;
        $this->botProcessingKey = $ingest['bot_processing_key'] ?? $this->botProcessingKey;
        $this->botMaxQueueLength = max(0, (int) ($ingest['bot_max_queue_length'] ?? $this->botMaxQueueLength));
        $this->blockedDomainQueueKey = $ingest['blocked_domain_queue_key'] ?? $this->blockedDomainQueueKey;
        $this->blockedDomainProcessingKey = $ingest['blocked_domain_processing_key'] ?? $this->blockedDomainProcessingKey;
        $this->blockedDomainMaxQueueLength = max(0, (int) ($ingest['blocked_domain_max_queue_length'] ?? $this->blockedDomainMaxQueueLength));
        $this->ingestAutoDrain = (bool) ($ingest['auto_drain'] ?? $this->ingestAutoDrain);
        $this->ingestAutoDrainEvery = max(1, (int) ($ingest['auto_drain_every'] ?? $this->ingestAutoDrainEvery));
        $this->ingestAutoDrainBatch = max(1, (int) ($ingest['auto_drain_batch'] ?? $this->ingestAutoDrainBatch));
        $this->ingestStalledAfter = max(30, (int) ($ingest['stalled_after'] ?? $this->ingestStalledAfter));
        $this->ensureIpDbExists();
        if ($this->asnEnabled) {
            $this->ensureAsnDbExists();
        }
        $this->ipResolver = new IpResolver($this->ipdbPath);
        $this->asnResolver = $this->asnEnabled
            ? new AsnResolver($this->asnDbPath, $this->asnDbPathV4, $this->asnDbPathV6)
            : new AsnResolver();

        $this->ensureSiteDomainSchema();
        $this->ensureBlockedDomainSchema();
        $this->ensurePageviewSchema();
        $this->ensureRollupSchema();
        $this->ensureShareSchema();
        $this->ensureSettingsSchema();
        $this->hydrateRetention();
        $this->hydrateIngestFilters();
        $this->maybeCleanupRetention();
        $this->maybeCleanupBlockedDomains();
        $this->maybeCleanupProxyHistory();
    }
public function setCacheTtl(int $ttlSeconds): void
    {
        $this->cacheTtl = max(60, $ttlSeconds);
    }
public function warmupDashboardCache(int $siteId): void
    {
        $this->forceRefresh = true;
        $this->rollupCoverageCache = [];
        $this->rollupSpanCache = [];
        try {
            // 补上 day_before
            $ranges = ['today', 'yesterday', 'day_before', '7d'];
            foreach ($ranges as $range) {
                $this->getOverview($siteId, $range);
                $this->getTrendData($siteId, $range);
                $this->getSearchEngineData($siteId, $range);
                $this->getKeywordData($siteId, $range);
                $this->getExternalLinkData($siteId, $range);
                $this->getMobileData($siteId, $range);
                $this->getVisitorEnv($siteId, $range);
                $this->getRegionData($siteId, $range);
                $this->getIspData($siteId, $range);
                $this->getAudienceData($siteId, $range);
            }
        } finally {
            $this->forceRefresh = false;
        }
    }
public function warmupShareCache(int $workerIndex = 1, int $workerCount = 1): void
    {
        $this->forceRefresh = true;
        $this->rollupCoverageCache = [];
        $this->rollupSpanCache = [];
        try {
            // 获取所有创建的分享页
            $shares = $this->getSharePages();
            if (empty($shares)) {
                return;
            }

            $ranges = ['today', 'yesterday', 'day_before', '7d'];
            foreach ($shares as $share) {
                if (empty($share['token'])) {
                    continue;
                }
                
                // 👇 新增分片逻辑：根据分享页 ID 分配给对应的 Worker
                if ($workerCount > 1 && ((($share['id'] - 1) % $workerCount) !== ($workerIndex - 1))) {
                    continue;
                }

                foreach ($ranges as $range) {
                    // 主动调用，将结果刷入 Redis
                    $this->getShareReport($share['token'], $range);
                }
            }
        } finally {
            $this->forceRefresh = false;
        }
    }
private function cacheAggregate(string $key, int $ttlSeconds, callable $builder): ?array
{
    $todayStr = date('Y-m-d');

    // 【防串包核心】：如果是相对时间，追加当前真实日期
    if (str_contains($key, ':yesterday') || str_contains($key, ':day_before') || str_contains($key, ':7d') || str_contains($key, ':today')) {
        $key .= ':' . $todayStr;
    }

    $lockKey = "lock:{$key}";
    $hasLock = false;

    // 优先读缓存
    if (!$this->forceRefresh) {
        $cached = $this->redis->get($key);
        if ($cached !== false) {
            $decoded = json_decode($cached, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // 没命中缓存，尝试加锁
        if (!$this->redis->setnx($lockKey, '1')) {
            // 如果没拿到锁，说明有其他进程正在查库，阻塞等待 (最多等 45 秒)
            for ($i = 0; $i < 90; $i++) {
                usleep(500000); // 等待 0.5 秒
                $cached = $this->redis->get($key);
                if ($cached !== false) {
                    return json_decode($cached, true);
                }
            }
        } else {
            // 拿到锁，设置锁超时防止死锁
            $this->redis->expire($lockKey, 120);
            $hasLock = true;
        }
    }

    try {
        // 查库执行聚合
        $result = $builder();

        // 【动态 TTL 策略】
        if (str_contains($key, ':yesterday') || str_contains($key, ':day_before')) {
            $computedTtl = max($this->cacheTtl, strtotime('tomorrow') - time()); 
        } else {
            $computedTtl = $this->cacheTtl; 
        }

        $this->redis->setex($key, $computedTtl, json_encode($result));

        return $result;
    } finally {
        // 释放锁
        if ($hasLock) {
            $this->redis->del($lockKey);
        }
    }
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

        $exists = $this->db->prepare('SELECT 1 FROM site_domains WHERE site_id = :site_id AND domain = :domain LIMIT 1');
        $exists->execute([
            ':site_id' => $siteId,
            ':domain' => $normalized,
        ]);
        if ($exists->fetchColumn()) {
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

    public function getBlockedDomains(int $siteId, string $range = 'today'): array
    {
        $targetDate = $range === 'yesterday' ? 'DATE_SUB(CURDATE(), INTERVAL 1 DAY)' : 'CURDATE()';
        $statement = $this->db->prepare(
            "SELECT domain, pv FROM site_blocked_domains
             WHERE site_id = :site_id AND log_date = {$targetDate}
             ORDER BY pv DESC"
        );
        $statement->execute([':site_id' => $siteId]);

        return $statement->fetchAll();
    }

    private function recordBlockedDomain(int $siteId, string $domain): void
    {
        $normalized = $this->canonicalHost($domain);
        if ($normalized === '') {
            return;
        }

        $this->enqueueBlockedDomain([
            'site_id' => $siteId,
            'domain' => $normalized,
        ]);
    }

    private function enqueueBlockedDomain(array $payload): void
    {
        if (empty($payload['site_id']) || empty($payload['domain'])) {
            return;
        }

        if ($this->blockedDomainMaxQueueLength > 0) {
            try {
                $len = (int) $this->redis->lLen($this->blockedDomainQueueKey);
                if ($len >= $this->blockedDomainMaxQueueLength) {
                    return;
                }
            } catch (Throwable $e) {
                return;
            }
        }

        $payload['received_at'] = time();
        $this->redis->lPush($this->blockedDomainQueueKey, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function persistBlockedDomain(int $siteId, string $domain): void
    {
        if ($siteId <= 0 || $domain === '') {
            return;
        }

        $insert = $this->db->prepare(
            'INSERT INTO site_blocked_domains (site_id, domain, log_date, pv, updated_at)
             VALUES (:site_id, :domain, CURDATE(), 1, NOW())
             ON DUPLICATE KEY UPDATE pv = pv + 1, updated_at = NOW()'
        );
        $insert->execute([
            ':site_id' => $siteId,
            ':domain' => $domain,
        ]);
    }

    public function recordPageview(string $trackingId, array $payload): void
    {
        if ($this->ingestMode === 'queue') {
            $this->enqueuePageview($trackingId, $payload);
            $this->autoDrainQueue();

            return;
        }

        $this->processPageview($trackingId, $payload);
    }

    private function processPageview(string $trackingId, array $payload, ?int $receivedAt = null): void
    {
        $site = $this->getSiteByTrackingId($trackingId);
        if (!$site) {
            return;
        }
        $occurredAtStr = $receivedAt ? date('Y-m-d H:i:s', $receivedAt) : date('Y-m-d H:i:s');
        $parsedUrl = $this->parseUrl($payload['path'] ?? null, $site['domain'] ?? null);
        $path = $this->limitText($parsedUrl['path'] ?? '/', 2048, '/');
        $host = $this->limitText($parsedUrl['host'] ?? '', 255);
        $canonicalHost = $this->limitText($parsedUrl['canonical'] ?? '', 255);
        $allowedDomains = $this->getAllSiteDomains((int) $site['id']);

        $referrer = $this->limitText($payload['referrer'] ?? '', 2048);
        $referrerHost = $this->referrerHost($referrer);
        $observedHost = $canonicalHost ?: $this->canonicalHost($referrerHost);

        if (!empty($allowedDomains)) {
            if (!$observedHost || !in_array($observedHost, $allowedDomains, true)) {
                if ($observedHost) {
                    $this->recordBlockedDomain((int) $site['id'], $observedHost);
                }
                return;
            }
            // ensure stored canonical host reflects the validated domain
            $canonicalHost = $this->limitText($observedHost, 255, $canonicalHost);
        }
        $ip = $this->sanitizeIp($payload['ip'] ?? null);
        $ipHash = $ip ? hash('sha256', $ip) : null;
        $audienceLabel = 'returning';
        $uvToday = false;
        $isUnique = false;

        $rawSessionId = $this->limitText($payload['session_id'] ?? '', 64);
        $rawFingerprint = $this->limitText($payload['fingerprint'] ?? '', 128);
        $sessionId = $rawSessionId !== '' ? $rawSessionId : ($ipHash ?: bin2hex(random_bytes(8)));
        $fingerprint = $rawFingerprint;
        if ($fingerprint === '') {
            $fingerprint = $sessionId;
        }
        $uidProvided = $rawSessionId !== '' || $rawFingerprint !== '';
        $duration = max(0, (int) ($payload['duration'] ?? 0));
        $pageCount = max(1, (int) ($payload['page_count'] ?? 1));
        $userAgent = $this->limitText($payload['user_agent'] ?? '', 1024);
        $isBot = $this->isBot($userAgent);
        if (!empty($payload['spider_verified'])) {
            $isBot = true;
        }
        $isMobile = $this->isMobile($userAgent);
        $keyword = $this->limitText($this->extractKeyword($referrer) ?? '', 255);
        $keyword = str_replace('|', ' ', $keyword);
        $headerMeta = [
            'language' => $this->limitText($payload['language'] ?? '', 32),
            'showp' => $this->limitText($payload['showp'] ?? '', 32),
            'ntime' => $this->limitText($payload['ntime'] ?? '', 16),
            'accept_language' => $this->limitText($payload['accept_language'] ?? '', 128),
            'accept_encoding' => $this->limitText($payload['accept_encoding'] ?? '', 128),
            'sec_ch_ua' => $this->limitText($payload['sec_ch_ua'] ?? '', 256),
            'sec_ch_ua_mobile' => $this->limitText($payload['sec_ch_ua_mobile'] ?? '', 32),
            'sec_ch_ua_platform' => $this->limitText($payload['sec_ch_ua_platform'] ?? '', 64),
            'sec_fetch_site' => $this->limitText($payload['sec_fetch_site'] ?? '', 32),
            'sec_fetch_mode' => $this->limitText($payload['sec_fetch_mode'] ?? '', 32),
            'sec_fetch_dest' => $this->limitText($payload['sec_fetch_dest'] ?? '', 32),
            'referrer_host' => $this->limitText($referrerHost ?? '', 255),
        ];
        $fallbackUid = $this->buildFallbackUid($ip, $userAgent, $headerMeta);
        $proxyRisk = ['blocked' => false, 'risk' => false];
        if (!$isBot) {
            if ($this->shouldFilterIngest($ip, $keyword, $path, $referrer, $userAgent)) {
                return;
            }

            $geo = $ip ? $this->resolveIpMeta($ip) : [];
            $countryName = $this->limitText($geo['country_name'] ?? '', 128);
            $regionName = $this->limitText($geo['region_name'] ?? '', 128);
            $cityName = $this->limitText($geo['city_name'] ?? '', 128);
            $ispName = $this->limitText($geo['isp_domain'] ?? '', 128);
            $countryCode = $this->limitText($geo['country_code'] ?? '', 16);
            $asnMeta = $ip ? $this->resolveAsnMeta($ip) : [];

            $proxyRisk = $this->isProxySuspicious(
                $sessionId,
                $fingerprint,
                $uidProvided,
                $fallbackUid,
                $userAgent,
                $duration,
                $pageCount,
                $ip,
                $ipHash,
                $asnMeta,
                $cityName,
                $regionName,
                $countryName,
                $headerMeta,
                (bool) $isMobile,
                $canonicalHost ?: $host
            );
            if ($proxyRisk['blocked']) {
                return;
            }
        }

        if ($isBot) {
            $this->insertBotLog(
                (int) $site['id'],
                $path,
                $referrer,
                $userAgent,
                $ip,
                $canonicalHost ?: $host,
                $this->detectSearchEngine($referrer, $userAgent)
            );
            return;
        }

        $geo = $geo ?? ($ip ? $this->resolveIpMeta($ip) : []);
        $countryName = $countryName ?? $this->limitText($geo['country_name'] ?? '', 128);
        $regionName = $regionName ?? $this->limitText($geo['region_name'] ?? '', 128);
        $cityName = $cityName ?? $this->limitText($geo['city_name'] ?? '', 128);
        $ispName = $ispName ?? $this->limitText($geo['isp_domain'] ?? '', 128);
        $countryCode = $countryCode ?? $this->limitText($geo['country_code'] ?? '', 16);

        if ($ipHash) {
            [$uvToday, $audienceLabel] = $this->markIpAudienceState((int) $site['id'], $ipHash);
            $isUnique = $uvToday;
        }

        $statement = $this->db->prepare(
    'INSERT INTO pageviews (site_id, host, canonical_host, path, referrer, user_agent, ip_address, ip_hash, session_id, duration_seconds, page_count, keyword, is_mobile, is_unique, is_proxy_risk, country_name, region_name, city_name, isp_domain, country_code, occurred_at) VALUES
    (:site_id, :host, :canonical_host, :path, :referrer, :user_agent, :ip_address, :ip_hash, :session_id, :duration_seconds, :page_count, :keyword, :is_mobile, :is_unique, :is_proxy_risk, :country_name, :region_name, :city_name, :isp_domain, :country_code, :occurred_at)'
);
        $statement->execute([
            ':site_id' => $site['id'],
            ':host' => $host,
            ':canonical_host' => $canonicalHost,
            ':path' => $path,
            ':referrer' => $referrer ?: null,
            ':user_agent' => $userAgent,
            ':ip_address' => $ip,
            ':ip_hash' => $ipHash,
            ':session_id' => $sessionId,
            ':duration_seconds' => $duration,
            ':page_count' => $pageCount,
            ':keyword' => $keyword ?: null,
            ':is_mobile' => $isMobile ? 1 : 0,
            ':is_unique' => $isUnique ? 1 : 0,
            ':is_proxy_risk' => (int) ($proxyRisk['risk'] ?? false),
            ':country_name' => $countryName ?: null,
            ':region_name' => $regionName ?: null,
            ':city_name' => $cityName ?: null,
            ':isp_domain' => $ispName ?: null,
            ':country_code' => $countryCode ?: null,
            ':occurred_at' => $occurredAtStr,
        ]);

        $now = new DateTimeImmutable('now');
        $browser = $this->detectBrowser($userAgent);
        $engine = $this->detectSearchEngine($referrer, $userAgent);
        $referrerHost = $this->referrerHost($referrer);
        $entryPath = $this->captureEntryPath((int) $site['id'], $sessionId, $path);

        // Rollup aggregation is handled by async workers for large-scale accuracy and lower write load.
    }

    private function insertBotLog(
        int $siteId,
        string $path,
        string $referrer,
        string $userAgent,
        ?string $ip,
        string $domain,
        string $engine
    ): void {
        $this->enqueueBotLog([
            'site_id' => $siteId,
            'path' => $path,
            'referrer' => $referrer,
            'user_agent' => $userAgent,
            'ip_address' => $ip,
            'domain' => $domain,
            'engine' => $engine,
        ]);
    }

    private function persistBotLog(
        int $siteId,
        string $path,
        string $referrer,
        string $userAgent,
        ?string $ip,
        string $domain,
        string $engine
    ): void {
        $occurredAt = new DateTimeImmutable('now');
        $bucketStart = $occurredAt->setTime((int) $occurredAt->format('H'), 0, 0);
        $bucketKey = $bucketStart->format('Y-m-d H:i:s');
        $domain = $this->limitText(ltrim($domain, '/'), 255);

        $statement = $this->db->prepare(
            'INSERT INTO pageview_bot_logs (site_id, bucket_start, occurred_at, path, referrer, user_agent, ip_address, domain, engine)
             VALUES (:site_id, :bucket_start, NOW(), :path, :referrer, :user_agent, :ip_address, :domain, :engine)'
        );
        $statement->execute([
            ':site_id' => $siteId,
            ':bucket_start' => $bucketKey,
            ':path' => $this->limitText($path, 512, '/'),
            ':referrer' => $this->limitText($referrer, 2048),
            ':user_agent' => $this->limitText($userAgent, 1024),
            ':ip_address' => $ip,
            ':domain' => $domain !== '' ? $domain : null,
            ':engine' => $this->limitText($engine, 64),
        ]);
    }

    private function enqueueBotLog(array $payload): void
    {
        $record = [
            'payload' => $payload,
            'received_at' => time(),
        ];

        $this->redis->lPush($this->botQueueKey, json_encode($record));

        if ($this->botMaxQueueLength > 0) {
            $this->redis->lTrim($this->botQueueKey, 0, $this->botMaxQueueLength - 1);
        }
    }

    private function enqueuePageview(string $trackingId, array $payload): void
    {
        $record = [
            'tracking_id' => $trackingId,
            'payload' => $payload,
            'received_at' => time(),
        ];

        $this->redis->lPush($this->ingestQueueKey, json_encode($record));

        if ($this->ingestMaxQueueLength > 0) {
            $this->redis->lTrim($this->ingestQueueKey, 0, $this->ingestMaxQueueLength - 1);
        }
    }

    private function autoDrainQueue(): void
    {
        if (!$this->ingestAutoDrain || $this->ingestAutoDrainEvery <= 0) {
            return;
        }

        $counterKey = $this->ingestQueueKey . ':autodrain';
        $count = (int) $this->redis->incr($counterKey);
        if ($count === 1) {
            $this->redis->expire($counterKey, 60);
        }

        if ($count % $this->ingestAutoDrainEvery !== 0) {
            return;
        }

        $this->drainIngestQueue($this->ingestAutoDrainBatch);
    }

    private function limitText(?string $value, int $max, string $fallback = ''): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }

        return substr($value, 0, $max);
    }

    private function shouldFilterIngest(?string $ip, string $keyword, string $path, string $referrer, string $userAgent): bool
    {
        $ipFilters = $this->ingestFilters['ip_filters'] ?? '';
        $keywordFilters = $this->ingestFilters['keyword_filters'] ?? '';
        $asnFilters = $this->ingestFilters['asn_filters'] ?? '';
        $uaFilters = $this->ingestFilters['ua_filters'] ?? '';

        if ($ip && $ipFilters !== '' && $this->ipMatchesFilters($ip, $ipFilters)) {
            return true;
        }

        if ($ip && $this->isBlockedProxyIp($ip)) {
            return true;
        }

        if ($keywordFilters !== '' && $this->keywordMatchesFilters($keyword, $path, $referrer, $keywordFilters)) {
            return true;
        }

        if ($uaFilters !== '' && $this->uaMatchesFilters($userAgent, $uaFilters)) {
            return true;
        }

        if ($ip && $asnFilters !== '') {
            $asn = $this->resolveAsnMeta($ip);
            if ($this->asnMatchesFilters($asn['number'] ?? '', $asn['name'] ?? '', $asnFilters)) {
                return true;
            }
        }

        return false;
    }

    private function ipMatchesFilters(string $ip, string $filters): bool
    {
        $entries = $this->splitFilterList($filters);
        if (!$entries) {
            return false;
        }

        $ipLong = ip2long($ip);
        foreach ($entries as $entry) {
            if ($entry === '') {
                continue;
            }

            if (str_contains($entry, '/')) {
                [$base, $bits] = array_pad(explode('/', $entry, 2), 2, '');
                $baseLong = ip2long($base);
                $bits = (int) $bits;
                if ($baseLong === false || $bits <= 0 || $bits > 32 || $ipLong === false) {
                    continue;
                }
                $mask = -1 << (32 - $bits);
                if (($ipLong & $mask) === ($baseLong & $mask)) {
                    return true;
                }
                continue;
            }

            if (str_contains($entry, '-')) {
                [$start, $end] = array_pad(explode('-', $entry, 2), 2, '');
                $startLong = ip2long($start);
                $endLong = ip2long($end);
                if ($startLong !== false && $endLong !== false && $ipLong !== false) {
                    if ($ipLong >= $startLong && $ipLong <= $endLong) {
                        return true;
                    }
                }
                continue;
            }

            if (str_contains($entry, '*')) {
                $prefix = rtrim(str_replace('*', '', $entry));
                if ($prefix !== '' && str_starts_with($ip, $prefix)) {
                    return true;
                }
                continue;
            }

            if (str_ends_with($entry, '.')) {
                if (str_starts_with($ip, $entry)) {
                    return true;
                }
                continue;
            }

            if ($ip === $entry) {
                return true;
            }
        }

        return false;
    }

    private function keywordMatchesFilters(string $keyword, string $path, string $referrer, string $filters): bool
    {
        $entries = $this->splitFilterList($filters);
        if (!$entries) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '') {
                continue;
            }
            $match = function (string $haystack) use ($entry): bool {
                if ($haystack === '') {
                    return false;
                }
                if (function_exists('mb_stripos')) {
                    return mb_stripos($haystack, $entry) !== false;
                }
                return stripos($haystack, $entry) !== false;
            };

            if ($match($keyword)) {
                return true;
            }
            if ($match($path)) {
                return true;
            }
            if ($match($referrer)) {
                return true;
            }
        }

        return false;
    }

    private function uaMatchesFilters(string $userAgent, string $filters): bool
    {
        $entries = $this->splitFilterList($filters);
        if (!$entries) {
            return false;
        }

        $ua = strtolower($userAgent);
        if ($ua === '') {
            return false;
        }

        foreach ($entries as $entry) {
            $needle = strtolower($entry);
            if ($needle === '') {
                continue;
            }

            if (function_exists('mb_stripos')) {
                if (mb_stripos($ua, $needle) !== false) {
                    return true;
                }
                continue;
            }

            if (stripos($ua, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isChinaNetwork(string $country, array $asnMeta): bool
    {
        $countryValue = trim($country);
        if ($countryValue !== '' && !str_contains($countryValue, '中国')) {
            return false;
        }

        $asnName = strtolower(trim((string) ($asnMeta['name'] ?? '')));
        $ispName = strtolower(trim((string) ($asnMeta['isp'] ?? '')));
        $combined = $asnName . ' ' . $ispName;

        $chinaIsps = [
            'china mobile',
            'china unicom',
            'china telecom',
            'cmcc',
            'unicom',
            'chinanet',
            'cnc',
            'ct',
            '移动',
            '联通',
            '电信',
            '铁通',
            '广电',
            '教育网',
            '长城宽带',
            '鹏博士',
        ];

        foreach ($chinaIsps as $isp) {
            if ($isp !== '' && str_contains($combined, strtolower($isp))) {
                return true;
            }
        }

        return $countryValue !== '';
    }

    private function isProxySuspicious(
        string $sessionId,
        string $fingerprint,
        bool $uidProvided,
        string $fallbackUid,
        string $userAgent,
        int $duration,
        int $pageCount,
        ?string $ip,
        ?string $ipHash,
        array $asnMeta,
        string $city,
        string $region,
        string $country,
        array $headerMeta,
        bool $isMobile,
        string $siteHost
    ): array {
        if (!$ip) {
            return ['blocked' => false, 'risk' => false, 'score' => 0];
        }

        $uid = $uidProvided
            ? ($fingerprint !== '' ? $fingerprint : $sessionId)
            : $fallbackUid;
        if ($uid === '') {
            return ['blocked' => false, 'risk' => false, 'score' => 0];
        }
        $uidMissing = !$uidProvided;

        try {
            $blockedKey = "proxy:blocked_uid:{$uid}";
            if ($this->redis->get($blockedKey)) {
                $this->rememberBlockedProxyIp($ip);
                return ['blocked' => true, 'risk' => true, 'score' => 100];
            }
        } catch (Throwable $e) {
            // ignore
        }

        $nowMs = (int) round(microtime(true) * 1000);
        $profileKey = "proxy:risk:{$uid}";
        $coarseMode = false;
        try {
            $churnKey = "proxy:uid_churn:{$ip}";
            $churnCount = (int) $this->redis->incr($churnKey);
            if ($churnCount === 1) {
                $this->redis->expire($churnKey, 1);
            }
            if ($churnCount > 25) {
                $uid = "ip:{$ip}";
                $profileKey = "proxy:risk:ip:{$ip}";
                $coarseMode = true;
            }
        } catch (Throwable $e) {
            // ignore
        }
        $sessionKey = $sessionId !== '' ? "proxy:session:{$sessionId}" : '';

        $asnValue = trim((string) ($asnMeta['number'] ?? $asnMeta['name'] ?? ''));
        $cityValue = trim($city);
        $regionValue = trim($region);
        $countryValue = trim($country);
        $isChinaNetwork = $this->isChinaNetwork($countryValue, $asnMeta);
        $isDataCenterAsn = $this->isDataCenterAsn($asnMeta);
        $uaSuspicious = $this->isSuspiciousUserAgent($userAgent);
        $ipChanged = true;
        $score = 0;
        $lastTs = 0;
        $ipChangeCount = 0;
        $requestCount = 0;
        $windowStart = 0;
        $goodScore = 0;
        $crossRegionHits = 0;
        $highFreqHits = 0;
        $sustainedHits = 0;
        $lastUa = '';
        $lastFp = '';

        try {
            $profile = $this->redis->hGetAll($profileKey);
            $score = (int) ($profile['score'] ?? 0);
            $lastIp = (string) ($profile['last_ip'] ?? '');
            $lastAsn = (string) ($profile['last_asn'] ?? '');
            $lastCity = (string) ($profile['last_city'] ?? '');
            $lastRegion = (string) ($profile['last_region'] ?? '');
            $lastCountry = (string) ($profile['last_country'] ?? '');
            $lastTs = (int) ($profile['last_ts'] ?? 0);
            $ipChangeCount = (int) ($profile['ip_change_count'] ?? 0);
            $requestCount = (int) ($profile['request_count'] ?? 0);
            $windowStart = (int) ($profile['window_start'] ?? 0);
            $goodScore = (int) ($profile['good_score'] ?? 0);
            $crossRegionHits = (int) ($profile['cross_region_hits'] ?? 0);
            $highFreqHits = (int) ($profile['high_freq_hits'] ?? 0);
            $sustainedHits = (int) ($profile['sustained_hits'] ?? 0);
            $lastUa = (string) ($profile['last_ua'] ?? '');
            $lastFp = (string) ($profile['last_fp'] ?? '');

            if ($windowStart <= 0 || ($nowMs - $windowStart) > 300000) {
                $windowStart = $nowMs;
                $ipChangeCount = 0;
                $requestCount = 0;
                $crossRegionHits = 0;
                $highFreqHits = 0;
                $sustainedHits = 0;
            }

            $requestCount += 1;
            $ipChanged = $lastIp !== '' && $lastIp !== $ip;

            $gapMs = $lastTs > 0 ? ($nowMs - $lastTs) : 0;
            if ($gapMs > 0) {
                $decaySteps = (int) floor($gapMs / 600000);
                if ($decaySteps > 0) {
                    $score = (int) round($score * (0.9 ** $decaySteps));
                }
            }
            $deduct = 0;
            if ($duration >= 10 && $duration <= 120) {
                $deduct += 5;
            }
            if ($pageCount >= 3 && $pageCount <= 5) {
                $deduct += 5;
            } elseif ($pageCount > 5) {
                $deduct += 15;
            }
            if ($gapMs > 86400000 && ($lastUa !== '' && $lastUa === $userAgent)) {
                $deduct += 20;
            }
            $referrerHost = strtolower(trim((string) ($headerMeta['referrer_host'] ?? '')));
            $siteHostValue = strtolower(trim($siteHost));
            if ($referrerHost !== '' && $siteHostValue !== '' && $referrerHost === $siteHostValue) {
                $deduct += 5;
            }
            $trustedHosts = [
                'baidu.com',
                'sogou.com',
                'so.com',
                'google.com',
                'bing.com',
                'wechat.com',
                'douyin.com',
                'bilibili.com',
                'weibo.com',
                'zhihu.com',
            ];
            foreach ($trustedHosts as $trusted) {
                if ($referrerHost !== '' && $trusted !== '' && (str_ends_with($referrerHost, $trusted))) {
                    $deduct += 25;
                    break;
                }
            }

            $headerScore = $this->headerIntegrityScore($userAgent, $headerMeta);
            if ($headerScore > 0) {
                $score += $headerScore;
            }

            $ntimeScore = $this->clientTimeDriftScore($headerMeta, (int) round($nowMs / 1000));
            if ($ntimeScore > 0) {
                $score += $ntimeScore;
            }

            if ($uidMissing) {
                $score += 6;
            }
            if ($coarseMode) {
                $score += 4;
            }

            if ($uaSuspicious) {
                $score += 16;
                $highFreqHits += 1;
            }

            if ($isDataCenterAsn) {
                $score += 18;
            }

            if ($countryValue === '' || $countryValue === '未知' || str_contains($countryValue, '保留地址')) {
                $score += 6;
            }

            if ($pageCount <= 1 && $duration <= 1 && $requestCount >= 4) {
                $score += 8;
            }

            $geoCross = false;
            if ($ipChanged) {
                $ipChangeCount += 1;
                $geoSameRegion = $regionValue !== '' && $regionValue === $lastRegion;
                $geoSameCity = $cityValue !== '' && $cityValue === $lastCity;
                $geoSameCountry = $countryValue !== '' && $countryValue === $lastCountry;

                $geoCross = (!$geoSameCountry && $countryValue !== '' && $lastCountry !== '')
                    || (!$geoSameRegion && $regionValue !== '' && $lastRegion !== '');

                $asnChanged = $asnValue !== '' && $lastAsn !== '' && $asnValue !== $lastAsn;
                $mobileChina = $isMobile && $isChinaNetwork && !$isDataCenterAsn;
                if ($geoCross && $asnChanged) {
                    $score += $mobileChina ? 4 : 12;
                    $crossRegionHits += 1;
                } elseif ($asnChanged) {
                    $score += $mobileChina ? 2 : 4;
                } else {
                    $score += 1;
                }

                if ($asnChanged && !$geoSameRegion && !$geoSameCountry) {
                    $score += 6;
                }

                if ($lastTs > 0 && ($nowMs - $lastTs) < 500 && !$mobileChina) {
                    $score += 6;
                }
            }

            if ($ipChangeCount > 6 && ($nowMs - $windowStart) <= 300000) {
                $score += 20;
                if (($nowMs - $windowStart) >= 180000) {
                    $sustainedHits += 1;
                }
            }

            $windowKey = "proxy:req:{$uid}:window10";
            $windowCount = (int) $this->redis->incr($windowKey);
            if ($windowCount === 1) {
                $this->redis->expire($windowKey, 10);
            }
            $hourKey = "proxy:req:{$uid}:1h";
            $hourCount = (int) $this->redis->incr($hourKey);
            if ($hourCount === 1) {
                $this->redis->expire($hourKey, 3600);
            }
            $dayKey = "proxy:req:{$uid}:24h";
            $dayCount = (int) $this->redis->incr($dayKey);
            if ($dayCount === 1) {
                $this->redis->expire($dayKey, 86400);
            }
            $uaStable = $lastUa !== '' && $lastUa === $userAgent;
            $fpStable = $lastFp !== '' && $lastFp === $fingerprint;
            $identityStable = $uaStable || $fpStable;
            $isNat = false;
            if ($ip && $sessionId !== '') {
                $natKey = "proxy:ip_sessions:{$ip}";
                $this->redis->sAdd($natKey, $sessionId);
                $this->redis->expire($natKey, 3600);
                $isNat = $this->redis->sCard($natKey) > 5;
            }
            $windowLimit = $isNat ? 30 : 15;
            if ($windowCount > $windowLimit && $identityStable) {
                $score += 10;
                $highFreqHits += 1;
            }
            if ($hourCount > 300) {
                $score += 6;
            }
            if ($dayCount > 2000) {
                $score += 8;
            }

            $isForeign = $countryValue !== '' && $countryValue !== '中国' && strcasecmp($countryValue, 'China') !== 0;
            if ($isForeign && ($ipChanged || $highFreqHits > 0)) {
                $score += 6;
            }

            if ($ipChanged && $ipChangeCount >= 3 && $highFreqHits >= 1 && !$isDataCenterAsn) {
                $score += 10;
                if (($nowMs - $windowStart) >= 120000) {
                    $sustainedHits += 1;
                }
            }

            $updateKey = "proxy:risk:update:{$uid}";
            $allowUpdate = $this->redis->setnx($updateKey, '1');
            if ($allowUpdate) {
                $this->redis->expire($updateKey, 1);
            } else {
                $isRisk = $score >= 50 || ($crossRegionHits >= 1 && $highFreqHits >= 1);
                $this->markProxyRiskStatus($ipHash, $isRisk);
                return ['blocked' => false, 'risk' => $isRisk, 'score' => $score];
            }

            if ($sessionKey && !$coarseMode) {
                $sessionProfile = $this->redis->hGetAll($sessionKey);
                $sessionUa = (string) ($sessionProfile['ua'] ?? '');
                $sessionFp = (string) ($sessionProfile['fp'] ?? '');
                if (($sessionUa && $sessionUa !== $userAgent) || ($sessionFp && $sessionFp !== $fingerprint && $fingerprint !== '')) {
                    $score += 5;
                }
                $this->redis->hMSet($sessionKey, [
                    'ua' => $userAgent,
                    'fp' => $fingerprint,
                    'ts' => $nowMs,
                ]);
                $this->redis->expire($sessionKey, 1800);
            }

            if ($score < 0) {
                $score = 0;
            }
            if ($score > 100) {
                $score = 100;
            }

            if ($deduct > 0) {
                $remaining = max(0, 40 - $goodScore);
                if ($remaining > 0) {
                    $applied = min($deduct, $remaining);
                    $score = max(0, $score - $applied);
                    $goodScore += $applied;
                }
            }

            if ($isChinaNetwork && !$isDataCenterAsn) {
                $score = (int) round($score * 0.6);
            }

            $this->redis->hMSet($profileKey, [
                'score' => $score,
                'last_ip' => $ip,
                'last_asn' => $asnValue,
                'last_city' => $cityValue,
                'last_region' => $regionValue,
                'last_country' => $countryValue,
                'last_ua' => $userAgent,
                'last_fp' => $fingerprint,
                'last_ts' => $nowMs,
                'ip_change_count' => $ipChangeCount,
                'request_count' => $requestCount,
                'window_start' => $windowStart,
                'good_score' => $goodScore,
                'cross_region_hits' => $crossRegionHits,
                'high_freq_hits' => $highFreqHits,
                'sustained_hits' => $sustainedHits,
            ]);
            $this->redis->expire($profileKey, 1800);

            if ($score >= 95 || ($score >= 75 && $highFreqHits >= 2)) {
                $probationKey = "proxy:probation:{$ip}";
                $isProbation = (bool) $this->redis->get($probationKey);
                if (!$isProbation) {
                    $this->redis->setex($probationKey, $this->proxyProbationSeconds, '1');
                    $this->markProxyRiskStatus($ipHash, true);
                    return ['blocked' => false, 'risk' => true, 'score' => $score];
                }

                $this->rememberBlockedProxyIp($ip);
                $this->redis->setex($blockedKey, 14400, '1');
                return ['blocked' => true, 'risk' => true, 'score' => $score];
            }
        } catch (Throwable $e) {
            return ['blocked' => false, 'risk' => false, 'score' => 0];
        }

        $isRisk = $score >= 50 || ($crossRegionHits >= 1 && $highFreqHits >= 1);
        $this->markProxyRiskStatus($ipHash, $isRisk);
        return ['blocked' => false, 'risk' => $isRisk, 'score' => $score];
    }

    private function isBlockedProxyIp(string $ip): bool
    {
        try {
            $score = $this->redis->zScore('proxy:blocked_ips', $ip);
            if (!$score) {
                return false;
            }

            $now = time();
            if (($now - (int) $score) >= $this->proxyRecoverySeconds) {
                $this->redis->zRem('proxy:blocked_ips', $ip);
                return false;
            }

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function rememberBlockedProxyIp(string $ip, ?int $now = null): void
    {
        $now = $now ?? time();
        try {
            $key = 'proxy:blocked_ips';
            $this->redis->zAdd($key, $now, $ip);
            $this->redis->zRemRangeByScore($key, 0, $now - 14400);
            $this->queueProxyCleanup($ip, $now);
        } catch (Throwable $e) {
            // ignore
        }
    }

    private function queueProxyCleanup(string $ip, int $now): void
    {
        try {
            $key = 'proxy:cleanup_queue';
            $this->redis->zAdd($key, $now, $ip);
            $this->redis->zRemRangeByScore($key, 0, $now - 14400);
        } catch (Throwable $e) {
            // ignore
        }
    }

    private function markProxyRiskStatus(?string $ipHash, bool $isRisk): void
    {
        if (!$ipHash) {
            return;
        }

        $key = "proxy:risk_ip:{$ipHash}";
        try {
            if ($isRisk) {
                $this->redis->setex($key, $this->proxyRiskHoldSeconds, '1');
                return;
            }

            if ($this->redis->get($key)) {
                $this->redis->del($key);
                $this->redis->zAdd('proxy:risk_recover', time(), $ipHash);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    private function maybeCleanupProxyHistory(): void
    {
        if ($this->proxyCleanupBatch <= 0) {
            return;
        }

        $now = time();
        $lastKey = 'proxy:cleanup:last';
        $last = (int) ($this->redis->get($lastKey) ?: 0);
        if ($last > 0 && ($now - $last) < $this->proxyCleanupInterval) {
            return;
        }

        $lockKey = 'proxy:cleanup:lock';
        if (!$this->redis->setnx($lockKey, '1')) {
            return;
        }
        $this->redis->expire($lockKey, 60);
        $this->redis->setex($lastKey, $this->proxyCleanupInterval, (string) $now);

        $this->cleanupProxyHistory($this->proxyCleanupBatch);
    }

    private function cleanupProxyHistory(int $batch): void
    {
        $batch = max(1, $batch);
        $queueKey = 'proxy:cleanup_queue';

        try {
            $targets = $this->redis->zRange($queueKey, 0, $batch - 1, true);
        } catch (Throwable $e) {
            return;
        }

        if (empty($targets)) {
            return;
        }

        foreach ($targets as $ip => $detectedAt) {
            $this->cleanupProxyIpData((string) $ip);
            try {
                $this->redis->zRem($queueKey, (string) $ip);
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    private function cleanupProxyIpData(string $ip): void
    {
        $ip = trim($ip);
        if ($ip === '') {
            return;
        }

        $statement = $this->db->prepare(
            'SELECT site_id, MIN(occurred_at) as min_ts, MAX(occurred_at) as max_ts
             FROM pageviews
             WHERE ip_address = :ip
             GROUP BY site_id'
        );
        $statement->execute([':ip' => $ip]);
        $rows = $statement->fetchAll();
        if (empty($rows)) {
            return;
        }

        $this->deleteBatched(
            'DELETE FROM pageviews WHERE ip_address = :ip LIMIT :batch',
            [':ip' => $ip],
            50000
        );

        $ipHash = hash('sha256', $ip);
        foreach ($rows as $row) {
            $siteId = (int) ($row['site_id'] ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            $minTs = $row['min_ts'] ? new DateTimeImmutable($row['min_ts']) : null;
            $maxTs = $row['max_ts'] ? new DateTimeImmutable($row['max_ts']) : null;
            if (!$minTs || !$maxTs) {
                continue;
            }

            $startBucket = $minTs->setTime((int) $minTs->format('H'), 0, 0);
            $endBucket = $maxTs->setTime((int) $maxTs->format('H'), 0, 0)->modify('+1 hour');

            $deleteAudience = $this->db->prepare(
                'DELETE FROM site_ip_audience WHERE site_id = :site_id AND ip_hash = :ip_hash LIMIT 1'
            );
            $deleteAudience->execute([
                ':site_id' => $siteId,
                ':ip_hash' => $ipHash,
            ]);

            $this->rebuildRollupRange($siteId, $startBucket, $endBucket);
        }
    }

    private function rebuildRollupRange(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        $current = $start;
        while ($current < $end) {
            $bucketStart = $current;
            $bucketEnd = $bucketStart->modify('+1 hour');
            $this->rebuildRollupBucket($siteId, $bucketStart, $bucketEnd);
            $current = $bucketEnd;
        }
    }

    private function rebuildRollupBucket(int $siteId, DateTimeImmutable $bucketStart, DateTimeImmutable $bucketEnd): void
    {
        $bucketKey = $bucketStart->format('Y-m-d H:i:s');
        $start = $bucketStart->format('Y-m-d H:i:s');
        $end = $bucketEnd->format('Y-m-d H:i:s');
        $dayStart = $bucketStart->setTime(0, 0, 0);
        $dayEnd = $dayStart->modify('+1 day');
        $dayStartKey = $dayStart->format('Y-m-d H:i:s');
        $dayEndKey = $dayEnd->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM pageview_rollups WHERE site_id = ? AND bucket_start = ?')
                ->execute([$siteId, $bucketKey]);
            $this->db->prepare('DELETE FROM pageview_dimension_rollups WHERE site_id = ? AND bucket_start = ?')
                ->execute([$siteId, $bucketKey]);
            $this->db->prepare('DELETE FROM pageview_page_rollups WHERE site_id = ? AND bucket_start = ?')
                ->execute([$siteId, $bucketKey]);
            $this->db->prepare('DELETE FROM pageview_entry_rollups WHERE site_id = ? AND bucket_start = ?')
                ->execute([$siteId, $bucketKey]);

            $totalsStmt = $this->db->prepare(
                "SELECT COUNT(*) as views, SUM(is_unique) as uniques, COUNT(DISTINCT ip_hash) as ips
                 FROM pageviews
                 WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ?"
            );
            $totalsStmt->execute([$siteId, $start, $end]);
            $totals = $totalsStmt->fetch() ?: [];

            $sessionStmt = $this->db->prepare(
                "SELECT COUNT(*) as sessions, SUM(duration_seconds) as duration_sum, SUM(page_count) as page_sum, SUM(bounce) as bounce_count
                 FROM (
                    SELECT MAX(duration_seconds) as duration_seconds,
                           MAX(page_count) as page_count,
                           CASE WHEN MAX(page_count) <= 1 THEN 1 ELSE 0 END as bounce
                    FROM pageviews
                    WHERE site_id = ? AND session_id IS NOT NULL
                      AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY session_id
                 ) t"
            );
            $sessionStmt->execute([$siteId, $start, $end]);
            $sessions = $sessionStmt->fetch() ?: [];

            $insertRollup = $this->db->prepare(
                'INSERT INTO pageview_rollups (site_id, bucket_start, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertRollup->execute([
                $siteId,
                $bucketKey,
                (int) ($totals['views'] ?? 0),
                (int) ($totals['uniques'] ?? 0),
                (int) ($totals['ips'] ?? 0),
                (int) ($sessions['sessions'] ?? 0),
                (int) ($sessions['duration_sum'] ?? 0),
                (int) ($sessions['page_sum'] ?? 0),
                (int) ($sessions['bounce_count'] ?? 0),
            ]);

            $dimensionInserts = [
                'host' => "SELECT LEFT(COALESCE(canonical_host, '未知域名'), 255) as dimension_value,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                    FROM pageviews p WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY dimension_value",
                'host_device' => "SELECT LEFT(CONCAT(COALESCE(canonical_host, '未知域名'), '|', IF(is_mobile = 1, 'mobile', 'desktop')), 255) as dimension_value,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                    FROM pageviews p WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY dimension_value",
                'device' => "SELECT IF(is_mobile = 1, 'mobile', 'desktop') as dimension_value,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                    FROM pageviews p WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY dimension_value",
                'browser' => "SELECT LEFT(" . $this->browserCase('p') . ", 255) as dimension_value,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                    FROM pageviews p WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY dimension_value",
                'referrer_host' => "SELECT dimension_value, pv, uv, ips, sessions, duration_sum, page_sum, bounce_count
                    FROM (
                        SELECT LEFT(" . $this->referrerHostExpr('p') . ", 255) as dimension_value,
                            SUM(p.page_count) as pv,
                            SUM(p.is_unique) as uv,
                            COUNT(DISTINCT p.ip_hash) as ips,
                            COUNT(*) as sessions,
                            SUM(p.duration_seconds) as duration_sum,
                            SUM(p.page_count) as page_sum,
                            SUM(CASE WHEN p.page_count <= 1 THEN 1 ELSE 0 END) as bounce_count
                        FROM (
                            SELECT MIN(id) as first_id, session_id
                            FROM pageviews
                            WHERE site_id = ? AND session_id IS NOT NULL
                              AND occurred_at >= ? AND occurred_at < ?
                            GROUP BY session_id
                        ) s
                        JOIN pageviews p ON p.id = s.first_id
                        GROUP BY dimension_value
                        ORDER BY ips DESC
                        LIMIT 500
                    ) t",
                'search_engine' => "SELECT LEFT(" . $this->searchEngineCase('p') . ", 255) as dimension_value,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                    FROM pageviews p WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY dimension_value",
                'keyword_engine' => "SELECT LEFT(CONCAT(COALESCE(keyword,''), '|', " . $this->searchEngineCase('p') . ", '|', COALESCE(canonical_host, host, s.domain, ''), COALESCE(NULLIF(path,''), '/')), 255) as dimension_value,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                    FROM pageviews p
                    JOIN sites s ON s.id = p.site_id
                    WHERE p.site_id = ? AND p.keyword IS NOT NULL AND keyword != '' AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY dimension_value",
                'audience' => "SELECT LEFT(CASE WHEN a.first_seen >= ? AND a.first_seen < ? THEN 'new' ELSE 'returning' END, 255) as dimension_value,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT p.ip_hash) as ips
                    FROM pageviews p
                    LEFT JOIN site_ip_audience a ON a.site_id = p.site_id AND a.ip_hash = p.ip_hash
                    WHERE p.site_id = ? AND p.p.occurred_at >= ? AND p.occurred_at < ?
                    GROUP BY dimension_value",
            ];

            foreach ($dimensionInserts as $dimension => $sql) {
                $useSessionMetrics = $dimension === 'referrer_host';
                $insert = $this->db->prepare(
                    'INSERT INTO pageview_dimension_rollups (site_id, bucket_start, dimension_type, dimension_value, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
                     SELECT ?, ?, ?, dimension_value, pv, uv, ips, ' .
                    ($useSessionMetrics ? 'sessions, duration_sum, page_sum, bounce_count' : '0, 0, 0, 0') .
                    ' FROM (' . $sql . ') t'
                );
                if ($dimension === 'audience') {
                    $params = [$dayStartKey, $dayEndKey, $siteId, $start, $end];
                } else {
                    $params = [$siteId, $start, $end];
                }
                $insert->execute(array_merge([$siteId, $bucketKey, $dimension], $params));
            }

            $geoStmt = $this->db->prepare(
                "SELECT ip_address, ip_hash, COUNT(*) as pv, SUM(is_unique) as uv
                 FROM pageviews
                 WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ?
                   AND ip_address IS NOT NULL AND ip_address != ''
                 GROUP BY ip_hash, ip_address"
            );
            $geoStmt->execute([$siteId, $start, $end]);
            $geoRows = $geoStmt->fetchAll();

            $regionAgg = [];
            $countryAgg = [];
            $ispAgg = [];

            foreach ($geoRows as $row) {
                $meta = $this->ipResolver->resolve($row['ip_address'] ?? '');
                $country = trim($meta['country_name'] ?? '');
                $region = trim($meta['region_name'] ?? '');
                $isp = trim($meta['isp_domain'] ?? '');

                $regionKey = $this->regionLabel($country, $region);
                $countryKey = $country !== '' ? $country : '未知';
                $ispKey = $isp !== '' ? $isp : '未知运营商';

                $pv = (int) ($row['pv'] ?? 0);
                $uv = (int) ($row['uv'] ?? 0);
                $ips = 1;

                $regionAgg[$regionKey]['pv'] = ($regionAgg[$regionKey]['pv'] ?? 0) + $pv;
                $regionAgg[$regionKey]['uv'] = ($regionAgg[$regionKey]['uv'] ?? 0) + $uv;
                $regionAgg[$regionKey]['ips'] = ($regionAgg[$regionKey]['ips'] ?? 0) + $ips;

                $countryAgg[$countryKey]['pv'] = ($countryAgg[$countryKey]['pv'] ?? 0) + $pv;
                $countryAgg[$countryKey]['uv'] = ($countryAgg[$countryKey]['uv'] ?? 0) + $uv;
                $countryAgg[$countryKey]['ips'] = ($countryAgg[$countryKey]['ips'] ?? 0) + $ips;

                $ispAgg[$ispKey]['pv'] = ($ispAgg[$ispKey]['pv'] ?? 0) + $pv;
                $ispAgg[$ispKey]['uv'] = ($ispAgg[$ispKey]['uv'] ?? 0) + $uv;
                $ispAgg[$ispKey]['ips'] = ($ispAgg[$ispKey]['ips'] ?? 0) + $ips;
            }

            $geoInsert = $this->db->prepare(
                'INSERT INTO pageview_dimension_rollups (site_id, bucket_start, dimension_type, dimension_value, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0)'
            );

            foreach ($regionAgg as $label => $data) {
                $geoInsert->execute([
                    $siteId,
                    $bucketKey,
                    'region',
                    mb_substr($label, 0, 255),
                    $data['pv'],
                    $data['uv'],
                    $data['ips'],
                ]);
            }

            foreach ($countryAgg as $label => $data) {
                $geoInsert->execute([
                    $siteId,
                    $bucketKey,
                    'country',
                    mb_substr($label, 0, 255),
                    $data['pv'],
                    $data['uv'],
                    $data['ips'],
                ]);
            }

            foreach ($ispAgg as $label => $data) {
                $geoInsert->execute([
                    $siteId,
                    $bucketKey,
                    'isp',
                    mb_substr($label, 0, 255),
                    $data['pv'],
                    $data['uv'],
                    $data['ips'],
                ]);
            }

            $pageStmt = $this->db->prepare(
                "INSERT INTO pageview_page_rollups (site_id, bucket_start, path, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
                 SELECT ?, ?, path, pv, uv, ips, 0, 0, 0, 0
                 FROM (
                    SELECT LEFT(COALESCE(path,'/'), 512) as path,
                        COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                    FROM pageviews p
                    WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY path
                    ORDER BY ips DESC
                    LIMIT 500
                 ) t"
            );
            $pageStmt->execute([$siteId, $bucketKey, $siteId, $start, $end]);

            $entryStmt = $this->db->prepare(
                "INSERT INTO pageview_entry_rollups (site_id, bucket_start, path, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
                 SELECT ?, ?, path, pv, uv, ips, session_count, duration_sum, page_sum, bounce_count
                 FROM (
                    SELECT LEFT(entry.path, 512) as path,
                        COUNT(*) as pv, SUM(entry.is_unique) as uv, COUNT(DISTINCT entry.ip_hash) as ips,
                        COUNT(*) as session_count,
                        SUM(entry.duration_seconds) as duration_sum,
                        SUM(entry.page_count) as page_sum,
                        SUM(CASE WHEN entry.page_count <= 1 THEN 1 ELSE 0 END) as bounce_count
                    FROM (
                        SELECT MIN(id) as first_id, session_id
                        FROM pageviews
                        WHERE site_id = ? AND session_id IS NOT NULL
                          AND occurred_at >= ? AND occurred_at < ?
                        GROUP BY session_id
                    ) s
                    JOIN pageviews entry ON entry.id = s.first_id
                    GROUP BY entry.path
                    ORDER BY ips DESC
                    LIMIT 500
                 ) t"
            );
            $entryStmt->execute([$siteId, $bucketKey, $siteId, $start, $end]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
        }
    }

    private function referrerHostExpr(string $alias): string
    {
        return "COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX({$alias}.referrer, '/', 3), '//', -1), ''), '直接访问')";
    }

    public function getBlockedProxyIps(int $limit = 200, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        try {
            $key = 'proxy:blocked_ips';
            $rows = $this->redis->zRevRange($key, $offset, $offset + $limit - 1, true);
            $results = [];
            foreach ($rows as $ip => $score) {
                $results[] = [
                    'ip' => (string) $ip,
                    'detected_at' => date('Y-m-d H:i:s', (int) $score),
                ];
            }
            return $results;
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getBlockedProxyIpCount(): int
    {
        try {
            return (int) $this->redis->zCard('proxy:blocked_ips');
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function splitFilterList(string $filters): array
    {
        $raw = preg_split('/[\r\n,;]+/', $filters);
        $clean = [];
        foreach ($raw as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $clean[] = $entry;
        }

        return $clean;
    }

    private function asnMatchesFilters(string $asnNumber, string $asnName, string $filters): bool
    {
        $entries = $this->splitFilterList($filters);
        if (!$entries) {
            return false;
        }

        $normalizedNumber = preg_replace('/[^0-9]/', '', $asnNumber);
        $normalizedName = strtolower($asnName);

        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            $normalizedEntryNumber = preg_replace('/[^0-9]/', '', $entry);
            if ($normalizedEntryNumber !== '' && $normalizedNumber !== '' && $normalizedEntryNumber === $normalizedNumber) {
                return true;
            }

            if ($normalizedName !== '' && stripos($normalizedName, $entry) !== false) {
                return true;
            }
        }

        return false;
    }

    public function drainIngestQueue(int $maxBatch = 500): int
    {
        $processed = 0;

        $this->recoverStalledIngestQueue();

        while ($processed < $maxBatch) {
            $raw = $this->redis->rPopLPush($this->ingestQueueKey, $this->ingestProcessingKey);

            if ($raw === false || $raw === null) {
                break;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || empty($decoded['tracking_id']) || !isset($decoded['payload']) || !is_array($decoded['payload'])) {
                $this->redis->lRem($this->ingestProcessingKey, $raw, 1);
                continue;
            }

            try {
                $receivedAt = (int) ($decoded['received_at'] ?? time());
                $this->processPageview($decoded['tracking_id'], $decoded['payload'], $receivedAt);
                $this->redis->lRem($this->ingestProcessingKey, $raw, 1);
                $processed++;
            } catch (Throwable $e) {
                // Leave the record in processing list for recovery retry.
            }
        }

        return $processed;
    }

    public function drainBotQueue(int $maxBatch = 500): int
    {
        $processed = 0;

        $this->recoverStalledBotQueue();

        while ($processed < $maxBatch) {
            $raw = $this->redis->rPopLPush($this->botQueueKey, $this->botProcessingKey);

            if ($raw === false || $raw === null) {
                break;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || empty($decoded['payload']) || !is_array($decoded['payload'])) {
                $this->redis->lRem($this->botProcessingKey, $raw, 1);
                continue;
            }

            $payload = $decoded['payload'];
            try {
                $this->persistBotLog(
                    (int) ($payload['site_id'] ?? 0),
                    (string) ($payload['path'] ?? ''),
                    (string) ($payload['referrer'] ?? ''),
                    (string) ($payload['user_agent'] ?? ''),
                    $payload['ip_address'] ?? null,
                    (string) ($payload['domain'] ?? ''),
                    (string) ($payload['engine'] ?? '')
                );
                $this->redis->lRem($this->botProcessingKey, $raw, 1);
                $processed++;
            } catch (Throwable $e) {
                // Leave the record in processing list for recovery retry.
            }
        }

        return $processed;
    }

    public function drainBlockedDomainQueue(int $maxBatch = 500): int
    {
        $processed = 0;

        $this->recoverStalledBlockedDomainQueue();

        while ($processed < $maxBatch) {
            $raw = $this->redis->rPopLPush($this->blockedDomainQueueKey, $this->blockedDomainProcessingKey);

            if ($raw === false || $raw === null) {
                break;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || empty($decoded['site_id']) || empty($decoded['domain'])) {
                $this->redis->lRem($this->blockedDomainProcessingKey, $raw, 1);
                continue;
            }

            try {
                $this->persistBlockedDomain((int) $decoded['site_id'], (string) $decoded['domain']);
                $this->redis->lRem($this->blockedDomainProcessingKey, $raw, 1);
                $processed++;
            } catch (Throwable $e) {
                // Leave the record in processing list for recovery retry.
            }
        }

        return $processed;
    }

    private function recoverStalledIngestQueue(): void
    {
        if ($this->ingestStalledAfter <= 0) {
            return;
        }

        $len = (int) $this->redis->lLen($this->ingestProcessingKey);
        if ($len <= 0) {
            return;
        }

        $cutoff = time() - $this->ingestStalledAfter;
        for ($i = 0; $i < $len; $i++) {
            $raw = $this->redis->rPop($this->ingestProcessingKey);
            if ($raw === false || $raw === null) {
                break;
            }

            $decoded = json_decode($raw, true);
            $receivedAt = is_array($decoded) ? (int) ($decoded['received_at'] ?? 0) : 0;
            if ($receivedAt > 0 && $receivedAt > $cutoff) {
                $this->redis->lPush($this->ingestProcessingKey, $raw);
                continue;
            }

            $this->redis->lPush($this->ingestQueueKey, $raw);
        }
    }

    private function recoverStalledBotQueue(): void
    {
        if ($this->ingestStalledAfter <= 0) {
            return;
        }

        $len = (int) $this->redis->lLen($this->botProcessingKey);
        if ($len <= 0) {
            return;
        }

        $cutoff = time() - $this->ingestStalledAfter;
        for ($i = 0; $i < $len; $i++) {
            $raw = $this->redis->rPop($this->botProcessingKey);
            if ($raw === false || $raw === null) {
                break;
            }

            $decoded = json_decode($raw, true);
            $receivedAt = is_array($decoded) ? (int) ($decoded['received_at'] ?? 0) : 0;
            if ($receivedAt > 0 && $receivedAt > $cutoff) {
                $this->redis->lPush($this->botProcessingKey, $raw);
                continue;
            }

            $this->redis->lPush($this->botQueueKey, $raw);
        }
    }

    private function recoverStalledBlockedDomainQueue(): void
    {
        if ($this->ingestStalledAfter <= 0) {
            return;
        }

        $len = (int) $this->redis->lLen($this->blockedDomainProcessingKey);
        if ($len <= 0) {
            return;
        }

        $cutoff = time() - $this->ingestStalledAfter;
        for ($i = 0; $i < $len; $i++) {
            $raw = $this->redis->rPop($this->blockedDomainProcessingKey);
            if ($raw === false || $raw === null) {
                break;
            }

            $decoded = json_decode($raw, true);
            $receivedAt = is_array($decoded) ? (int) ($decoded['received_at'] ?? 0) : 0;
            if ($receivedAt > 0 && $receivedAt > $cutoff) {
                $this->redis->lPush($this->blockedDomainProcessingKey, $raw);
                continue;
            }

            $this->redis->lPush($this->blockedDomainQueueKey, $raw);
        }
    }

    private function updateRollups(
        int $siteId,
        DateTimeImmutable $occurredAt,
        int $duration,
        int $pageCount,
        bool $isUnique,
        bool $uvToday,
        ?string $sessionId,
        array $dimensions = []
    ): void
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

        $uvIncrement = $uvToday ? 1 : 0;
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

        $this->updateDimensionRollups(
            $siteId,
            $bucketKey,
            $uvIncrement,
            $ipIncrement,
            $sessionIncrement,
            $durationIncrement,
            $pageIncrement,
            $bounceIncrement,
            $dimensions
        );
    }

    private function rollupRangeBounds(string $range): array
    {
        $now = new DateTimeImmutable('now');
        if (str_starts_with($range, 'custom:')) {
            [$start, $endExclusive] = $this->parseCustomRange($range);
            if ($start && $endExclusive) {
                return [$start, $endExclusive];
            }
        }
        $ranges = [
            'today' => $now->setTime(0, 0),
            'yesterday' => $now->modify('-1 day')->setTime(0, 0),
            'day_before' => $now->modify('-2 day')->setTime(0, 0),
            '7d' => $now->modify('-6 day')->setTime(0, 0),
            '30d' => $now->modify('-29 day')->setTime(0, 0),
            'all' => new DateTimeImmutable('1970-01-01 00:00:00'),
        ];

        $start = $ranges[$range] ?? $ranges['today'];
        if ($range === 'yesterday') {
            $end = $now->setTime(0, 0);
        } elseif ($range === 'day_before') {
            $end = $now->modify('-1 day')->setTime(0, 0);
        } else {
            $end = $now;
        }

        return [$start, $end];
    }

    private function rollupCoverageBounds(int $siteId): array
    {
        if (array_key_exists($siteId, $this->rollupCoverageCache)) {
            return $this->rollupCoverageCache[$siteId];
        }

        $statement = $this->db->prepare('SELECT MIN(bucket_start) as first_bucket, MAX(bucket_start) as last_bucket FROM pageview_rollups WHERE site_id = :site_id');
        $statement->execute([':site_id' => $siteId]);
        $row = $statement->fetch() ?: [];

        $start = !empty($row['first_bucket']) ? new DateTimeImmutable($row['first_bucket']) : null;
        $end = !empty($row['last_bucket']) ? (new DateTimeImmutable($row['last_bucket']))->modify('+1 hour') : null;

        $this->rollupCoverageCache[$siteId] = [$start, $end];

        return $this->rollupCoverageCache[$siteId];
    }

    private function rollupSpanForRange(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end): ?array
    {
        $cacheKey = $siteId . ':' . $start->format('YmdHi') . ':' . $end->format('YmdHi');
        if (array_key_exists($cacheKey, $this->rollupSpanCache)) {
            return $this->rollupSpanCache[$cacheKey];
        }

        [$coverageStart, $coverageEnd] = $this->rollupCoverageBounds($siteId);
        if (!$coverageStart || !$coverageEnd) {
            $this->rollupSpanCache[$cacheKey] = null;
            return null;
        }

        $windowStart = max($start, $coverageStart);
        $windowEnd = min($end, $coverageEnd);

        if ($windowStart >= $windowEnd) {
            $this->rollupSpanCache[$cacheKey] = null;
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT MIN(bucket_start) as first_bucket, MAX(bucket_start) as last_bucket, COUNT(DISTINCT bucket_start) as buckets
             FROM pageview_rollups
             WHERE site_id = :site_id AND bucket_start >= :start AND bucket_start < :end'
        );

        $statement->execute([
            ':site_id' => $siteId,
            ':start' => $windowStart->format('Y-m-d H:i:s'),
            ':end' => $windowEnd->format('Y-m-d H:i:s'),
        ]);

        $row = $statement->fetch() ?: [];
        $bucketCount = (int) ($row['buckets'] ?? 0);
        if ($bucketCount === 0 || empty($row['last_bucket'])) {
            $this->rollupSpanCache[$cacheKey] = null;
            return null;
        }

        $lastBucket = new DateTimeImmutable($row['last_bucket']);
        $firstBucket = !empty($row['first_bucket']) ? new DateTimeImmutable($row['first_bucket']) : $lastBucket;
        $contiguousStart = $lastBucket->modify('-' . ($bucketCount - 1) . ' hour');
        $spanStart = max($windowStart, $firstBucket, $contiguousStart);
        $spanEnd = min($windowEnd, $lastBucket->modify('+1 hour'));

        if ($spanStart >= $spanEnd) {
            $this->rollupSpanCache[$cacheKey] = null;
            return null;
        }
        $this->rollupSpanCache[$cacheKey] = [
            'start' => $spanStart,
            'end' => $spanEnd,
        ];
        return $this->rollupSpanCache[$cacheKey];
    }

    private function rollupCoverageStart(int $siteId): ?DateTimeImmutable
    {
        [$start] = $this->rollupCoverageBounds($siteId);

        return $start;
    }

    private function rollupsCoverRange(int $siteId, DateTimeImmutable $start, ?DateTimeImmutable $end = null): bool
    {
        $windowEnd = $end ?? $start;
        $span = $this->rollupSpanForRange($siteId, $start, $windowEnd);

        if (!$span) {
            return false;
        }

        return $span['start'] <= $start && $span['end'] >= $windowEnd;
    }

    private function rollupsCoverRangeForSites(array $siteIds, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        foreach ($siteIds as $siteId) {
            if (!$this->rollupsCoverRange((int) $siteId, $start, $end)) {
                return false;
            }
        }

        return true;
    }

    private function aggregateRollups(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $span = $this->rollupSpanForRange($siteId, $start, $end);

        if (!$span) {
            [$coverageStart, $coverageEnd] = $this->rollupCoverageBounds($siteId);
            return [
                'has_data' => false,
                'coverage_start' => $coverageStart,
                'coverage_end' => $coverageEnd,
                'views' => 0,
                'uniques' => 0,
                'ip_count' => 0,
                'session_count' => 0,
                'duration_sum' => 0,
                'page_sum' => 0,
                'bounce_count' => 0,
            ];
        }

        $statement = $this->db->prepare(
            'SELECT SUM(pv) as views, SUM(uv) as uniques, SUM(ip_count) as ip_count, SUM(session_count) as session_count,
                SUM(duration_sum) as duration_sum, SUM(page_sum) as page_sum, SUM(bounce_count) as bounce_count
             FROM pageview_rollups
             WHERE site_id = :site_id AND bucket_start >= :start AND bucket_start < :end'
        );

        $statement->execute([
            ':site_id' => $siteId,
            ':start' => $span['start']->format('Y-m-d H:i:s'),
            ':end' => $span['end']->format('Y-m-d H:i:s'),
        ]);

        $row = $statement->fetch() ?: [];

        return [
            'has_data' => true,
            'coverage_start' => $span['start'],
            'coverage_end' => $span['end'],
            'views' => (int) ($row['views'] ?? 0),
            'uniques' => (int) ($row['uniques'] ?? 0),
            'ip_count' => (int) ($row['ip_count'] ?? 0),
            'session_count' => (int) ($row['session_count'] ?? 0),
            'duration_sum' => (int) ($row['duration_sum'] ?? 0),
            'page_sum' => (int) ($row['page_sum'] ?? 0),
            'bounce_count' => (int) ($row['bounce_count'] ?? 0),
        ];
    }

    private function rollupWindowComplete(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $expectedBuckets = (int) ceil(($end->getTimestamp() - $start->getTimestamp()) / 3600);
        if ($expectedBuckets <= 0) {
            return false;
        }

        $statement = $this->db->prepare(
            'SELECT COUNT(DISTINCT bucket_start) as buckets
             FROM pageview_rollups
             WHERE site_id = :site_id AND bucket_start >= :start AND bucket_start < :end'
        );

        $statement->execute([
            ':site_id' => $siteId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
        ]);

        $row = $statement->fetch() ?: [];

        return ((int) ($row['buckets'] ?? 0)) >= $expectedBuckets;
    }

    private function aggregateRawWindow(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($this->rollupOnly) {
            return [
                'has_data' => false,
                'coverage_start' => $start,
                'views' => 0,
                'uniques' => 0,
                'ip_count' => 0,
                'session_count' => 0,
                'duration_sum' => 0,
                'page_sum' => 0,
                'bounce_count' => 0,
            ];
        }

        $ipExpr = $this->ipHashExpr('pageviews');

        $totalsStmt = $this->db->prepare(
            "SELECT COUNT(*) as views, SUM(is_unique) as uniques, COUNT(DISTINCT {$ipExpr}) as ip_count
             FROM pageviews
             WHERE site_id = :site_id AND occurred_at >= :start AND occurred_at < :end"
        );
        $totalsStmt->execute([
            ':site_id' => $siteId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
        ]);

        $totals = $totalsStmt->fetch() ?: [];

        $sessionStmt = $this->db->prepare(
            'SELECT COUNT(*) as session_count, SUM(duration_seconds) as duration_sum, SUM(page_count) as page_sum, SUM(bounce) as bounce_count
             FROM (
                SELECT COALESCE(MAX(duration_seconds), 0) as duration_seconds,
                       COALESCE(MAX(page_count), 0) as page_count,
                       CASE WHEN MAX(page_count) <= 1 THEN 1 ELSE 0 END as bounce
                FROM pageviews
                WHERE site_id = :site_id AND session_id IS NOT NULL AND occurred_at >= :start AND occurred_at < :end
                GROUP BY session_id
             ) t'
        );
        $sessionStmt->execute([
            ':site_id' => $siteId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
        ]);

        $sessions = $sessionStmt->fetch() ?: [];

        return [
            'has_data' => true,
            'coverage_start' => $start,
            'views' => (int) ($totals['views'] ?? 0),
            'uniques' => (int) ($totals['uniques'] ?? 0),
            'ip_count' => (int) ($totals['ip_count'] ?? 0),
            'session_count' => (int) ($sessions['session_count'] ?? 0),
            'duration_sum' => (int) ($sessions['duration_sum'] ?? 0),
            'page_sum' => (int) ($sessions['page_sum'] ?? 0),
            'bounce_count' => (int) ($sessions['bounce_count'] ?? 0),
        ];
    }

    private function combineTotals(array ...$segments): array
    {
        $result = [
            'has_data' => false,
            'views' => 0,
            'uniques' => 0,
            'ip_count' => 0,
            'session_count' => 0,
            'duration_sum' => 0,
            'page_sum' => 0,
            'bounce_count' => 0,
        ];

        foreach ($segments as $segment) {
            foreach (['views', 'uniques', 'ip_count', 'session_count', 'duration_sum', 'page_sum', 'bounce_count'] as $key) {
                $result[$key] += (int) ($segment[$key] ?? 0);
            }

            if (!empty($segment['has_data']) || (($segment['views'] ?? 0) + ($segment['session_count'] ?? 0) > 0)) {
                $result['has_data'] = true;
            }
        }

        return $result;
    }

    private function aggregateTotalsWithRollups(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $rollups = $this->aggregateRollups($siteId, $start, $end);

        if (!$rollups['has_data']) {
            return [
                'has_data' => false,
                'views' => 0,
                'uniques' => 0,
                'ip_count' => 0,
                'session_count' => 0,
                'duration_sum' => 0,
                'page_sum' => 0,
                'bounce_count' => 0,
            ];
        }

        return $rollups;
    }

    private function uncoveredRanges(?array $span, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if (!$span) {
            return [[$start, $end]];
        }

        $ranges = [];

        if ($start < $span['start']) {
            $ranges[] = [$start, $span['start']];
        }

        if ($span['end'] < $end) {
            $ranges[] = [$span['end'], $end];
        }

        return $ranges;
    }

    private function mergeDimensionRows(string $key, array ...$rowSets): array
    {
        $numeric = ['views', 'ips', 'uniques', 'sessions', 'duration_sum', 'page_sum', 'bounce_count'];
        $merged = [];

        foreach ($rowSets as $rows) {
            foreach ($rows as $row) {
                if (!isset($row[$key])) {
                    continue;
                }

                $label = $row[$key];
                if (!array_key_exists($label, $merged)) {
                    $merged[$label] = [$key => $label];
                }

                foreach ($numeric as $field) {
                    $merged[$label][$field] = ($merged[$label][$field] ?? 0) + (float) ($row[$field] ?? 0);
                }
            }
        }

        return array_values(array_map(function ($row) {
            $sessions = (float) ($row['sessions'] ?? 0);
            if ($sessions > 0) {
                $row['avg_pages'] = ($row['page_sum'] ?? 0) / $sessions;
                $row['avg_duration'] = ($row['duration_sum'] ?? 0) / $sessions;
                $row['bounce_rate'] = ($row['bounce_count'] ?? 0) / $sessions;
            }

            return $row;
        }, $merged));
    }

    private function normalizePathRows(array $rows): array
    {
        return array_map(function ($row) {
            return [
                'path' => $row['dimension_value'] ?? $row['path'] ?? '/',
                'views' => (int) ($row['views'] ?? 0),
                'ips' => (int) ($row['ips'] ?? 0),
            ];
        }, $rows);
    }

    private function normalizeEntryRows(array $rows): array
    {
        return array_map(function ($row) {
            $sessions = (int) ($row['sessions'] ?? ($row['views'] ?? 0));
            $avgPages = (float) ($row['avg_pages'] ?? 0);
            $avgDuration = (float) ($row['avg_duration'] ?? 0);
            $bounceRate = (float) ($row['bounce_rate'] ?? 0);

            $pageSum = $row['page_sum'] ?? ($sessions * $avgPages);
            $durationSum = $row['duration_sum'] ?? ($sessions * $avgDuration);
            $bounceCount = $row['bounce_count'] ?? ($sessions * $bounceRate);

            return [
                'path' => $row['dimension_value'] ?? $row['path'] ?? '/',
                'sessions' => $sessions,
                'views' => (int) ($row['views'] ?? 0),
                'ips' => (int) ($row['ips'] ?? 0),
                'uniques' => (int) ($row['uniques'] ?? 0),
                'page_sum' => $pageSum,
                'duration_sum' => $durationSum,
                'bounce_count' => $bounceCount,
                'avg_pages' => $avgPages,
                'avg_duration' => $avgDuration,
                'bounce_rate' => $bounceRate,
            ];
        }, $rows);
    }

    private function updateDimensionRollups(
        int $siteId,
        string $bucketKey,
        int $uvIncrement,
        int $ipIncrement,
        int $sessionIncrement,
        int $durationIncrement,
        int $pageIncrement,
        int $bounceIncrement,
        array $dimensions
    ): void {
        $entries = [];

        $path = $this->limitText($dimensions['path'] ?? '/', 512, '/');
        $keyword = $this->limitText($dimensions['keyword'] ?? '', 255);
        $engine = $this->limitText($dimensions['engine'] ?? '', 64);
        $referrerHost = $this->limitText($dimensions['referrer_host'] ?? '', 255);
        $browser = $this->limitText($dimensions['browser'] ?? '', 64);
        $isp = $this->limitText($dimensions['isp'] ?? '', 128);
        $isMobile = (bool) ($dimensions['is_mobile'] ?? false);
        $isUnique = (bool) ($dimensions['is_unique'] ?? false);
        $canonicalHost = $this->limitText($dimensions['canonical_host'] ?? '', 255, '未知域名');
        $audienceLabel = $this->limitText($dimensions['audience_label'] ?? 'returning', 64, 'returning');

        if ($keyword !== '') {
            $entries[] = ['keyword', $keyword];
            if ($engine !== '') {
                $entries[] = ['keyword_engine', $this->limitText($this->dimensionKey([$keyword, $engine, $path]), 255)];
            }
        }

        if ($engine !== '' && $engine !== '其他') {
            $entries[] = ['search_engine', $engine];
        }

        if ($referrerHost !== '') {
            $entries[] = ['referrer_host', $referrerHost];
        }

        if ($canonicalHost !== '') {
            $entries[] = ['host', $canonicalHost];
            $entries[] = [
                'host_device',
                $this->limitText($this->dimensionKey([$canonicalHost, $isMobile ? 'mobile' : 'desktop']), 255)
            ];
        }

        if (!empty($dimensions['entry_path'])) {
            $this->upsertEntryRollup(
                $siteId,
                $bucketKey,
                $this->limitText($dimensions['entry_path'], 512, '/'),
                $uvIncrement,
                $ipIncrement,
                $sessionIncrement,
                $durationIncrement,
                $pageIncrement,
                $bounceIncrement,
                $isUnique
            );
        }

        $this->upsertPageRollup(
            $siteId,
            $bucketKey,
            $path,
            $uvIncrement,
            $ipIncrement,
            $sessionIncrement,
            $durationIncrement,
            $pageIncrement,
            $bounceIncrement,
            $isUnique
        );

        $entries[] = ['device', $isMobile ? 'mobile' : 'desktop'];

        if ($browser !== '') {
            $entries[] = ['browser', $browser];
        }

        if (!empty($dimensions['region'])) {
            $regionLabel = $this->regionLabel(
                $dimensions['region']['country'] ?? '',
                $dimensions['region']['region'] ?? ''
            );
            $entries[] = ['region', $this->limitText($regionLabel, 255, '未知')];
            $country = $this->limitText($dimensions['region']['country'] ?? '', 128, '未知');
            $entries[] = ['country', $country];
        }

        if ($isp !== '') {
            $entries[] = ['isp', $isp];
        }

        $entries[] = ['audience', $audienceLabel];

        foreach ($entries as [$dimension, $value]) {
            $this->upsertDimensionRollup(
                $siteId,
                $bucketKey,
                $dimension,
                $value,
                $uvIncrement,
                $ipIncrement,
                $sessionIncrement,
                $durationIncrement,
                $pageIncrement,
                $bounceIncrement,
                $isUnique
            );
        }
    }

    private function upsertDimensionRollup(
        int $siteId,
        string $bucketKey,
        string $dimension,
        string $value,
        int $uvIncrement,
        int $ipIncrement,
        int $sessionIncrement,
        int $durationIncrement,
        int $pageIncrement,
        int $bounceIncrement,
        bool $isUnique
    ): void {
        $value = $this->limitText($value, 255);

        $stmt = $this->db->prepare(
            'INSERT INTO pageview_dimension_rollups (site_id, bucket_start, dimension_type, dimension_value, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
             VALUES (:site_id, :bucket_start, :dimension_type, :dimension_value, 1, :uv, :ip_count, :session_count, :duration_sum, :page_sum, :bounce_count)
             ON DUPLICATE KEY UPDATE
                pv = pv + 1,
                uv = uv + VALUES(uv),
                ip_count = ip_count + VALUES(ip_count),
                session_count = session_count + VALUES(session_count),
                duration_sum = duration_sum + VALUES(duration_sum),
                page_sum = page_sum + VALUES(page_sum),
                bounce_count = bounce_count + VALUES(bounce_count)'
        );

        $stmt->execute([
            ':site_id' => $siteId,
            ':bucket_start' => $bucketKey,
            ':dimension_type' => $dimension,
            ':dimension_value' => $value,
            ':uv' => $isUnique ? 1 : 0,
            ':ip_count' => $isUnique ? 1 : 0,
            ':session_count' => $sessionIncrement,
            ':duration_sum' => $durationIncrement,
            ':page_sum' => $pageIncrement,
            ':bounce_count' => $bounceIncrement,
        ]);
    }

    private function upsertPageRollup(
        int $siteId,
        string $bucketKey,
        string $path,
        int $uvIncrement,
        int $ipIncrement,
        int $sessionIncrement,
        int $durationIncrement,
        int $pageIncrement,
        int $bounceIncrement,
        bool $isUnique
    ): void {
        $path = $this->limitText($path ?: '/', 512, '/');

        $stmt = $this->db->prepare(
            'INSERT INTO pageview_page_rollups (site_id, bucket_start, path, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
             VALUES (:site_id, :bucket_start, :path, 1, :uv, :ip_count, :session_count, :duration_sum, :page_sum, :bounce_count)
             ON DUPLICATE KEY UPDATE
                pv = pv + 1,
                uv = uv + VALUES(uv),
                ip_count = ip_count + VALUES(ip_count),
                session_count = session_count + VALUES(session_count),
                duration_sum = duration_sum + VALUES(duration_sum),
                page_sum = page_sum + VALUES(page_sum),
                bounce_count = bounce_count + VALUES(bounce_count)'
        );

        $stmt->execute([
            ':site_id' => $siteId,
            ':bucket_start' => $bucketKey,
            ':path' => $path,
            ':uv' => $isUnique ? 1 : 0,
            ':ip_count' => $ipIncrement,
            ':session_count' => $sessionIncrement,
            ':duration_sum' => $durationIncrement,
            ':page_sum' => $pageIncrement,
            ':bounce_count' => $bounceIncrement,
        ]);
    }

    private function upsertEntryRollup(
        int $siteId,
        string $bucketKey,
        string $path,
        int $uvIncrement,
        int $ipIncrement,
        int $sessionIncrement,
        int $durationIncrement,
        int $pageIncrement,
        int $bounceIncrement,
        bool $isUnique
    ): void {
        $path = $this->limitText($path ?: '/', 512, '/');

        $stmt = $this->db->prepare(
            'INSERT INTO pageview_entry_rollups (site_id, bucket_start, path, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
             VALUES (:site_id, :bucket_start, :path, 1, :uv, :ip_count, :session_count, :duration_sum, :page_sum, :bounce_count)
             ON DUPLICATE KEY UPDATE
                pv = pv + 1,
                uv = uv + VALUES(uv),
                ip_count = ip_count + VALUES(ip_count),
                session_count = session_count + VALUES(session_count),
                duration_sum = duration_sum + VALUES(duration_sum),
                page_sum = page_sum + VALUES(page_sum),
                bounce_count = bounce_count + VALUES(bounce_count)'
        );

        $stmt->execute([
            ':site_id' => $siteId,
            ':bucket_start' => $bucketKey,
            ':path' => $path,
            ':uv' => $isUnique ? 1 : 0,
            ':ip_count' => $ipIncrement,
            ':session_count' => $sessionIncrement,
            ':duration_sum' => $durationIncrement,
            ':page_sum' => $pageIncrement,
            ':bounce_count' => $bounceIncrement,
        ]);
    }

    private function aggregateDimensionRollups(int $siteId, string $dimension, DateTimeImmutable $start, DateTimeImmutable $end, int $limit = 200): array
    {
        $span = $this->rollupSpanForRange($siteId, $start, $end);
        if (!$span) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT dimension_value, SUM(pv) as views, SUM(uv) as uniques, SUM(ip_count) as ips, SUM(session_count) as sessions, SUM(duration_sum) as duration_sum, SUM(page_sum) as page_sum, SUM(bounce_count) as bounce_count
             FROM pageview_dimension_rollups
             WHERE site_id = :site_id AND dimension_type = :dimension AND bucket_start >= :start AND bucket_start < :end
             GROUP BY dimension_value
             ORDER BY views DESC
             LIMIT :limit'
        );

        $statement->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $statement->bindValue(':dimension', $dimension, PDO::PARAM_STR);
        $statement->bindValue(':start', $start->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $statement->bindValue(':end', $end->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();

        return $this->recalcRollupIps([$siteId], $dimension, $span['start'], $span['end'], $rows);
    }

    private function aggregatePageRollups(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end, int $limit = 200): array
    {
        $span = $this->rollupSpanForRange($siteId, $start, $end);
        if (!$span) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT path as dimension_value, SUM(pv) as views, SUM(uv) as uniques, SUM(ip_count) as ips, SUM(session_count) as sessions, SUM(duration_sum) as duration_sum, SUM(page_sum) as page_sum, SUM(bounce_count) as bounce_count
             FROM pageview_page_rollups
             WHERE site_id = :site_id AND bucket_start >= :start AND bucket_start < :end
             GROUP BY path
             ORDER BY ips DESC
             LIMIT :limit'
        );

        $statement->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $statement->bindValue(':start', $start->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $statement->bindValue(':end', $end->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();

        $span = $span ?? ['start' => $start, 'end' => $end];

        return $this->recalcRollupIps([$siteId], 'page_path', $span['start'], $span['end'], $rows);
    }

    private function aggregateEntryRollups(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end, int $limit = 200): array
    {
        $span = $this->rollupSpanForRange($siteId, $start, $end);
        if (!$span) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT path as dimension_value, SUM(pv) as views, SUM(uv) as uniques, SUM(ip_count) as ips, SUM(session_count) as sessions, SUM(duration_sum) as duration_sum, SUM(page_sum) as page_sum, SUM(bounce_count) as bounce_count
             FROM pageview_entry_rollups
             WHERE site_id = :site_id AND bucket_start >= :start AND bucket_start < :end
             GROUP BY path
             ORDER BY ips DESC
             LIMIT :limit'
        );

        $statement->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $statement->bindValue(':start', $start->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $statement->bindValue(':end', $end->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();

        $span = $span ?? ['start' => $start, 'end' => $end];

        return $this->recalcEntryRollupIps($siteId, $span['start'], $span['end'], $rows);
    }

private function aggregateDimensionRollupsForSites(array $siteIds, string $dimension, DateTimeImmutable $start, DateTimeImmutable $end, int $limit = 200): array
    {
        if (empty($siteIds)) {
            return [];
        }

        $placeholders = [];
        $bindings = [
            ':dimension' => $dimension,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
            ':limit' => $limit,
        ];

        foreach (array_values($siteIds) as $idx => $siteId) {
            $ph = ':sid' . $idx;
            $placeholders[] = $ph;
            $bindings[$ph] = (int) $siteId;
        }

        $sql = sprintf(
            'SELECT dimension_value, SUM(pv) as views, SUM(uv) as uniques, SUM(ip_count) as ips, SUM(session_count) as sessions, SUM(duration_sum) as duration_sum, SUM(page_sum) as page_sum, SUM(bounce_count) as bounce_count
             FROM pageview_dimension_rollups
             WHERE site_id IN (%s) AND dimension_type = :dimension AND bucket_start >= :start AND bucket_start < :end
             GROUP BY dimension_value
             ORDER BY views DESC
             LIMIT :limit',
            implode(',', $placeholders)
        );

        $statement = $this->db->prepare($sql);
        foreach ($bindings as $key => $value) {
            $paramType = (str_starts_with($key, ':sid') || $key === ':limit') ? PDO::PARAM_INT : PDO::PARAM_STR;
            $statement->bindValue($key, $value, $paramType);
        }

        $statement->execute();
        $rows = $statement->fetchAll();

        return $this->recalcRollupIps($siteIds, $dimension, $start, $end, $rows);
    }

    private function recalcRollupIps(?array $siteIds, string $dimension, DateTimeImmutable $start, DateTimeImmutable $end, array $rows): array
    {
        return array_map(function ($row) {
            $row['ips'] = (int) ($row['ips'] ?? ($row['ip_count'] ?? 0));
            $row['uniques'] = (int) ($row['uniques'] ?? ($row['uv'] ?? 0));

            return $row;
        }, $rows);
    }

    private function recalcEntryRollupIps(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end, array $rows): array
    {
        return array_map(function ($row) {
            $row['ips'] = (int) ($row['ips'] ?? ($row['ip_count'] ?? 0));
            $row['uniques'] = (int) ($row['uniques'] ?? ($row['uv'] ?? 0));

            return $row;
        }, $rows);
    }

    private function markIpAudienceState(int $siteId, string $ipHash): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO site_ip_audience (site_id, ip_hash, first_seen, last_seen_date)
             VALUES (:site_id, :ip_hash, NOW(), CURRENT_DATE())
             ON DUPLICATE KEY UPDATE last_seen_date = VALUES(last_seen_date)'
        );

        $stmt->execute([
            ':site_id' => $siteId,
            ':ip_hash' => $ipHash,
        ]);

        $affected = (int) $stmt->rowCount();
        $uvToday = $affected > 0;
        $audienceLabel = ($affected === 1) ? 'new' : 'returning';

        return [$uvToday, $audienceLabel];
    }

    private function detectBrowser(string $userAgent): string
    {
        $ua = strtolower($userAgent);
        return match (true) {
            str_contains($ua, 'micromessenger') => 'WeChat',
            (bool) preg_match('/bytedancewebview|aweme/', $ua) => 'Douyin',
            str_contains($ua, 'baiduboxapp') => 'Baidu',
            (bool) preg_match('/mqqbrowser|qqbrowser/', $ua) => 'QQ',
            str_contains($ua, 'ucbrowser') => 'UC',
            str_contains($ua, 'quark') => 'Quark',
            str_contains($ua, 'xiaomi') || str_contains($ua, 'miuibrowser') => 'Mi',
            str_contains($ua, 'huawei') => 'Huawei',
            str_contains($ua, 'vivobrowser') => 'Vivo',
            str_contains($ua, 'heytapbrowser') || str_contains($ua, 'oppobrowser') => 'OPPO',
            (bool) preg_match('/edg(a|ios)/', $ua) => 'Edge',
            (bool) preg_match('/chrome|crios/', $ua) => 'Chrome',
            (bool) preg_match('/firefox|fxios/', $ua) => 'Firefox',
            (bool) preg_match('/safari/', $ua) && !(bool) preg_match('/chrome|crios|edg/', $ua) => 'Safari',
            (bool) preg_match('/360se|360ee/', $ua) => '360',
            (bool) preg_match('/msie|trident/', $ua) => 'IE',
            default => '其他浏览器',
        };
    }

    private function browserCase(string $alias = ''): string
    {
        $prefix = $alias ? $alias . '.' : '';

        return "CASE
            WHEN LOWER({$prefix}user_agent) REGEXP 'micromessenger' THEN 'WeChat'
            WHEN LOWER({$prefix}user_agent) REGEXP 'bytedancewebview|aweme' THEN 'Douyin'
            WHEN LOWER({$prefix}user_agent) REGEXP 'baiduboxapp' THEN 'Baidu'
            WHEN LOWER({$prefix}user_agent) REGEXP 'mqqbrowser|qqbrowser' THEN 'QQ'
            WHEN LOWER({$prefix}user_agent) REGEXP 'ucbrowser' THEN 'UC'
            WHEN LOWER({$prefix}user_agent) REGEXP 'quark' THEN 'Quark'
            WHEN LOWER({$prefix}user_agent) REGEXP 'xiaomi|miuibrowser' THEN 'Mi'
            WHEN LOWER({$prefix}user_agent) REGEXP 'huawei' THEN 'Huawei'
            WHEN LOWER({$prefix}user_agent) REGEXP 'vivobrowser' THEN 'Vivo'
            WHEN LOWER({$prefix}user_agent) REGEXP 'heytapbrowser|oppobrowser' THEN 'OPPO'
            WHEN LOWER({$prefix}user_agent) REGEXP 'edg(a|ios)' THEN 'Edge'
            WHEN LOWER({$prefix}user_agent) REGEXP 'chrome|crios' THEN 'Chrome'
            WHEN LOWER({$prefix}user_agent) REGEXP 'firefox|fxios' THEN 'Firefox'
            WHEN LOWER({$prefix}user_agent) REGEXP 'safari' AND LOWER({$prefix}user_agent) NOT REGEXP 'chrome|crios|edg' THEN 'Safari'
            WHEN LOWER({$prefix}user_agent) REGEXP '360se|360ee' THEN '360'
            WHEN LOWER({$prefix}user_agent) REGEXP 'msie|trident' THEN 'IE'
            ELSE '其他浏览器'
        END";
    }

    private function detectSearchEngine(string $referrer, string $userAgent): string
    {
        $ref = strtolower($referrer);
        $ua = strtolower($userAgent);

        return match (true) {
            str_contains($ref, 'baidu.com') || str_contains($ua, 'baiduspider') => '百度',
            str_contains($ref, 'google') || str_contains($ua, 'googlebot') => '谷歌',
            str_contains($ref, 'bing.com') || str_contains($ua, 'bingbot') => '必应',
            str_contains($ref, 'so.com') || str_contains($ua, '360spider') => '360',
            str_contains($ref, 'toutiao.com') || str_contains($ua, 'bytespider') => '头条',
            str_contains($ref, 'sogou.com') || str_contains($ua, 'sogou') => '搜狗',
            str_contains($ref, 'sm.cn') || str_contains($ua, 'yisouspider') => '神马',
            str_contains($ua, 'petalbot') => '华为',
            default => '其他',
        };
    }

    private function referrerHost(?string $referrer): ?string
    {
        if (!$referrer) {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST) ?: '';
        return $host ? strtolower($host) : null;
    }

    private function captureEntryPath(int $siteId, ?string $sessionId, string $path): ?string
    {
        if (!$sessionId) {
            return null;
        }

        $key = sprintf('entry:first:%d:%s', $siteId, $sessionId);
        if ($this->redis->setnx($key, $path ?: '/')) {
            $this->redis->expire($key, 172800);
            return $path ?: '/';
        }

        return null;
    }

    private function regionLabel(string $country, string $region): string
    {
        $country = trim($country);
        $region = trim($region);

        if ($country === '' || $country === '未知' || $country === '保留地址') {
            return '未知';
        }

        if (str_starts_with($country, '中国')) {
            return $region !== '' ? $region : '未知';
        }

        return $country;
    }

    private function dimensionKey(array $parts): string
    {
        return implode('|', array_map(fn ($p) => str_replace('|', '/', (string) $p), $parts));
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
        if (!$this->rollupsCoverRange($siteId, $start, $end)) {
            return [];
        }

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

    private function getRollupHourlyStats(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end, bool $allowPartial = false): array
    {
        if (!$allowPartial && !$this->rollupsCoverRange($siteId, $start, $end)) {
            return [];
        }

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

    private function ensureRollupCoverage(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        $span = $this->rollupSpanForRange($siteId, $start, $end);
        if (!$span || $span['start'] > $start || $span['end'] < $end) {
            $this->rebuildRollupRange($siteId, $start, $end);
        }
    }

    public function getOverview(int $siteId, string $range = 'today'): array
    {
        $regionRows = $this->getRegionStats($siteId, $range, 200);

        $overview = [
            'totals' => $this->getTotals($siteId, $range),
            'daily' => $this->getDailyStats($siteId, $range),
            'hourly' => $this->getHourlyStats($siteId, $range),
            'trend' => $this->getTrendLines($siteId, $range),
            'predictions' => $this->getPredictions($siteId),
            'regions' => array_slice($regionRows, 0, 20),
            'china_map' => $regionRows,
            'devices' => $this->getDeviceBreakdown($siteId, $range),
            'browsers' => $this->getBrowserBreakdown($siteId, $range, 6),
            'new_vs_returning' => $this->getNewVsReturning($siteId, $range),
            'top_referrers' => $this->getTopReferrers($siteId, $range),
            'top_pages' => $this->getTopPages($siteId, $range, 10),
            'entry_pages' => $this->getEntryPages($siteId, $range, 15),
        ];

        if ($range === 'today') {
            $overview['yesterday_totals'] = $this->getTotals($siteId, 'yesterday');
        }

        return $overview;
    }

    public function getContentData(int $siteId, string $range = 'today', array $filters = [], int $page = 1, int $perPage = 50): array
    {
        return [
            'active' => $this->getActiveSessions($siteId),
            'details' => $this->getVisitDetails($siteId, $filters, $page, $perPage),
            'total_sessions' => $this->getVisitDetailCount($siteId, $filters),
        ];
    }

    public function getKeywordData(int $siteId, string $range = 'today', ?string $domain = null): array
    {
        return [
            'keywords' => $this->getKeywords($siteId, $range),
            'engine_counts' => $this->getKeywordEngines($siteId, $range, $domain),
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

    public function getBotData(int $siteId, string $range = 'today', ?string $engine = null, ?string $domain = null, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $total = $this->getBotTrafficCount($siteId, $range, $engine, $domain);

        return [
            'bot' => $this->getBotTraffic($siteId, $range, $engine, $domain, $page, $perPage),
            'total' => $total['total'] ?? 0,
            'engines' => $this->getBotEngines($siteId, $range, $domain),
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
        $cacheKey = "trend_lines:{$siteId}:{$range}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range) {
            return $this->buildTrendLines($siteId, $range);
        });
    }

    private function buildTrendLines(int $siteId, string $range = 'today'): array
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

        if ($range === 'day_before') {
            $granularity = 'hour';
            $primaryLabel = '前天';
            $compareLabel = '大前天';

            $dayBeforeStart = $now->modify('-2 day')->setTime(0, 0);
            $primary = $this->normalizeHourlySeries($this->getHourlyStats($siteId, 'day_before'), $dayBeforeStart);
            $compare = $this->normalizeHourlySeries(
                $this->getHourlyStatsForWindow($siteId, $dayBeforeStart->modify('-1 day'), $dayBeforeStart),
                $dayBeforeStart->modify('-1 day')
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

    public function getTotals(int $siteId, string $range): array
    {
        $cacheKey = "totals:{$siteId}:{$range}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $rollupTotals = $this->aggregateTotalsWithRollups($siteId, $start, $end);

            return [
                'views' => $rollupTotals['views'],
                'uniques' => $rollupTotals['uniques'],
                'ip_count' => $rollupTotals['ip_count'],
                'averages' => $this->getVisitAverages($siteId, $range, $rollupTotals),
                'bounce_rate' => $this->getBounceRate($siteId, $range, $rollupTotals),
            ];
        });
    }

    public function getDailyStats(int $siteId, string $range): array
    {
        $cacheKey = "daily:{$siteId}:{$range}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range) {
            [$start, $end] = $this->rollupRangeBounds($range);
            return $this->getRollupDailyStats($siteId, $start, $end);
        });
    }

    public function getTopPages(int $siteId, string $range = 'today', int $limit = 50): array
    {
        $cacheKey = "top_pages:{$siteId}:{$range}:{$limit}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $limit) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if (!$span) {
                return [];
            }

            $rows = $this->normalizePathRows($this->aggregatePageRollups($siteId, $span['start'], $span['end'], $limit * 3));

            usort($rows, fn($a, $b) => ($b['ips'] ?? 0) <=> ($a['ips'] ?? 0));

            return array_slice($rows, 0, $limit);
        });
    }

    public function getTopReferrers(int $siteId, string $range): array
    {
        $cacheKey = "top_referrers:{$siteId}:{$range}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if (!$span) {
                return [];
            }

            $rows = $this->aggregateDimensionRollups($siteId, 'referrer_host', $span['start'], $span['end'], 50);
            $domains = $this->getAllSiteDomains($siteId);

            if ($domains) {
                $rows = array_values(array_filter($rows, function ($row) use ($domains) {
                    return !$this->isOwnReferrer($row['dimension_value'] ?? '', $domains);
                }));
            }

            return array_map(function ($row) {
                return [
                    'referrer' => $row['dimension_value'] ?? '',
                    'views' => (int) ($row['views'] ?? 0),
                    'ips' => (int) ($row['ips'] ?? 0),
                ];
            }, $rows);
        });
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

    public function getEntryPages(int $siteId, string $range, int $limit = 20): array
    {
        $cacheKey = "entry_pages:{$siteId}:{$range}:{$limit}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $limit) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if (!$span) {
                return [];
            }

            $rows = $this->normalizeEntryRows(
                $this->aggregateEntryRollups($siteId, $span['start'], $span['end'], $limit * 3)
            );

            usort($rows, fn($a, $b) => ($b['ips'] ?? 0) <=> ($a['ips'] ?? 0));

            return array_slice($rows, 0, $limit);
        });
    }

    private function getEntrySummary(int $siteId, string $range): array
    {
        [$rollupStart, $rollupEnd] = $this->rollupRangeBounds($range);
        $summary = $this->rollupsCoverRange($siteId, $rollupStart, $rollupEnd)
            ? $this->rollupSummaryStats($this->aggregateRollups($siteId, $rollupStart, $rollupEnd))
            : [];

        $rows = $this->getEntryRollupRows($siteId, $range, 200);

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

    private function getEntryRollupRows(int $siteId, string $range, int $limit = 200): array
    {
        [$start, $end] = $this->rollupRangeBounds($range);
        $rows = $this->aggregateEntryRollups($siteId, $start, $end, $limit);

        if (empty($rows)) {
            return [];
        }

        return array_map(function ($row) {
            $sessions = (int) ($row['sessions'] ?? 0);
            $avgPages = $sessions > 0 ? (float) ($row['page_sum'] ?? 0) / $sessions : 0;
            $avgDuration = $sessions > 0 ? (float) ($row['duration_sum'] ?? 0) / $sessions : 0;
            $bounceRate = $sessions > 0 ? (float) ($row['bounce_count'] ?? 0) / $sessions : 0;

            return [
                'path' => $row['dimension_value'] ?? '/',
                'sessions' => $sessions,
                'ips' => (int) ($row['ips'] ?? 0),
                'uniques' => (int) ($row['uniques'] ?? 0),
                'views' => (int) ($row['views'] ?? 0),
                'avg_pages' => $avgPages,
                'avg_duration' => $avgDuration,
                'bounce_rate' => $bounceRate,
            ];
        }, $rows);
    }

    private function getPageSummary(int $siteId, string $range): array
    {
        [$rollupStart, $rollupEnd] = $this->rollupRangeBounds($range);
        $summaryTotals = $this->aggregateTotalsWithRollups($siteId, $rollupStart, $rollupEnd);
        $summary = $this->rollupSummaryStats($summaryTotals);

        $rollupRows = $this->getPageRollupRows($siteId, $range, 200);

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
            'rows' => $rollupRows,
        ];
    }

    private function getPageRollupRows(int $siteId, string $range, int $limit = 200): array
    {
        [$start, $end] = $this->rollupRangeBounds($range);
        $rows = $this->aggregatePageRollups($siteId, $start, $end, $limit);

        if (empty($rows)) {
            return [];
        }

        return array_map(function ($row) {
            $sessions = (int) ($row['sessions'] ?? 0);
            $avgPages = $sessions > 0 ? (float) ($row['page_sum'] ?? 0) / $sessions : 0;
            $avgDuration = $sessions > 0 ? (float) ($row['duration_sum'] ?? 0) / $sessions : 0;
            $bounceRate = $sessions > 0 ? (float) ($row['bounce_count'] ?? 0) / $sessions : 0;

            return [
                'path' => $row['dimension_value'] ?? '/',
                'views' => (int) ($row['views'] ?? 0),
                'ips' => (int) ($row['ips'] ?? 0),
                'uniques' => (int) ($row['uniques'] ?? 0),
                'avg_pages' => $avgPages,
                'avg_duration' => $avgDuration,
                'bounce_rate' => $bounceRate,
            ];
        }, $rows);
    }

    private function getReferrerSummary(int $siteId, string $range, array $filters = []): array
    {
        $domains = $this->getAllSiteDomains($siteId);
        [$start, $end] = $this->rollupRangeBounds($range);
        $span = $this->rollupSpanForRange($siteId, $start, $end);
        if (!$span) {
            return [
                'summary' => [
                    'ips' => 0,
                    'views' => 0,
                    'uv' => 0,
                    'new' => 0,
                    'sessions' => 0,
                    'avg_pages' => 0,
                    'avg_duration' => 0,
                    'bounce_rate' => 0,
                ],
                'rows' => [],
            ];
        }

        $rows = $this->aggregateDimensionRollups($siteId, 'referrer_host', $span['start'], $span['end'], 200);
        $filtered = [];
        foreach ($rows as $row) {
            $referrer = $row['dimension_value'] ?? '';
            if ($referrer === '' || $referrer === '直接访问') {
                continue;
            }
            if ($domains && $this->isOwnReferrer($referrer, $domains)) {
                continue;
            }

            $sessions = (int) ($row['sessions'] ?? 0);
            $avgPages = $sessions > 0 ? (float) ($row['page_sum'] ?? 0) / $sessions : 0;
            $avgDuration = $sessions > 0 ? (float) ($row['duration_sum'] ?? 0) / $sessions : 0;
            $bounceRate = $sessions > 0 ? (float) ($row['bounce_count'] ?? 0) / $sessions : 0;

            $filtered[] = [
                'referrer' => $referrer,
                'sessions' => $sessions,
                'ips' => (int) ($row['ips'] ?? 0),
                'uniques' => (int) ($row['uniques'] ?? 0),
                'views' => (int) ($row['views'] ?? 0),
                'avg_pages' => $avgPages,
                'avg_duration' => $avgDuration,
                'bounce_rate' => $bounceRate,
            ];
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
        if ($this->rollupOnly) {
            return [];
        }

        [$rangeSql, $params] = $this->rangeClause($range);
        $statement = $this->db->prepare(
            "SELECT path, referrer, user_agent, occurred_at
            FROM pageviews
            WHERE site_id = :site_id {$rangeSql}
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
                 WHERE site_id = :site_id AND session_id IS NOT NULL
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
                        WHERE site_id = :site_id AND session_id IS NOT NULL
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
                    WHERE site_id = :site_id AND session_id IS NOT NULL
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
        $cacheKey = "keywords:{$siteId}:{$range}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if (!$span) {
                return [];
            }

            $rollup = $this->aggregateDimensionRollups($siteId, 'keyword_engine', $span['start'], $span['end'], 5000);

            if (!empty($rollup)) {
                $keywords = [];

                foreach ($rollup as $row) {
                    [$keyword, $engine, $entry] = array_pad(explode('|', $row['dimension_value'] ?? '', 3), 3, '');
                    if ($keyword === '') {
                        continue;
                    }
                    $entryLabel = $entry !== '' ? $entry : '/';
                    if (!isset($keywords[$keyword])) {
                        $keywords[$keyword] = [
                            'keyword' => $keyword,
                            'views' => 0,
                            'engines' => [],
                            'entry' => $entryLabel,
                        ];
                    }

                    $keywords[$keyword]['views'] += (int) ($row['views'] ?? 0);
                    $keywords[$keyword]['engines'][] = $engine ?: '其他';

                    if (($row['views'] ?? 0) >= ($keywords[$keyword]['views'] ?? 0)) {
                        $keywords[$keyword]['entry'] = $entryLabel;
                    }
                }

                return array_values(array_map(function ($item) {
                    $item['engines'] = implode(' / ', array_unique($item['engines']));
                    return $item;
                }, $keywords));
            }
            return [];
        });
    }

    private function getKeywordEngines(int $siteId, string $range, ?string $domain = null): array
    {
        $cacheKey = "keyword_engines:{$siteId}:{$range}:" . ($domain ?? 'all');

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $domain) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if (!$span) {
                return [];
            }

            $rollup = $this->aggregateDimensionRollups($siteId, 'keyword_engine', $span['start'], $span['end'], 5000);
            if (empty($rollup)) {
                return [];
            }

            $engineKeywords = [];
            foreach ($rollup as $row) {
                [$keyword, $engine, $entry] = array_pad(explode('|', $row['dimension_value'] ?? '', 3), 3, '');
                if ($keyword === '') {
                    continue;
                }
                if ($domain !== null && $domain !== '' && $domain !== 'all') {
                    $entry = ltrim((string) $entry, '/');
                    $entryHost = strtolower(explode('/', $entry)[0] ?? '');
                    if ($entryHost !== strtolower($domain)) {
                        continue;
                    }
                }
                $engineLabel = $engine !== '' ? $engine : '其他';
                $engineKeywords[$engineLabel][$keyword] = true;
            }

            $results = [];
            foreach ($engineKeywords as $engineLabel => $keywords) {
                $count = count($keywords);
                if ($count === 0) {
                    continue;
                }
                $results[] = [
                    'engine' => $engineLabel,
                    'total' => $count,
                ];
            }

            usort($results, static fn(array $a, array $b) => $b['total'] <=> $a['total']);

            return $results;
        });
    }

    private function getBotTraffic(int $siteId, string $range, ?string $engine = null, ?string $domain = null, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $cacheKey = "bots:{$siteId}:{$range}:" . ($engine ?? 'all') . ':' . ($domain ?? 'all') . ":{$page}:{$perPage}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $engine, $domain, $page, $perPage) {
            [$rangeSql, $params] = $this->rangeClause($range);
            $engineFilter = '';
            $domainFilter = '';

            if ($engine !== null && $engine !== '' && $engine !== 'all') {
                $engineFilter = " AND engine = :engine";
                $params[':engine'] = $engine;
            }
            if ($domain !== null && $domain !== '' && $domain !== 'all') {
                $domainFilter = " AND domain = :domain";
                $params[':domain'] = $domain;
            }

            $limit = $perPage;
            $offset = ($page - 1) * $perPage;
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;

            $statement = $this->db->prepare(
                "SELECT path, referrer, user_agent, ip_address,
                    domain, occurred_at, engine
                FROM pageview_bot_logs
                WHERE site_id = :site_id {$rangeSql}{$engineFilter}{$domainFilter}
                ORDER BY occurred_at DESC
                LIMIT :limit OFFSET :offset"
            );
            $statement->execute(array_merge([':site_id' => $siteId], $params));

            return $statement->fetchAll();
        });
    }

    private function getBotTrafficCount(int $siteId, string $range, ?string $engine = null, ?string $domain = null): array
    {
        $cacheKey = "bots_total:{$siteId}:{$range}:" . ($engine ?? 'all') . ':' . ($domain ?? 'all');

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $engine, $domain) {
            [$rangeSql, $params] = $this->rangeClause($range);
            $engineFilter = '';
            $domainFilter = '';

            if ($engine !== null && $engine !== '' && $engine !== 'all') {
                $engineFilter = " AND engine = :engine";
                $params[':engine'] = $engine;
            }
            if ($domain !== null && $domain !== '' && $domain !== 'all') {
                $domainFilter = " AND domain = :domain";
                $params[':domain'] = $domain;
            }

            $statement = $this->db->prepare(
                "SELECT COUNT(*) FROM pageview_bot_logs
                WHERE site_id = :site_id {$rangeSql}{$engineFilter}{$domainFilter}"
            );
            $statement->execute(array_merge([':site_id' => $siteId], $params));

            return [
                'total' => (int) $statement->fetchColumn(),
            ];
        });
    }

    private function getBotEngines(int $siteId, string $range, ?string $domain = null): array
    {
        $cacheKey = "bot_engines:{$siteId}:{$range}:" . ($domain ?? 'all');

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $domain) {
            [$rangeSql, $params] = $this->rangeClause($range);
            $domainFilter = '';
            if ($domain !== null && $domain !== '' && $domain !== 'all') {
                $domainFilter = ' AND domain = :domain';
                $params[':domain'] = $domain;
            }
            $statement = $this->db->prepare(
                "SELECT engine, COUNT(*) as total
                FROM pageview_bot_logs
                WHERE site_id = :site_id {$rangeSql}{$domainFilter}
                GROUP BY engine
                ORDER BY total DESC"
            );

            $statement->execute(array_merge([':site_id' => $siteId], $params));

            return $statement->fetchAll();
        });
    }

    private function getVisitAverages(int $siteId, string $range, array $rollupTotals = []): array
    {
        if (!empty($rollupTotals['has_data']) && ($rollupTotals['session_count'] ?? 0) > 0) {
            $sessions = max(1, (int) $rollupTotals['session_count']);
            $uniques = max(0, (int) ($rollupTotals['uniques'] ?? 0));
            $views = max(0, (int) ($rollupTotals['views'] ?? 0));

            return [
                'duration' => round(((float) ($rollupTotals['duration_sum'] ?? 0)) / $sessions, 2),
                'pages' => $uniques > 0 ? round($views / $uniques, 2) : 0.0,
            ];
        }
        return [
            'duration' => 0.0,
            'pages' => 0.0,
        ];
    }

    private function getBounceRate(int $siteId, string $range, array $rollupTotals = []): float
    {
        if (!empty($rollupTotals['has_data']) && ($rollupTotals['session_count'] ?? 0) > 0) {
            $sessions = max(1, (int) $rollupTotals['session_count']);
            $bounces = (float) ($rollupTotals['bounce_count'] ?? 0);

            return $bounces / $sessions;
        }
        return 0.0;
    }

    public function getPredictions(int $siteId): array
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
        $todayStart = (new DateTimeImmutable('today'))->setTime(0, 0, 0);
        $historyStart = $todayStart->modify("-{$days} days");

        $span = $this->rollupSpanForRange($siteId, $historyStart, $todayStart);
        if (!$span) {
            return ['views' => 0, 'uniques' => 0, 'ips' => 0];
        }

        $stmt = $this->db->prepare(
            'SELECT DATE(bucket_start) as day, SUM(pv) as views, SUM(uv) as uniques, SUM(ip_count) as ips
             FROM pageview_rollups
             WHERE site_id = :site_id AND bucket_start >= :start AND bucket_start < :end
             GROUP BY day
             ORDER BY day DESC'
        );

        $stmt->execute([
            ':site_id' => $siteId,
            ':start' => $span['start']->format('Y-m-d H:i:s'),
            ':end' => $span['end']->format('Y-m-d H:i:s'),
        ]);

        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return ['views' => 0, 'uniques' => 0, 'ips' => 0];
        }

        $denominator = max(min(count($rows), $days), 1);

        return [
            'views' => (int) round(array_sum(array_column($rows, 'views')) / $denominator),
            'uniques' => (int) round(array_sum(array_column($rows, 'uniques')) / $denominator),
            'ips' => (int) round(array_sum(array_column($rows, 'ips')) / $denominator),
        ];
    }

    private function getRangeStats(int $siteId, DateTimeInterface $start, DateTimeInterface $end): array
    {
        $totals = $this->aggregateTotalsWithRollups(
            $siteId,
            new DateTimeImmutable($start->format('Y-m-d H:i:s')),
            new DateTimeImmutable($end->format('Y-m-d H:i:s'))
        );

        return [
            'views' => (int) ($totals['views'] ?? 0),
            'ips' => (int) ($totals['ip_count'] ?? 0),
            'uniques' => (int) ($totals['uniques'] ?? 0),
        ];
    }

    private function projectDayMetric(int $today, int $yesterdayFull, int $yesterdayPartial, int $average): int
    {
        $baseline = $yesterdayFull > 0 ? $yesterdayFull : ($average > 0 ? $average : $today);
        $pace = $yesterdayPartial > 0 ? max(0.1, $today / $yesterdayPartial) : 1.0;
        $estimate = (int) round($baseline * $pace);

        return max($today, $estimate);
    }

    private function buildFallbackUid(?string $ip, string $userAgent, array $headerMeta): string
    {
        if (!$ip) {
            return '';
        }

        $parts = [
            $ip,
            strtolower(trim($userAgent)),
            strtolower(trim((string) ($headerMeta['language'] ?? ''))),
            strtolower(trim((string) ($headerMeta['showp'] ?? ''))),
            strtolower(trim((string) ($headerMeta['accept_language'] ?? ''))),
            strtolower(trim((string) ($headerMeta['accept_encoding'] ?? ''))),
            strtolower(trim((string) ($headerMeta['sec_ch_ua'] ?? ''))),
            strtolower(trim((string) ($headerMeta['sec_ch_ua_mobile'] ?? ''))),
            strtolower(trim((string) ($headerMeta['sec_ch_ua_platform'] ?? ''))),
            strtolower(trim((string) ($headerMeta['sec_fetch_site'] ?? ''))),
            strtolower(trim((string) ($headerMeta['sec_fetch_mode'] ?? ''))),
            strtolower(trim((string) ($headerMeta['sec_fetch_dest'] ?? ''))),
        ];

        return hash('sha256', implode('|', $parts));
    }

    private function headerIntegrityScore(string $userAgent, array $headerMeta): int
    {
        $ua = strtolower(trim($userAgent));
        if ($ua === '') {
            return 6;
        }

        $acceptLanguage = strtolower(trim((string) ($headerMeta['accept_language'] ?? '')));
        $acceptEncoding = strtolower(trim((string) ($headerMeta['accept_encoding'] ?? '')));
        $secChUa = strtolower(trim((string) ($headerMeta['sec_ch_ua'] ?? '')));
        $secChUaMobile = strtolower(trim((string) ($headerMeta['sec_ch_ua_mobile'] ?? '')));
        $secChUaPlatform = strtolower(trim((string) ($headerMeta['sec_ch_ua_platform'] ?? '')));
        $secFetchSite = strtolower(trim((string) ($headerMeta['sec_fetch_site'] ?? '')));
        $secFetchMode = strtolower(trim((string) ($headerMeta['sec_fetch_mode'] ?? '')));
        $secFetchDest = strtolower(trim((string) ($headerMeta['sec_fetch_dest'] ?? '')));
        $language = strtolower(trim((string) ($headerMeta['language'] ?? '')));
        $showp = strtolower(trim((string) ($headerMeta['showp'] ?? '')));

        $score = 0;
        $looksBrowser = (bool) preg_match('/chrome|crios|safari|firefox|fxios|edg|opera|webkit/', $ua);

        if ($language === '') {
            $score += 1;
        }
        if ($showp === '') {
            $score += 2;
        } elseif (!$this->isValidScreenResolution($showp)) {
            $score += 2;
        }

        if ($acceptLanguage === '') {
            $score += 2;
        }
        if ($acceptEncoding === '') {
            $score += 2;
        }
        if ($acceptLanguage !== '' && strlen($acceptLanguage) < 5) {
            $score += 1;
        }

        $isChromeFamily = $looksBrowser && (str_contains($ua, 'chrome') || str_contains($ua, 'edg') || str_contains($ua, 'opera'));
        if ($isChromeFamily) {
            if ($secChUa === '') {
                $score += 3;
            }
            if ($secFetchSite === '' || $secFetchMode === '' || $secFetchDest === '') {
                $score += 3;
            }
            if ($showp !== '' && ($secChUa === '' || $secFetchSite === '' || $secFetchMode === '' || $secFetchDest === '')) {
                $score += 2;
            }
        }

        if ($looksBrowser && str_contains($ua, 'safari') && !str_contains($ua, 'chrome')) {
            if ($secChUa !== '') {
                $score += 2;
            }
        }

        if (!$looksBrowser && ($secChUa !== '' || $secFetchSite !== '' || $secFetchMode !== '' || $secFetchDest !== '')) {
            $score += 2;
        }

        if ($secFetchMode !== '' && !in_array($secFetchMode, ['navigate', 'cors', 'no-cors', 'same-origin', 'websocket'], true)) {
            $score += 2;
        }

        if ($secChUaMobile !== '' && !in_array($secChUaMobile, ['?0', '?1'], true)) {
            $score += 1;
        }

        if ($secChUaPlatform !== '' && strlen($secChUaPlatform) > 20) {
            $score += 1;
        }

        return min(10, $score);
    }

    private function clientTimeDriftScore(array $headerMeta, int $serverEpoch): int
    {
        $ntimeRaw = trim((string) ($headerMeta['ntime'] ?? ''));
        if ($ntimeRaw === '' || !ctype_digit($ntimeRaw)) {
            return 0;
        }

        $clientEpoch = (int) $ntimeRaw;
        if ($clientEpoch <= 0) {
            return 0;
        }

        $drift = abs($serverEpoch - $clientEpoch);
        if ($drift >= 86400) {
            return 6;
        }
        if ($drift >= 21600) {
            return 4;
        }
        if ($drift >= 3600) {
            return 2;
        }

        return 0;
    }

    private function isValidScreenResolution(string $showp): bool
    {
        if (!preg_match('/^([1-9]\d{1,4})x([1-9]\d{1,4})$/', $showp, $matches)) {
            return false;
        }

        $width = (int) $matches[1];
        $height = (int) $matches[2];
        if ($width < 200 || $height < 200) {
            return false;
        }

        return $width <= 10000 && $height <= 10000;
    }

    private function isDataCenterAsn(array $asnMeta): bool
    {
        $asnName = strtolower(trim((string) ($asnMeta['name'] ?? '')));
        $ispName = strtolower(trim((string) ($asnMeta['isp'] ?? '')));
        $combined = trim($asnName . ' ' . $ispName);
        if ($combined === '') {
            return false;
        }

        $needles = [
            'amazon', 'aws', 'amazon web services', 'google', 'gcp', 'microsoft', 'azure',
            'oracle', 'oracle cloud', 'digitalocean', 'linode', 'vultr', 'hetzner', 'ovh',
            'leaseweb', 'gcore', 'cloudflare', 'akamai', 'fastly',
            'alibaba', 'aliyun', 'tencent', 'huawei cloud', 'baidu', 'ucloud', 'qingcloud',
            'google cloud', 'tencent cloud', 'alibaba cloud', 'baidu cloud', 'huawei',
            'datacenter', 'data center', 'colo', 'host', 'hosting', 'server', 'cloud',
        ];

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($combined, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isSuspiciousUserAgent(string $userAgent): bool
    {
        $ua = strtolower(trim($userAgent));
        if ($ua === '') {
            return true;
        }

        if ($this->isSearchEngineSpider($ua)) {
            return false;
        }

        $needles = [
            'bot', 'spider', 'crawler', 'scrapy', 'headless', 'phantomjs', 'selenium',
            'playwright', 'puppeteer', 'chromedriver', 'cypress', 'node-fetch', 'axios',
            'okhttp', 'apache-httpclient', 'java/', 'python-requests', 'python-urllib',
            'go-http-client', 'libwww-perl', 'curl/', 'wget/', 'postman', 'insomnia',
            'powershell', 'httpclient/', 'aiohttp', 'httpx', 'gocolly/', 'zgrab/', 'zmap',
            'masscan', 'nmap', 'sqlmap', 'nessus', 'acunetix', 'netcraft', 'censys',
            'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot',
        ];

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($ua, $needle)) {
                return true;
            }
        }

        return strlen($ua) < 20;
    }

    private function isBot(string $userAgent): bool
    {
        $ua = strtolower(trim($userAgent));
        if ($ua === '') {
            return true;
        }

        if (str_contains($ua, 'petalbot')) {
            return true;
        }

        if ($this->isSearchEngineSpider($ua)) {
            return false;
        }

        $bots = [
            'bot', 'spider', 'monitor', 'crawler', 'postman', 'curl/', 'wget/',
            'windowspowershell/', 'python-', 'python-requests', 'python-urllib', 'httpclient/',
            'go-http-client/', 'libwww-perl', 'java/', 'okhttp', 'apache-httpclient',
            'feedburner/', 'headless', 'cloudflare', 'gocolly/', 'scrapy/', 'zgrab/',
            'phantomjs', 'axios', 'apachebench', 'wkhtmltopdf', 'playwright', 'puppeteer',
            'chromedriver', 'cypress', 'selenium', 'node-fetch', 'aiohttp', 'httpx',
            'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot',
            'masscan', 'nmap', 'sqlmap', 'nessus', 'acunetix',
            'petalbot'
        ];

        foreach ($bots as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isSearchEngineSpider(string $ua): bool
    {
        $needles = [
            'baiduspider',
            'googlebot',
            'bingbot',
            'bingpreview',
            '360spider',
            'bytespider',
            'sogouspider',
            'sogou web spider',
            'yisouspider',
        ];

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($ua, $needle)) {
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

    private function ipHashExpr(string $alias = 'p'): string
    {
        $alias = trim($alias);

        if ($this->hasIpHashColumn) {
            return sprintf(
                'COALESCE(%1$s.ip_hash, SHA2(COALESCE(%1$s.ip_address, ""), 256))',
                $alias
            );
        }

        return sprintf('SHA2(COALESCE(%s.ip_address, ""), 256)', $alias);
    }

    private function replaceIpHash(string $sql, string $alias = 'p'): string
    {
        $expr = $this->ipHashExpr($alias);

        return preg_replace_callback(
            '/\b' . preg_quote($alias, '/') . '\.ip_hash\b|\bip_hash\b/',
            fn() => $expr,
            $sql
        );
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

    private function resolveAsnMeta(?string $ip): array
    {
        if (!$this->asnEnabled || !$ip) {
            return [];
        }

        $this->maybeRefreshAsnDb();

        $cacheKey = 'asnmeta:' . $ip;
        $cached = $this->redis->get($cacheKey);
        if ($cached) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            $this->redis->del($cacheKey);
        }

        $asn = $this->asnResolver->resolve($ip);
        if (!$asn) {
            return [];
        }

        $sanitized = [
            'number' => trim((string) ($asn['number'] ?? '')),
            'name' => trim((string) ($asn['name'] ?? '')),
        ];

        $this->redis->setex($cacheKey, 604800, json_encode($sanitized, JSON_UNESCAPED_UNICODE));

        return $sanitized;
    }

    private function maybeRefreshAsnDb(): void
    {
        if (!$this->asnEnabled) {
            return;
        }
        $now = time();
        if ($this->asnNextRefreshAt > $now) {
            return;
        }

        $this->ensureAsnDbExists();
        $this->asnResolver->refreshIfUpdated();
        $this->asnNextRefreshAt = $now + 3600;
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

    private function ensureBlockedDomainSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS site_blocked_domains (
                site_id INT UNSIGNED NOT NULL,
                domain VARCHAR(255) NOT NULL,
                log_date DATE NOT NULL,
                pv BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (site_id, domain, log_date),
                INDEX idx_site_date (site_id, log_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );
    }

    private function ensureHashPartitioned(string $table, int $partitions = 64): void
    {
        $method = null;
        $probe = $this->db->prepare(
            'SELECT PARTITION_METHOD FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1'
        );
        $probe->execute([':table' => $table]);
        $method = $probe->fetchColumn();

        if ($method === 'HASH') {
            return;
        }

        try {
            $quoted = str_replace('`', '``', $table);
            $this->db->exec("ALTER TABLE `{$quoted}` PARTITION BY HASH (site_id) PARTITIONS {$partitions}");
        } catch (PDOException $e) {
            // If partitioning is unsupported or privileges are missing, continue with the non-partitioned table.
        }
    }

    private function ensurePageviewSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS pageviews (
                id BIGINT UNSIGNED AUTO_INCREMENT,
                site_id INT UNSIGNED NOT NULL,
                host VARCHAR(255),
                canonical_host VARCHAR(255),
                path VARCHAR(2048) NOT NULL,
                referrer VARCHAR(2048),
                user_agent VARCHAR(1024),
                ip_address VARCHAR(45),
                ip_hash CHAR(64),
                session_id VARCHAR(64),
                duration_seconds INT DEFAULT 0,
                page_count INT DEFAULT 1,
                keyword VARCHAR(255),
                is_mobile TINYINT(1) DEFAULT 0,
                is_unique TINYINT(1) DEFAULT 0,
                is_proxy_risk TINYINT(1) DEFAULT 0,
                occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id, site_id),
                INDEX idx_site_time (site_id, occurred_at),
                INDEX idx_site_mobile (site_id, is_mobile, occurred_at),
                INDEX idx_site_host (site_id, canonical_host, occurred_at),
                INDEX idx_site_ip (site_id, ip_hash, occurred_at),
                INDEX idx_site_session (site_id, session_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );

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
        $ensureColumn('path', 'VARCHAR(2048)');
        $ensureColumn('referrer', 'VARCHAR(2048)');
        $ensureColumn('user_agent', 'VARCHAR(1024)');
        $ensureColumn('session_id', 'VARCHAR(64)');
        $ensureColumn('duration_seconds', 'INT DEFAULT 0');
        $ensureColumn('page_count', 'INT DEFAULT 1');
        $ensureColumn('keyword', 'VARCHAR(255)');
        $ensureColumn('is_mobile', 'TINYINT(1) DEFAULT 0');
        $ensureColumn('is_unique', 'TINYINT(1) DEFAULT 0');
        $ensureColumn('is_proxy_risk', 'TINYINT(1) DEFAULT 0');
        $ensureColumn('country_name', 'VARCHAR(128)');
        $ensureColumn('region_name', 'VARCHAR(128)');
        $ensureColumn('city_name', 'VARCHAR(128)');
        $ensureColumn('isp_domain', 'VARCHAR(128)');
        $ensureColumn('country_code', 'VARCHAR(16)');
        $ensureColumn('occurred_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
        $ensureColumn('ip_address', 'VARCHAR(45)');
        $ensureColumn('ip_hash', 'CHAR(64)');

        $ensureIndex('idx_site_host', 'site_id, canonical_host, occurred_at');
        $ensureIndex('idx_site_mobile', 'site_id, is_mobile, occurred_at');
        $ensureIndex('idx_site_ip', 'site_id, ip_hash, occurred_at');
        $ensureIndex('idx_site_ref', 'site_id, referrer(120), occurred_at');

        $this->hasIpHashColumn = $columnExists('ip_hash');
        $this->hasGeoColumns = $columnExists('region_name') && $columnExists('city_name');

        // If the column is missing (or inaccessible), attempt to add it and gracefully fall back.
        if (!$this->hasIpHashColumn) {
            try {
                $this->db->exec('ALTER TABLE pageviews ADD COLUMN ip_hash CHAR(64)');
                $this->hasIpHashColumn = $columnExists('ip_hash');
            } catch (PDOException $inner) {
                $this->hasIpHashColumn = false;
            }
        }

        if ($this->hasIpHashColumn) {
            $needsBackfill = $this->db->query(
                "SELECT 1 FROM pageviews WHERE (ip_hash IS NULL OR ip_hash = '') LIMIT 1"
            )->fetchColumn();

            if ($needsBackfill !== false) {
                $this->db->exec(
                    "UPDATE pageviews SET ip_hash = SHA2(COALESCE(ip_address, ''), 256)
                    WHERE (ip_hash IS NULL OR ip_hash = '') LIMIT 50000"
                );
            }
        }

        if ($this->hasGeoColumns) {
            $geoBackfill = $this->db->query(
                "SELECT 1 FROM pageviews WHERE (region_name IS NULL OR region_name = '') AND ip_address IS NOT NULL AND ip_address != '' LIMIT 1"
            )->fetchColumn();
            if ($geoBackfill !== false) {
                $rows = $this->db->query(
                    "SELECT id, ip_address FROM pageviews
                     WHERE (region_name IS NULL OR region_name = '') AND ip_address IS NOT NULL AND ip_address != ''
                     ORDER BY id DESC LIMIT 2000"
                )->fetchAll();
                foreach ($rows as $row) {
                    $meta = $this->resolveIpMeta($row['ip_address'] ?? '');
                    $stmt = $this->db->prepare(
                        'UPDATE pageviews
                         SET country_name = :country, region_name = :region, city_name = :city, isp_domain = :isp, country_code = :code
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':country' => $meta['country_name'] ?? null,
                        ':region' => $meta['region_name'] ?? null,
                        ':city' => $meta['city_name'] ?? null,
                        ':isp' => $meta['isp_domain'] ?? null,
                        ':code' => $meta['country_code'] ?? null,
                        ':id' => $row['id'],
                    ]);
                }
            }
        }

        $this->ensureHashPartitioned('pageviews');

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS site_ip_audience (
                site_id INT UNSIGNED NOT NULL,
                ip_hash CHAR(64) NOT NULL,
                first_seen DATETIME NOT NULL,
                last_seen_date DATE NOT NULL,
                PRIMARY KEY (site_id, ip_hash),
                INDEX idx_last_seen_date (last_seen_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );

        $this->ensureHashPartitioned('site_ip_audience');
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

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS pageview_dimension_rollups (
                site_id INT UNSIGNED NOT NULL,
                bucket_start DATETIME NOT NULL,
                dimension_type VARCHAR(64) NOT NULL,
                dimension_value VARCHAR(255) NOT NULL,
                pv BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uv BIGINT UNSIGNED NOT NULL DEFAULT 0,
                ip_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                session_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                duration_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
                page_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
                bounce_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (site_id, bucket_start, dimension_type, dimension_value),
                INDEX idx_dimension_type (dimension_type, dimension_value),
                INDEX idx_dimension_time (bucket_start),
                INDEX idx_query_perf (site_id, dimension_type, bucket_start),
                INDEX idx_share_perf (dimension_type, bucket_start, site_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS pageview_page_rollups (
                site_id INT UNSIGNED NOT NULL,
                bucket_start DATETIME NOT NULL,
                path VARCHAR(512) NOT NULL,
                pv BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uv BIGINT UNSIGNED NOT NULL DEFAULT 0,
                ip_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                session_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                duration_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
                page_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
                bounce_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (site_id, bucket_start, path),
                INDEX idx_page_time (bucket_start),
                INDEX idx_query_perf (site_id, bucket_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS pageview_entry_rollups (
                site_id INT UNSIGNED NOT NULL,
                bucket_start DATETIME NOT NULL,
                path VARCHAR(512) NOT NULL,
                pv BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uv BIGINT UNSIGNED NOT NULL DEFAULT 0,
                ip_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                session_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                duration_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
                page_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
                bounce_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (site_id, bucket_start, path),
                INDEX idx_entry_time (bucket_start),
                INDEX idx_query_perf (site_id, bucket_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );

        $this->ensureHashPartitioned('pageview_rollups');
        $this->ensureHashPartitioned('pageview_dimension_rollups');
        $this->ensureHashPartitioned('pageview_page_rollups');
        $this->ensureHashPartitioned('pageview_entry_rollups');
    }

    private function ensureIpDbExists(): void
    {
        $dir = dirname($this->ipdbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $refreshSeconds = max(1, $this->ipdbRefreshHours) * 3600;
        $isStale = !is_file($this->ipdbPath)
            || filesize($this->ipdbPath) <= 0
            || (time() - (int) filemtime($this->ipdbPath)) >= $refreshSeconds;

        if (!$isStale) {
            return;
        }

        if (function_exists('shell_exec') && $this->ipdbUrl !== '') {
            $this->downloadIpDbAsync($this->ipdbUrl, $this->ipdbPath);
            return;
        }

        if ($this->ipdbUrl === '') {
            return;
        }

        try {
            $data = @file_get_contents($this->ipdbUrl);
            if ($data !== false && strlen($data) > 1024) {
                @file_put_contents($this->ipdbPath, $data);
            }
        } catch (\Throwable $e) {
            // ignore download failure; resolver will fallback
        }
    }

    private function downloadIpDbAsync(string $url, string $path): void
    {
        $tmp = $path . '.tmp';
        $urlEscaped = escapeshellarg($url);
        $tmpEscaped = escapeshellarg($tmp);
        $pathEscaped = escapeshellarg($path);
        $cmd = "(curl -fsSL {$urlEscaped} -o {$tmpEscaped} && mv {$tmpEscaped} {$pathEscaped}) > /dev/null 2>&1 &";
        @shell_exec($cmd);
    }

    private function ensureAsnDbExists(): void
    {
        if (!$this->asnEnabled) {
            return;
        }
        $paths = array_filter([$this->asnDbPath, $this->asnDbPathV4, $this->asnDbPathV6]);
        foreach ($paths as $path) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }

        $refreshSeconds = max(1, $this->asnDbRefreshHours) * 3600;
        $downloads = [
            [$this->asnDbUrl, $this->asnDbPath],
            [$this->asnDbUrlV4, $this->asnDbPathV4],
            [$this->asnDbUrlV6, $this->asnDbPathV6],
        ];

        foreach ($downloads as [$url, $path]) {
            if (!$url || !$path) {
                continue;
            }

            if (is_file($path) && (time() - (int) filemtime($path)) < $refreshSeconds) {
                continue;
            }

            $this->downloadAsnDbAsync($url, $path);
        }
    }

    private function downloadAsnDbAsync(string $url, string $path): void
    {
        $tmp = $path . '.tmp';
        $urlEscaped = escapeshellarg($url);
        $tmpEscaped = escapeshellarg($tmp);
        $pathEscaped = escapeshellarg($path);
        if (str_ends_with($url, '.gz') && str_ends_with($path, '.tsv')) {
            $cmd = "(curl -fsSL {$urlEscaped} | gzip -dc > {$tmpEscaped} && mv {$tmpEscaped} {$pathEscaped}) > /dev/null 2>&1 &";
        } else {
            $cmd = "(curl -fsSL {$urlEscaped} -o {$tmpEscaped} && mv {$tmpEscaped} {$pathEscaped}) > /dev/null 2>&1 &";
        }
        @shell_exec($cmd);
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
                'pageviews_days' => (int) ($this->retentionDefaults['pageviews_days'] ?? 0),
                'cleanup_hour' => (int) ($this->retentionDefaults['cleanup_hour'] ?? 3),
            ]);
        }

        if (!$this->getSetting('ingest_filters')) {
            $this->setSetting('ingest_filters', $this->ingestFilterDefaults);
        }
    }

    private function hydrateRetention(): void
    {
        $retention = $this->getRetentionSettings($this->retentionDefaults);
        $this->retentionDays = max(0, (int) ($retention['days'] ?? 0));
        $this->pageviewRetentionDays = max(0, (int) ($retention['pageviews_days'] ?? 0));
        $this->cleanupHour = min(23, max(0, (int) ($retention['cleanup_hour'] ?? 3)));
    }

    private function hydrateIngestFilters(): void
    {
        $filters = $this->getIngestFilters($this->ingestFilterDefaults);
        $this->ingestFilters = [
            'ip_filters' => trim((string) ($filters['ip_filters'] ?? '')),
            'keyword_filters' => trim((string) ($filters['keyword_filters'] ?? '')),
            'asn_filters' => trim((string) ($filters['asn_filters'] ?? '')),
            'ua_filters' => trim((string) ($filters['ua_filters'] ?? '')),
        ];
    }

private function getMobileBreakdown(int $siteId, string $range): array
    {
        $cacheKey = "mobile_breakdown:{$siteId}:{$range}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if ($span) {
                $hosts = $this->aggregateDimensionRollups($siteId, 'host', $span['start'], $span['end'], 200);
                $hostDevices = $this->aggregateDimensionRollups($siteId, 'host_device', $span['start'], $span['end'], 400);

                if (!empty($hosts)) {
                    $result = $this->formatHostDeviceBreakdown($hosts, $hostDevices);
                    
                    // 获取真实的全局去重 IP (无需再查 PV)
                    $globalTotals = $this->aggregateTotalsWithRollups($siteId, $span['start'], $span['end']);
                    $deviceRollup = $this->aggregateDimensionRollups($siteId, 'device', $span['start'], $span['end'], 2);
                    
                    $mobileIps = 0;
                    foreach ($deviceRollup as $dr) {
                        if (($dr['dimension_value'] ?? '') === 'mobile') {
                            $mobileIps = (int) ($dr['ips'] ?? 0);
                            break;
                        }
                    }
                    
                    // 构建全局汇总行：PV 与 "汇总" 保持一致，IP 使用全局去重后的值
                    $globalRow = [
                        'domain' => '全局汇总',
                        'views' => $result[0]['views'],               // 保持一致
                        'mobile_views' => $result[0]['mobile_views'], // 保持一致
                        'ips' => (int) ($globalTotals['ip_count'] ?? 0),
                        'mobile_ips' => $mobileIps,
                    ];
                    
                    // 插入到数组最前面
                    array_unshift($result, $globalRow);
                    return $result;
                }
            }
            return [
                ['domain' => '全局汇总', 'views' => 0, 'ips' => 0, 'mobile_views' => 0, 'mobile_ips' => 0],
                ['domain' => '汇总', 'views' => 0, 'ips' => 0, 'mobile_views' => 0, 'mobile_ips' => 0]
            ];
        });
    }

private function formatHostDeviceBreakdown(array $hosts, array $hostDevices): array
    {
        $deviceMap = [];

        foreach ($hostDevices as $deviceRow) {
            [$hostValue, $device] = array_pad(explode('|', $deviceRow['dimension_value'] ?? '', 2), 2, '');
            if ($device !== 'mobile') {
                continue;
            }

            $deviceMap[$hostValue]['views'] = ($deviceMap[$hostValue]['views'] ?? 0) + (int) ($deviceRow['views'] ?? 0);
            $deviceMap[$hostValue]['ips'] = ($deviceMap[$hostValue]['ips'] ?? 0) + (int) ($deviceRow['ips'] ?? 0);
        }

        $rows = [];
        $totals = [
            'domain' => '汇总', // <--- 改为“汇总”
            'views' => 0,
            'ips' => 0,
            'mobile_views' => 0,
            'mobile_ips' => 0,
        ];

        foreach ($hosts as $row) {
            $domain = $row['dimension_value'] ?? '未知域名';
            $views = (int) ($row['views'] ?? 0);
            $ips = (int) ($row['ips'] ?? 0);
            $mobileViews = (int) ($deviceMap[$domain]['views'] ?? 0);
            $mobileIps = (int) ($deviceMap[$domain]['ips'] ?? 0);

            $rows[] = [
                'domain' => $domain,
                'views' => $views,
                'ips' => $ips,
                'mobile_views' => $mobileViews,
                'mobile_ips' => $mobileIps,
            ];

            $totals['views'] += $views;
            $totals['ips'] += $ips;
            $totals['mobile_views'] += $mobileViews;
            $totals['mobile_ips'] += $mobileIps;
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
            WHEN LOWER(COALESCE({$prefix}user_agent,'')) LIKE '%petalbot%' THEN '华为'
            WHEN LOWER(COALESCE({$prefix}referrer,'')) LIKE '%quark.cn%' THEN '夸克'
            ELSE '其他'
        END";
    }

    private function getSearchEngines(int $siteId, string $range): array
    {
        $cacheKey = "search_engines:{$siteId}:{$range}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if (!$span) {
                return [];
            }

            $rollup = $this->aggregateDimensionRollups($siteId, 'search_engine', $span['start'], $span['end'], 50);

            if (!empty($rollup)) {
                $mapped = [];

                foreach ($rollup as $row) {
                    $engine = $row['dimension_value'] ?? '其他';

                    if ($engine === '其他') {
                        continue;
                    }

                    $mapped[] = [
                        'engine' => $engine,
                        'views' => (int) ($row['views'] ?? 0),
                        'ips' => (int) ($row['ips'] ?? 0),
                    ];
                }

                return $mapped;
            }
            return [];
        });
    }

private function getExternalLinks(int $siteId, string $range): array
    {
        $cacheKey = "external_links:{$siteId}:{$range}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $domains = $this->getAllSiteDomains($siteId);
            
            $blocked = ['baidu', 'google', 'bing.', 'sm.cn', 'quark.cn', 'so.com', 'sogou', 'bytedance', 'toutiao'];
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if (!$span) {
                return [];
            }

            $rollup = $this->aggregateDimensionRollups($siteId, 'referrer_host', $span['start'], $span['end'], 200);
            $filtered = [];

            if (!empty($rollup)) {
                foreach ($rollup as $row) {
                    $host = strtolower($row['dimension_value'] ?? '');
                    if ($host === '' || $host === '直接访问') {
                        continue;
                    }

                    $skip = false;
                    
                    // 排除搜索引擎
                    foreach ($blocked as $needle) {
                        if (str_contains($host, $needle)) {
                            $skip = true;
                            break;
                        }
                    }

                    if (!$skip && $domains && $this->isOwnReferrer($host, $domains)) {
                        $skip = true;
                    }

                    if (!$skip) {
                        $filtered[] = [
                            'host' => $host,
                            'views' => (int) ($row['views'] ?? 0),
                            'ips' => (int) ($row['ips'] ?? 0),
                        ];
                    }
                }

                return $filtered;
            }
            return [];
        });
    }

public function getDeviceBreakdown(int $siteId, string $range): array
    {
        $cacheKey = "devices:{$siteId}:{$range}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $desktopViews = 0;
            $mobileViews = 0;
            $mobileIps = 0;
            $totalIps = 0;

            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if ($span) {
                $rollup = $this->aggregateDimensionRollups($siteId, 'device', $span['start'], $span['end'], 2);
                $lookup = [];
                foreach ($rollup as $row) {
                    $lookup[$row['dimension_value'] ?? ''] = $row;
                }

                $mobileViews = (int) ($lookup['mobile']['views'] ?? 0);
                $mobileIps = (int) ($lookup['mobile']['ips'] ?? 0);
                $desktopViews = (int) ($lookup['desktop']['views'] ?? 0);

                $totals = $this->aggregateTotalsWithRollups($siteId, $span['start'], $span['end']);
                $totalIps = (int) ($totals['ip_count'] ?? 0);
            }

            $desktopIps = max(0, $totalIps - $mobileIps);

            return [
                'desktop' => [
                    'views' => $desktopViews,
                    'ips' => $desktopIps,
                ],
                'mobile' => [
                    'views' => $mobileViews,
                    'ips' => $mobileIps,
                ],
            ];
        });
    }

    public function getBrowserBreakdown(int $siteId, string $range, int $limit = 10): array
    {
        $cacheKey = "browsers:{$siteId}:{$range}:{$limit}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $limit) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if ($span) {
                $rollup = $this->aggregateDimensionRollups($siteId, 'browser', $span['start'], $span['end'], $limit);

                if (!empty($rollup)) {
                    return array_map(fn ($row) => [
                        'browser' => $row['dimension_value'],
                        'views' => (int) ($row['views'] ?? 0),
                        'ips' => (int) ($row['ips'] ?? 0),
                    ], $rollup);
                }
            }
            return [];
        });
    }

    public function getRegionStats(int $siteId, string $range, int $limit = 50): array
    {
        $cacheKey = "regions:{$siteId}:{$range}:{$limit}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $limit) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if ($span) {
                $rollup = $this->aggregateDimensionRollups($siteId, 'region', $span['start'], $span['end'], $limit);

                if (!empty($rollup)) {
                    return array_map(fn ($row) => [
                        'region' => $row['dimension_value'],
                        'views' => (int) ($row['views'] ?? 0),
                        'ips' => (int) ($row['ips'] ?? 0),
                    ], $rollup);
                }
            }
            return [];
        });
    }

    public function getCountryStats(int $siteId, string $range, int $limit = 200): array
    {
        $cacheKey = "countries:{$siteId}:{$range}:{$limit}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $limit) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if ($span) {
                $rollup = $this->aggregateDimensionRollups($siteId, 'country', $span['start'], $span['end'], $limit);

                if (!empty($rollup)) {
                    return array_map(fn ($row) => [
                        'country' => $row['dimension_value'],
                        'country_code' => '',
                        'ips' => (int) ($row['ips'] ?? 0),
                    ], $rollup);
                }
            }

            return [];
        });
    }

    private function getIspStats(int $siteId, string $range, int $limit = 50): array
    {
        $cacheKey = "isp:{$siteId}:{$range}:{$limit}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $limit) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if ($span) {
                $rollup = $this->aggregateDimensionRollups($siteId, 'isp', $span['start'], $span['end'], $limit);

                if (!empty($rollup)) {
                    return array_map(fn ($row) => [
                        'isp' => $row['dimension_value'],
                        'views' => (int) ($row['views'] ?? 0),
                        'ips' => (int) ($row['ips'] ?? 0),
                    ], $rollup);
                }
            }
            return [];
        });
    }

    public function getNewVsReturning(int $siteId, string $range): array
    {
        $cacheKey = "new_vs_returning:{$siteId}:{$range}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            $rows = $span
                ? $this->aggregateDimensionRollups($siteId, 'audience', $span['start'], $span['end'], 2)
                : [];

            $newViews = 0;
            $returningViews = 0;
            $newIps = 0;
            $returningIps = 0;

            foreach ($rows as $row) {
                $value = $row['dimension_value'] ?? '';

                if ($value === 'new') {
                    $newViews += (int) ($row['views'] ?? 0);
                    $newIps += (int) ($row['ips'] ?? 0);
                }

                if ($value === 'returning') {
                    $returningViews += (int) ($row['views'] ?? 0);
                    $returningIps += (int) ($row['ips'] ?? 0);
                }
            }

            return [
                'new' => $newViews,
                'returning' => $returningViews,
                'new_ips' => $newIps,
                'returning_ips' => $returningIps,
            ];
        });
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

    public function getHourlyStats(int $siteId, string $range): array
    {
        $cacheKey = "hourly:{$siteId}:{$range}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range) {
            [$start, $end] = $this->rollupRangeBounds($range);
            return $this->getRollupHourlyStats($siteId, $start, $end, true);
        });
    }

    private function getHourlyStatsForWindow(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return $this->getRollupHourlyStats($siteId, $start, $end, true);
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
        if (str_starts_with($range, 'custom:')) {
            [$start, $endExclusive] = $this->parseCustomRange($range);
            if ($start && $endExclusive) {
                $endDisplay = $endExclusive->modify('-1 second');
                return [
                    'start' => $start->format('Y-m-d H:i:s'),
                    'end' => $endDisplay->format('Y-m-d H:i:s'),
                ];
            }
        }
        $ranges = [
            'today' => $now->setTime(0, 0),
            'yesterday' => $now->modify('-1 day')->setTime(0, 0),
            'day_before' => $now->modify('-2 day')->setTime(0, 0),
            '7d' => $now->modify('-6 day')->setTime(0, 0),
        ];

        $start = $ranges[$range] ?? $ranges['today'];
        if ($range === 'yesterday') {
            $end = $now->setTime(0, 0);
        } elseif ($range === 'day_before') {
            $end = $now->modify('-1 day')->setTime(0, 0);
        } else {
            $end = $now;
        }

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
        if (str_starts_with($range, 'custom:')) {
            [$start, $endExclusive] = $this->parseCustomRange($range);
            if ($start && $endExclusive) {
                $sql = ' AND occurred_at >= :start AND occurred_at < :end';
                $params[':start'] = $start->format('Y-m-d H:i:s');
                $params[':end'] = $endExclusive->format('Y-m-d H:i:s');
                return [$sql, $params];
            }
        }

        $ranges = [
            'today' => $now->setTime(0, 0),
            'yesterday' => $now->modify('-1 day')->setTime(0, 0),
            'day_before' => $now->modify('-2 day')->setTime(0, 0),
            '7d' => $now->modify('-6 day')->setTime(0, 0),
        ];

        $start = $ranges[$range] ?? $ranges['today'];

        if ($range === 'yesterday') {
            $end = $now->setTime(0, 0);
            $sql = ' AND occurred_at >= :start AND occurred_at < :end';
            $params[':start'] = $start->format('Y-m-d H:i:s');
            $params[':end'] = $end->format('Y-m-d H:i:s');
            return [$sql, $params];
        }
        if ($range === 'day_before') {
            $end = $now->modify('-1 day')->setTime(0, 0);
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

    private function parseCustomRange(string $range): array
    {
        $parts = explode(':', $range);
        $startRaw = $parts[1] ?? '';
        $endRaw = $parts[2] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startRaw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endRaw)) {
            return [null, null];
        }
        try {
            $start = (new DateTimeImmutable($startRaw))->setTime(0, 0);
            $end = (new DateTimeImmutable($endRaw))->setTime(0, 0);
        } catch (Throwable $e) {
            return [null, null];
        }
        if ($end < $start) {
            return [null, null];
        }
        $endExclusive = $end->modify('+1 day');
        return [$start, $endExclusive];
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
        $merged['pageviews_days'] = max(0, (int) ($merged['pageviews_days'] ?? 0));
        $merged['cleanup_hour'] = min(23, max(0, (int) ($merged['cleanup_hour'] ?? 3)));

        return $merged;
    }

    public function getIngestFilters(array $fallback): array
    {
        $stored = $this->getSetting('ingest_filters') ?? [];
        $merged = array_merge($fallback, $stored);
        $merged['ip_filters'] = trim((string) ($merged['ip_filters'] ?? ''));
        $merged['keyword_filters'] = trim((string) ($merged['keyword_filters'] ?? ''));
        $merged['asn_filters'] = trim((string) ($merged['asn_filters'] ?? ''));
        $merged['ua_filters'] = trim((string) ($merged['ua_filters'] ?? ''));

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

    public function updateRetentionSettings(int $days, int $hour, int $pageviewsDays = 0): array
    {
        $payload = [
            'days' => max(0, $days),
            'pageviews_days' => max(0, $pageviewsDays),
            'cleanup_hour' => min(23, max(0, $hour)),
        ];
        $this->setSetting('retention', $payload);
        $this->hydrateRetention();

        return $payload;
    }

    public function updateIngestFilters(string $ipFilters, string $keywordFilters): array
    {
        $payload = [
            'ip_filters' => trim($ipFilters),
            'keyword_filters' => trim($keywordFilters),
            'asn_filters' => trim((string) ($this->ingestFilters['asn_filters'] ?? '')),
            'ua_filters' => trim((string) ($this->ingestFilters['ua_filters'] ?? '')),
        ];
        $this->setSetting('ingest_filters', $payload);
        $this->hydrateIngestFilters();

        return $payload;
    }

    public function updateIngestFiltersWithAsn(string $ipFilters, string $keywordFilters, string $asnFilters, string $uaFilters = ''): array
    {
        $payload = [
            'ip_filters' => trim($ipFilters),
            'keyword_filters' => trim($keywordFilters),
            'asn_filters' => trim($asnFilters),
            'ua_filters' => trim($uaFilters),
        ];
        $this->setSetting('ingest_filters', $payload);
        $this->hydrateIngestFilters();

        return $payload;
    }

    public function manualCleanup(int $days, ?int $pageviewsDays = null, ?int $batchSize = null): void
    {
        $pageviewsDays = $pageviewsDays === null ? $days : max(0, $pageviewsDays);
        $this->cleanupDataOlderThan($days, $pageviewsDays, $batchSize);
    }

    private function cleanupDataOlderThan(int $rollupDays, int $pageviewsDays, ?int $batchSize = null): void
    {
        if ($rollupDays <= 0 && $pageviewsDays <= 0) {
            return;
        }

        $batchSize = $batchSize && $batchSize > 0 ? $batchSize : 50000;

        if ($rollupDays > 0) {
            $rollupCutoffPoint = (new \DateTimeImmutable('now'))->modify("-{$rollupDays} days");
            $rollupCutoff = $rollupCutoffPoint->format('Y-m-d H:i:s');
            $rollupCutoffDate = $rollupCutoffPoint->format('Y-m-d');

            // Rollup tables are small enough to delete in a single pass while still using time indexes.
            $this->deleteBatched(
                'DELETE FROM pageview_rollups WHERE bucket_start < :cutoff LIMIT :batch',
                [':cutoff' => $rollupCutoff],
                $batchSize
            );

            $this->deleteBatched(
                'DELETE FROM pageview_dimension_rollups WHERE bucket_start < :cutoff LIMIT :batch',
                [':cutoff' => $rollupCutoff],
                $batchSize
            );

            $this->deleteBatched(
                'DELETE FROM pageview_page_rollups WHERE bucket_start < :cutoff LIMIT :batch',
                [':cutoff' => $rollupCutoff],
                $batchSize
            );

            $this->deleteBatched(
                'DELETE FROM pageview_entry_rollups WHERE bucket_start < :cutoff LIMIT :batch',
                [':cutoff' => $rollupCutoff],
                $batchSize
            );

            $this->deleteBatched(
                'DELETE FROM pageview_bot_logs WHERE bucket_start < :cutoff LIMIT :batch',
                [':cutoff' => $rollupCutoff],
                $batchSize
            );

            $this->deleteBatched(
                'DELETE FROM site_ip_audience WHERE last_seen_date < :cutoff_date LIMIT :batch',
                [':cutoff_date' => $rollupCutoffDate],
                $batchSize
            );

        }

        if ($pageviewsDays > 0) {
            $pageviewsCutoffPoint = (new \DateTimeImmutable('now'))->modify("-{$pageviewsDays} days");
            $pageviewsCutoff = $pageviewsCutoffPoint->format('Y-m-d H:i:s');

            // Pageviews can be very large; delete in batches to limit lock time and reduce replication lag.
            $this->deleteBatched(
                'DELETE FROM pageviews WHERE occurred_at < :cutoff LIMIT :batch',
                [':cutoff' => $pageviewsCutoff],
                $batchSize
            );
        }
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
        // 加上缓存包裹，防跨天串包和动态 TTL 机制都会自动生效
        $cacheKey = "share_report:{$token}:{$range}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($token, $range) {
            $share = $this->getShareByToken($token);
            if (!$share || empty($share['site_ids'])) {
                return null;
            }

            [$start, $end] = $this->rollupRangeBounds($range);

            $rows = $this->getHostDeviceRollupRowsForSites($share['site_ids'], $start, $end);
            if (empty($rows)) {
                $rows = [[
                    'domain' => '汇总',
                    'views' => 0,
                    'ips' => 0,
                    'mobile_views' => 0,
                    'mobile_ips' => 0,
                ]];
            }

            return [
                'share' => $share,
                'rows' => $rows,
            ];
        });
    }

private function getHostDeviceRollupRowsForSites(array $siteIds, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $hosts = $this->aggregateDimensionRollupsForSites($siteIds, 'host', $start, $end, 500);
        if (empty($hosts)) {
            return [
                ['domain' => '全局汇总', 'views' => 0, 'ips' => 0, 'mobile_views' => 0, 'mobile_ips' => 0],
                ['domain' => '汇总', 'views' => 0, 'ips' => 0, 'mobile_views' => 0, 'mobile_ips' => 0]
            ];
        }

        $hostDevices = $this->aggregateDimensionRollupsForSites($siteIds, 'host_device', $start, $end, 1000);
        $result = $this->formatHostDeviceBreakdown($hosts, $hostDevices);

        $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
        $params = array_merge($siteIds, [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
        
        // 全局基础 IP（一次性查询）
        $stmt = $this->db->prepare("SELECT SUM(ip_count) as ips FROM pageview_rollups WHERE site_id IN ($placeholders) AND bucket_start >= ? AND bucket_start < ?");
        $stmt->execute($params);
        $globalTotals = $stmt->fetch() ?: [];

        // 全局移动端 IP（一次性查询）
        $stmtDevice = $this->db->prepare("SELECT SUM(ip_count) as ips FROM pageview_dimension_rollups WHERE site_id IN ($placeholders) AND dimension_type = 'device' AND dimension_value = 'mobile' AND bucket_start >= ? AND bucket_start < ?");
        $stmtDevice->execute($params);
        $globalMobile = $stmtDevice->fetch() ?: [];

        $globalRow = [
            'domain' => '全局汇总',
            'views' => $result[0]['views'],               
            'mobile_views' => $result[0]['mobile_views'], 
            'ips' => (int) ($globalTotals['ips'] ?? 0),
            'mobile_ips' => (int) ($globalMobile['ips'] ?? 0),
        ];

        array_unshift($result, $globalRow);
        return $result;
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
        if ($this->retentionDays <= 0 && $this->pageviewRetentionDays <= 0) {
            return;
        }

        $key = 'retention:cleanup:' . date('Y-m-d');
        $hour = (int) date('G');
        if ($hour < $this->cleanupHour) {
            return;
        }

        if ($this->redis->setnx($key, '1')) {
            $this->redis->expire($key, 86400);
            $this->cleanupDataOlderThan($this->retentionDays, $this->pageviewRetentionDays);
        }
    }

    private function maybeCleanupBlockedDomains(): void
    {
        $key = 'blocked_domains:cleanup:' . date('Y-m-d');
        if (!$this->redis->setnx($key, '1')) {
            return;
        }
        $this->redis->expire($key, 86400);

        $statement = $this->db->prepare(
            'DELETE FROM site_blocked_domains WHERE log_date < DATE_SUB(CURDATE(), INTERVAL 1 DAY)'
        );
        $statement->execute();
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

    private function deleteBatched(string $sql, array $params, int $batchSize): void
    {
        $statement = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':batch', $batchSize, PDO::PARAM_INT);

        do {
            $statement->execute();
            $deleted = $statement->rowCount();
        } while ($deleted === $batchSize);
    }
}
