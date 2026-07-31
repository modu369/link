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
    private array $ingestFilterDefaults = ['ip_filters' => '', 'keyword_filters' => '', 'path_filters' => '', 'asn_filters' => '', 'ua_filters' => ''];
    private array $ingestFilters = ['ip_filters' => '', 'keyword_filters' => '', 'path_filters' => '', 'asn_filters' => '', 'ua_filters' => ''];
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
        $this->ipResolver = new IpResolver($this->ipdbPath);
        $this->asnResolver = $this->asnEnabled
            ? new AsnResolver($this->asnDbPath, $this->asnDbPathV4, $this->asnDbPathV6)
            : new AsnResolver();

        $this->hydrateRetention();
        $this->hydrateIngestFilters();
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
            // 修复：使用传入的 $ttlSeconds，或者在需要时结合 $this->cacheTtl
            $computedTtl = $ttlSeconds; 
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
    $currentUserId = $GLOBALS['current_user_id'] ?? 0; // 获取当前用户ID

    $statement = $this->db->prepare(
        'INSERT INTO sites (name, domain, tracking_id, user_id, created_at) VALUES (:name, :domain, :tracking_id, :user_id, NOW())'
    );
    $statement->execute([
        ':name' => $name,
        ':domain' => $normalizedDomain,
        ':tracking_id' => $trackingId,
        ':user_id' => $currentUserId,
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
// --- 修改 1：getSites 增加可选参数 $userId ---
public function getSites(?int $userId = null): array
{
    // 如果传了 $userId 则查该用户的；没传则根据当前身份查（管理员为0，普通用户为UID）
    $uid = ($userId !== null) ? $userId : ($GLOBALS['current_user_id'] ?? 0);

    $statement = $this->db->prepare('SELECT id, name, domain, tracking_id, created_at FROM sites WHERE user_id = :user_id ORDER BY created_at DESC');
    $statement->execute([':user_id' => $uid]);
    return $statement->fetchAll();
}

// --- 修改 2：getSite 必须查询 user_id 字段 ---
public function getSite(int $id): ?array
{
    $isAdmin = $GLOBALS['is_admin'] ?? false;
    $currentUserId = $GLOBALS['current_user_id'] ?? 0;

    if ($isAdmin) {
        // 【关键】：这里一定要加上 user_id 字段
        $statement = $this->db->prepare('SELECT id, name, domain, tracking_id, user_id, created_at FROM sites WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
    } else {
        $statement = $this->db->prepare('SELECT id, name, domain, tracking_id, user_id, created_at FROM sites WHERE id = :id AND user_id = :user_id LIMIT 1');
        $statement->execute([':id' => $id, ':user_id' => $currentUserId]);
    }
    
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
        // === 新增：严格越权拦截 ===
        if (!$this->getSite($id)) {
            throw new RuntimeException('越权操作：无权修改该站点或站点不存在');
        }
        // ==========================
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
$ip = $payload['ip'] ?? '';
$fp = $payload['fingerprint'] ?? '';
$path = $payload['path'] ?? '/';
$sessionId = $payload['session_id'] ?? '';
$userAgent = $payload['user_agent'] ?? ''; 
$isPingStr = !empty($payload['is_ping']) ? '1' : '0';

// 【修复】：加入分隔符 '|' 防止边界碰撞，并引入 session_id 和 user_agent 作为同 IP 下的隔离墙
$debounceStr = implode('|', [$sessionId, $fp, $ip, $userAgent, $path, $isPingStr]);
$debounceKey = "tracker:debounce:{$trackingId}:" . md5($debounceStr);

if (!$this->redis->setnx($debounceKey, '1')) {
    return; // 命中防抖锁，直接丢弃该重复 PV
}
$this->redis->expire($debounceKey, 3); // 锁定 3 秒

    if ($this->ingestMode === 'queue') {
        $this->enqueuePageview($trackingId, $payload);
        return;
    }

    $this->processPageview($trackingId, $payload);
}

    private function processPageview(string $trackingId, array $payload, ?int $receivedAt = null, bool $batchMode = false): ?array
    {
        $site = $this->getSiteByTrackingId($trackingId);
        if (!$site) {
            return null;
        }
        $occurredAtStr = $receivedAt ? date('Y-m-d H:i:s', $receivedAt) : date('Y-m-d H:i:s');
        $parsedUrl = $this->parseUrl($payload['path'] ?? null, $site['domain'] ?? null);
        $path = $this->limitText($parsedUrl['path'] ?? '/', 2048, '/');
        $host = $this->limitText($parsedUrl['host'] ?? '', 255);
        $canonicalHost = $this->limitText($parsedUrl['canonical'] ?? '', 255);
        $allowedDomains = $this->getAllSiteDomains((int) $site['id']);

        // 【根源修复 1】：保留完整的原始 Referrer，先提取关键词，防止被 2048 暴力切断
        $rawReferrer = $payload['referrer'] ?? '';
        $keyword = $this->limitText($this->extractKeyword($rawReferrer) ?? '', 255);
        $keyword = str_replace('|', ' ', $keyword);

        // 提取完毕后，再将 URL 截断到 2048 以适配数据库字段
        $referrer = $this->limitText($rawReferrer, 2048);
        $referrerHost = $this->referrerHost($referrer);
        $observedHost = $canonicalHost ?: $this->canonicalHost($referrerHost);

        if (!empty($allowedDomains)) {
            if (!$observedHost || !in_array($observedHost, $allowedDomains, true)) {
                if ($observedHost) {
                    $this->recordBlockedDomain((int) $site['id'], $observedHost);
                }
                return null;
            }
            // ensure stored canonical host reflects the validated domain
            $canonicalHost = $this->limitText($observedHost, 255, $canonicalHost);
        }
        $ip = $this->sanitizeIp($payload['ip'] ?? null);
        // 【修复 3 应用】：降级处理 IPv6
        $effectiveIp = $this->truncateIpv6($ip);
        $ipHash = $effectiveIp ? hash('sha256', $effectiveIp) : null;
        $audienceLabel = 'returning';
        $uvToday = false;
        $isUnique = false;

$rawSessionId = $this->limitText($payload['session_id'] ?? '', 64);
        $rawFingerprint = $this->limitText($payload['fingerprint'] ?? '', 128);
        $title = $this->limitText($payload['title'] ?? '', 255);
        
        // 优化：彻底剥离 ipHash 作为业务 ID 的兜底，改用标准的 UUID v4 (32位纯Hex)
        $sessionId = $rawSessionId !== '' ? $rawSessionId : $this->generateUuidV4Hex();
        $fingerprint = $rawFingerprint;
        if ($fingerprint === '') {
            $fingerprint = $sessionId;
        }
        $uidProvided = $rawSessionId !== '' || $rawFingerprint !== '';
        $duration = max(0, (int) ($payload['duration'] ?? 0));
        $pageCount = max(1, (int) ($payload['page_count'] ?? 1));
        $userAgent = $this->limitText($payload['user_agent'] ?? '', 1024);
        $isBot = $this->isBot($userAgent, $payload); // 传入 payload 以验证 JS 能力
        if (!empty($payload['spider_verified'])) {
            $isBot = true;
        }
        // 👇👇👇 完美版极速短路：插在这里！ 👇👇👇
        // ====== 【核心优化：心跳极速短路通道】 ======
        if (!empty($payload['is_ping'])) {
            // 极速防御：如果该IP已经被风控拉黑（只需1次极速Redis查询），直接丢弃心跳，不给它创建脏Session的机会
            if ($ip && $this->isBlockedProxyIp($ip)) {
                return null; 
            }

            $sessionData = [
                'site_id' => $site['id'], 
                'session_id' => $sessionId, 
                'start_time' => $occurredAtStr, 
                'updated_at' => $occurredAtStr,
                'is_unique' => 0, 
                'ip_address' => $ip, 
                'user_agent' => $userAgent,
                'entry_path' => $path, 
                'last_path' => $path, 
                'referrer' => $referrer ?: null,
                'keyword' => null, 
                'engine' => '其他', 
                'country_name' => null,
                'region_name' => null, 
                'city_name' => null,
                'duration_seconds' => $duration, 
                'page_count' => $pageCount
            ];
            
            if ($batchMode) {
                return ['session' => $sessionData];
            }
            
            $this->executeBulkInsert('sessions', array_keys($sessionData), [$sessionData], 
                'ON DUPLICATE KEY UPDATE last_path = VALUES(last_path), updated_at = VALUES(updated_at), duration_seconds = GREATEST(duration_seconds, VALUES(duration_seconds)), page_count = GREATEST(page_count, VALUES(page_count))'
            );
            return null; 
        }
        // ==========================================
        $isMobile = $this->isMobile($userAgent, $payload);
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
                return null;
            }

            $geo = $ip ? $this->resolveIpMeta($ip) : [];
            $countryName = $this->limitText($geo['country_name'] ?? '', 128);
            $regionName = $this->limitText($geo['region_name'] ?? '', 128);
            $cityName = $this->limitText($geo['city_name'] ?? '', 128);
            $ispName = $this->limitText($geo['isp_domain'] ?? '', 128);
            $countryCode = $this->limitText($geo['country_code'] ?? '', 16);
            $asnMeta = $ip ? $this->resolveAsnMeta($ip) : [];

$proxyRisk = $this->isProxySuspicious(
                $sessionId, $fingerprint, $uidProvided, $fallbackUid, $userAgent,
                $duration, $pageCount, $ip, $ipHash, $asnMeta, $cityName,
                $regionName, $countryName, $headerMeta, (bool) $isMobile, $canonicalHost ?: $host
            );
            
            if ($proxyRisk['blocked']) {
                // 写入专门的 Redis 封禁拦截统计池，不污染 MySQL 蜘蛛表
                $this->recordBlockedStats((int) $site['id'], $ipHash, (bool) $isMobile);
                return null; 
            }
            
            if (!empty($proxyRisk['is_spider'])) {
                $isBot = true;
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
                $this->detectSearchEngine($referrer, $userAgent) ?: '高级渲染蜘蛛' 
            );
            return null; 
        }

        $geo = $geo ?? ($ip ? $this->resolveIpMeta($ip) : []);
        $countryName = $countryName ?? $this->limitText($geo['country_name'] ?? '', 128);
        $regionName = $regionName ?? $this->limitText($geo['region_name'] ?? '', 128);
        $cityName = $cityName ?? $this->limitText($geo['city_name'] ?? '', 128);
        $ispName = $ispName ?? $this->limitText($geo['isp_domain'] ?? '', 128);
        $countryCode = $countryCode ?? $this->limitText($geo['country_code'] ?? '', 16);

        $visitorId = $this->limitText($payload['visitor_id'] ?? '', 128);
        if ($visitorId === '') {
            // 优化：优先使用指纹作为访客ID，其次生成新ID，绝对不再使用 IP Hash 污染独立访客(UV)计算
            $visitorId = $rawFingerprint !== '' ? $rawFingerprint : $this->generateUuidV4Hex();
        }

        if ($visitorId) {
            [$uvToday, $audienceLabel] = $this->markVisitorAudienceState((int) $site['id'], $visitorId);
            $isUnique = $uvToday;
        }

        $engine = $this->detectSearchEngine($referrer, $userAgent);
        $this->captureEntryPath((int) $site['id'], $sessionId, $path);

        $pageviewData = [
            'site_id' => $site['id'], 
            'host' => $host, 
            'canonical_host' => $canonicalHost,
            'title' => $title ?: null,
            'path' => $path, 
            'referrer' => $referrer ?: null, 
            'user_agent' => $userAgent,
            'ip_address' => $ip, 
            'ip_hash' => $ipHash, 
            'session_id' => $sessionId,
            'visitor_id' => $visitorId, 
            'duration_seconds' => $duration, 
            'page_count' => $pageCount, 
            'keyword' => $keyword ?: null,
            'is_mobile' => $isMobile ? 1 : 0, 
            'is_unique' => $isUnique ? 1 : 0,
            'is_proxy_risk' => (int) ($proxyRisk['risk'] ?? false),
            'country_name' => $countryName ?: null, 
            'region_name' => $regionName ?: null,
            'city_name' => $cityName ?: null, 
            'isp_domain' => $ispName ?: null,
            'country_code' => $countryCode ?: null, 
            'occurred_at' => $occurredAtStr,
        ];

        $sessionData = [
            'site_id' => $site['id'], 
            'session_id' => $sessionId, 
            'start_time' => $occurredAtStr, 
            'updated_at' => $occurredAtStr,
            'is_unique' => $isUnique ? 1 : 0, 
            'ip_address' => $ip, 
            'user_agent' => $userAgent,
            'entry_path' => $path, 
            'last_path' => $path, 
            'referrer' => $referrer ?: null,
            'keyword' => $keyword ?: null, 
            'engine' => $engine, 
            'country_name' => $countryName ?: null,
            'region_name' => $regionName ?: null, 
            'city_name' => $cityName ?: null,
            'duration_seconds' => $duration, 
            'page_count' => $pageCount
        ];
        // 【极客级优化：HLL 架构写入，包含防空判定及独立域名 HLL】
        $todayStr = date('Ymd', strtotime($occurredAtStr));
        $hourStr = date('YmdH', strtotime($occurredAtStr)); // 新增：小时级标识
        $sid = (int) $site['id'];
        
        $dailyIpKey = "site:{$sid}:hll_ip:{$todayStr}";
        $hourlyIpKey = "site:{$sid}:hll_ip:{$hourStr}"; // 新增：小时级全局 IP
        $dailyUvKey = "site:{$sid}:hll_uv:{$todayStr}";
        
        
        $device = $isMobile ? 'mobile' : 'desktop';
        $dailyDeviceIpKey = "site:{$sid}:hll_ip_{$device}:{$todayStr}";
        $dailyAudienceIpKey = "site:{$sid}:hll_ip_{$audienceLabel}:{$todayStr}";
        $hourlyDeviceIpKey = "site:{$sid}:hll_ip_{$device}:{$hourStr}"; // 新增：小时级设备 IP
        $dimHostVal = $canonicalHost ?: '未知域名';
        $dimHostDeviceVal = $dimHostVal . '|' . $device;
        // 使用 MD5 防止域名中的特殊符号破坏 Redis 结构
        $dailyHostKey = "site:{$sid}:hll_dim:host:" . md5($dimHostVal) . ":{$todayStr}";
        $dailyHostDeviceKey = "site:{$sid}:hll_dim:host_device:" . md5($dimHostDeviceVal) . ":{$todayStr}";
        
        // 严格防空判断，避免污染 HLL
        if ($ipHash) {
            $this->redis->pfAdd($dailyIpKey, [$ipHash]);
            $this->redis->pfAdd($hourlyIpKey, [$ipHash]);
            $this->redis->pfAdd($dailyDeviceIpKey, [$ipHash]);
            $this->redis->pfAdd($hourlyDeviceIpKey, [$ipHash]);
            $this->redis->pfAdd($dailyHostKey, [$ipHash]);
            $this->redis->pfAdd($dailyHostDeviceKey, [$ipHash]);
            $this->redis->pfAdd($dailyAudienceIpKey, [$ipHash]);
            $this->redis->expire($dailyIpKey, 86400 * 8);
            $this->redis->expire($dailyDeviceIpKey, 86400 * 8);
            $this->redis->expire($dailyHostKey, 86400 * 8);
            $this->redis->expire($dailyHostDeviceKey, 86400 * 8);
            $this->redis->expire($dailyAudienceIpKey, 86400 * 8);
            $this->redis->expire($hourlyIpKey, 86400 * 3);
            $this->redis->expire($hourlyDeviceIpKey, 86400 * 3);
        }
        if ($visitorId) {
            $this->redis->pfAdd($dailyUvKey, [$visitorId]);
            $this->redis->expire($dailyUvKey, 86400 * 8);
        }

        if ($batchMode) {
            return ['pageview' => $pageviewData, 'session' => $sessionData];
        }

        $this->executeBulkInsert('pageviews', array_keys($pageviewData), [$pageviewData]);
        $this->executeBulkInsert('sessions', array_keys($sessionData), [$sessionData], 
            'ON DUPLICATE KEY UPDATE last_path = VALUES(last_path), updated_at = VALUES(updated_at), duration_seconds = GREATEST(duration_seconds, VALUES(duration_seconds)), page_count = GREATEST(page_count, VALUES(page_count))'
        );
        
        return null;
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
private function truncateIpv6(?string $ip): ?string
    {
        if (!$ip) return $ip;
        
        // 【对齐大厂标准】
        // 为了在统计报表中呈现真实的独立 IP 量（对齐 51la），不再主动截断 IPv6 隐私扩展地址。
        // 将 WAF 级别的 /64 网段折叠逻辑保留给外部防火墙，不干预业务统计报表。
        return $ip; 
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

        // 【核心修复】：防止 1366 Incorrect string value 导致整批数据插入崩溃
        // 检查字符串是否为 100% 合法的 UTF-8 编码
        if (!mb_check_encoding($value, 'UTF-8')) {
            // 1. 在国内业务中，非 UTF-8 极大概率是搜索引擎的 GBK 编码（如百度的乱码）
            // 先尝试将其按 GBK 恢复为正常的 UTF-8 中文
            $value = @mb_convert_encoding($value, 'UTF-8', 'GBK');
            
            // 2. 兜底清洗：如果依然存在因 URL 强行截断导致的“残缺字节”（如 \xE8\xA7%）
            // 这行代码会将其彻底抹除或替换为问号，确保交给 MySQL 的绝对是纯净合法的 UTF-8
            $value = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
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
        $pathFilters = $this->ingestFilters['path_filters'] ?? '';
        $asnFilters = $this->ingestFilters['asn_filters'] ?? '';
        $uaFilters = $this->ingestFilters['ua_filters'] ?? '';

        if ($ip && $ipFilters !== '' && $this->ipMatchesFilters($ip, $ipFilters)) {
            return true;
        }

        if ($ip && $this->isBlockedProxyIp($ip)) {
            return true;
        }

        if ($keywordFilters !== '' && $this->keywordMatchesFilters($keyword, '', $referrer, $keywordFilters)) {
            return true;
        }

        if ($pathFilters !== '' && $this->keywordMatchesFilters('', $path, '', $pathFilters)) {
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

private function isChinaNetwork(string $country, array $asnMeta, string $ispDomain = ''): bool
    {
        $countryValue = trim($country);
        if ($countryValue !== '' && !str_contains($countryValue, '中国')) {
            return false;
        }

        $asnName = strtolower(trim((string) ($asnMeta['name'] ?? '')));
        $ispName = strtolower(trim((string) ($asnMeta['isp'] ?? '')));
        $combined = $asnName . ' ' . $ispName . ' ' . strtolower($ispDomain);

        $chinaIsps = [
            'china mobile', 'china unicom', 'china telecom', 'cmcc', 'unicom', 'chinanet', 'cnc', 'ct', 'cernet', 'cstnet',
            '移动', '联通', '电信', '广电', '铁通', '网通', '教育网', '科技网', '长城宽带', '鹏博士',
            '东方有线', '华数', '天威', '歌华', '方正宽带', '珠江宽带', '聚友', '艾普', '盈科', '视讯宽带', '宽带'
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


        // ==========================================
        // = 终极极速版：前置 IP/C段 蜘蛛白名单直通车 =
        // ==========================================
        try {
            $cClass = substr($ip, 0, strrpos($ip, '.')) . '.0';
            $keys = ["proxy:whitelist_rdns:{$ip}"];
            
            $spiders = ['bingbot', 'googlebot', 'bytespider', 'baiduspider', 'sogou', 'yisouspider', '360spider'];
            foreach ($spiders as $spider) {
                $keys[] = "bot:rdns:{$spider}:{$cClass}"; 
                $keys[] = "bot:rdns:{$spider}:{$ip}";     
            }
            
            $values = $this->redis->mGet($keys);
            if ($values) {
                foreach ($values as $val) {
                    if ($val === '1' || $val === 'ok') {
                        return ['blocked' => false, 'risk' => false, 'score' => 0, 'is_spider' => true];
                    }
                }
            }
        } catch (Throwable $e) {
        }
        // ==========================================

        $nowMs = (int) round(microtime(true) * 1000);
        $profileKey = "proxy:risk:{$uid}";
        
        $ingestOpts = $this->options['ingest'] ?? [];
        $riskScoreThreshold = $ingestOpts['risk_score_threshold'] ?? 80;
        $crossRegionThreshold = $ingestOpts['cross_region_threshold'] ?? 3;
        $highFreqThreshold = $ingestOpts['high_freq_threshold'] ?? 4;
        $sustainedFreqThreshold = $ingestOpts['sustained_freq_threshold'] ?? 5;
        $mediumRiskScore = $ingestOpts['medium_risk_score'] ?? 60;
        
        $sessionKey = $sessionId !== '' ? "proxy:session:{$sessionId}" : '';

        $asnValue = trim((string) ($asnMeta['number'] ?? $asnMeta['name'] ?? ''));
        $cityValue = trim($city);
        $regionValue = trim($region);
        $countryValue = trim($country);
        
        $geoMeta = $ip ? $this->resolveIpMeta($ip) : [];
        $ispDomain = trim($geoMeta['isp_domain'] ?? '');

        $isChinaNetwork = $this->isChinaNetwork($countryValue, $asnMeta, $ispDomain);
        $isDataCenterAsn = $this->isDataCenterAsn($asnMeta, $userAgent, $ispDomain);

        $uaSuspicious = $this->isSuspiciousUserAgent($userAgent, $headerMeta); // 传入 headerMeta
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

            $uniqueFpCount = 0;
            $isNat = false;
            if ($ip) {
                $natKey = "proxy:ip_fps:{$ip}";
                if ($fingerprint !== '' && ($duration > 3 || $pageCount > 1)) {
                    $this->redis->sAdd($natKey, $fingerprint);
                    $this->redis->expire($natKey, 3600);
                }
                $uniqueFpCount = (int) $this->redis->sCard($natKey);
                $isNat = $uniqueFpCount > 5;
            }

            $gapMs = $lastTs > 0 ? ($nowMs - $lastTs) : 0;
            if ($gapMs > 0) {
                $decaySteps = (int) floor($gapMs / 300000); 
                if ($decaySteps > 0) {
                    $score = (int) round($score * (0.75 ** $decaySteps)); 
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
                'baidu.com', 'sogou.com', 'so.com', 'google.com', 'bing.com', 'toutiao.com', 'quark.cn', 'sm.cn',
                'wechat.com', 'douyin.com', 'bilibili.com', 'weibo.com', 'zhihu.com',
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

            if ($uaSuspicious) {
                $score += 8; 
                $highFreqHits += 1;
            }

            if ($isDataCenterAsn) {
                $score += 30; 
                $highFreqHits += 1;
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
                $score += 10; 
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

            $dynamicMultiplier = $isNat ? max(1, (int)floor($uniqueFpCount / 2)) : 1;
            $windowLimit = $isNat ? (35 + $uniqueFpCount * 5) : 35;
            
            if ($windowCount > $windowLimit && $identityStable) {
                $score += 10;
                $highFreqHits += 1;
            }
            
            $hourLimit = 300 * $dynamicMultiplier;
            if ($hourCount > $hourLimit) {
                $score += 6;
            }
            
            $dayLimit = 2000 * $dynamicMultiplier;
            if ($dayCount > $dayLimit) {
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
                $isRisk = $score >= $riskScoreThreshold 
                    || ($crossRegionHits >= $crossRegionThreshold && $highFreqHits >= $highFreqThreshold) 
                    || ($score >= $mediumRiskScore && $sustainedHits >= $sustainedFreqThreshold);
                $this->markProxyRiskStatus($ipHash, $isRisk);
                return ['blocked' => false, 'risk' => $isRisk, 'score' => $score];
            }

            if ($sessionKey) {
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

$isRisk = $score >= $riskScoreThreshold 
                || ($crossRegionHits >= $crossRegionThreshold && $highFreqHits >= $highFreqThreshold) 
                || ($score >= $mediumRiskScore && $sustainedHits >= $sustainedFreqThreshold);

            if ($isRisk) {
                // ==========================================
                // = 新增：精准诊断拦截诱因，拒绝误报 =
                // ==========================================
                $reasonParts = [];
                if ($score >= $riskScoreThreshold) {
                    $reasonParts[] = "风险总分超限({$score}分)";
                }
                if ($crossRegionHits >= $crossRegionThreshold && $highFreqHits >= $highFreqThreshold) {
                    $reasonParts[] = "秒拨IP特征(跨省{$crossRegionHits}次+高频采集)";
                }
                if ($score >= $mediumRiskScore && $sustainedHits >= $sustainedFreqThreshold) {
                    $reasonParts[] = "持续高频采集(危险期内{$sustainedHits}次超限)";
                }
                if ($isDataCenterAsn) {
                    $reasonParts[] = "命中IDC机房/云服务器";
                }
                if ($uaSuspicious) {
                    $reasonParts[] = "疑似无头浏览器/爬虫工具";
                }
                if ($ipChanged && $ipChangeCount >= 3 && $highFreqHits >= 1 && !$isDataCenterAsn) {
                    $reasonParts[] = "同设备频繁秒换IP({$ipChangeCount}次)";
                }
                
                $reason = implode(' + ', $reasonParts);
                if (empty($reason)) {
                    $reason = "未分类异常组合 (评分:{$score}分)";
                }

                // 下面是原有的 Redis 处理逻辑
                $probationKey = "proxy:probation:{$ip}"; 
                $isProbation = (bool) $this->redis->get($probationKey);
                
                if (!$isProbation) {
                    $this->redis->setex($probationKey, $this->proxyProbationSeconds, '1');
                    $this->markProxyRiskStatus($ipHash, true);
                    return ['blocked' => false, 'risk' => true, 'score' => $score];
                }
                
                $blockType = ($isDataCenterAsn || $isForeign) ? 'IP全局封禁 (独立IP)' : '设备级封禁 (国内基站)';
                
                // ==========================================
                // = 核心修复：必须将 reason 写入 Redis！ =
                // ==========================================
                $logData = json_encode([
                    'ip' => $ip, 
                    'uid' => $uid, 
                    'type' => $blockType, 
                    'time' => date('Y-m-d H:i:s'), 
                    'score' => $score,
                    'reason' => $reason // <--- 增加这行，否则前端永远只能显示兜底词汇
                ], JSON_UNESCAPED_UNICODE);
                
                $this->redis->lPush('proxy:recent_blocks_log', $logData);
                $this->redis->lTrim('proxy:recent_blocks_log', 0, 999);
                $this->redis->incr('proxy:total_blocks_count');
                
if ($isDataCenterAsn || $isForeign) {
                    $this->rememberBlockedProxyIp($ip);
                    $this->redis->setex("proxy:blocked_exact_ip:{$ip}", 14400, '1'); 
                }
                
                // 修复：补全缺失的 $blockedKey 定义，使用与拦截判断一致的键名
                $networkId = $this->getNetworkIdentifier($ip);
                $blockedKey = "proxy:blocked_net:{$networkId}";
                
                $this->redis->setex($blockedKey, 14400, '1'); 
                return ['blocked' => true, 'risk' => true, 'score' => $score];
            }
        } catch (Throwable $e) {
            return ['blocked' => false, 'risk' => false, 'score' => 0];
        }

        $this->markProxyRiskStatus($ipHash, false);
        return ['blocked' => false, 'risk' => false, 'score' => $score];
    }

public function getBlockedProxyIps(int $limit = 200, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        try {
            $key = 'proxy:recent_blocks_log';
            $rows = $this->redis->lRange($key, $offset, $offset + $limit - 1);
            $results = [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $data = json_decode($row, true);
                    if ($data) {
                        $results[] = [
                            'ip' => $data['ip'] ?? '未知',
                            'uid' => $data['uid'] ?? '',
                            'type' => $data['type'] ?? '未知',
                            'score' => $data['score'] ?? 0,
                            // 这里读取上面存入的 reason，如果遇到存量旧数据，才显示未知
                            'reason' => $data['reason'] ?? '历史记录 (评分: ' . ($data['score'] ?? 0) . '分)',
                            'detected_at' => $data['time'] ?? '',
                        ];
                    }
                }
            }
            return $results;
        } catch (Throwable $e) {
            return [];
        }
    }

public function getBlockedProxyIpCount(): int
    {
        try {
            return (int) $this->redis->lLen('proxy:recent_blocks_log');
        } catch (Throwable $e) {
            return 0;
        }
    }

public function getTotalBlockedCount(): int
    {
        try {
            // 直接获取计数器的值，如果不存在或发生异常，直接返回 0
            return (int) $this->redis->get('proxy:total_blocks_count');
        } catch (Throwable $e) {
            return 0;
        }
    }
private function recordBlockedStats(int $siteId, ?string $ipHash, bool $isMobile): void
    {
        $todayStr = date('Ymd');
        $pvKey = "site:{$siteId}:blocked_pv:{$todayStr}";
        $pvMobileKey = "site:{$siteId}:blocked_pv_mobile:{$todayStr}";
        $hllIpKey = "site:{$siteId}:blocked_hll_ip:{$todayStr}";
        $hllIpMobileKey = "site:{$siteId}:blocked_hll_ip_mobile:{$todayStr}";

        $this->redis->incr($pvKey);
        $this->redis->expire($pvKey, 86400 * 3); // 仅保留3天缓存用于当日展示
        if ($ipHash) {
            $this->redis->pfAdd($hllIpKey, [$ipHash]);
            $this->redis->expire($hllIpKey, 86400 * 3);
        }

        if ($isMobile) {
            $this->redis->incr($pvMobileKey);
            $this->redis->expire($pvMobileKey, 86400 * 3);
            if ($ipHash) {
                $this->redis->pfAdd($hllIpMobileKey, [$ipHash]);
                $this->redis->expire($hllIpMobileKey, 86400 * 3);
            }
        }
    }

    public function getBlockedStats(int $siteId, string $range = 'today'): array
    {
        $dateStr = $range === 'yesterday' ? date('Ymd', strtotime('-1 day')) : date('Ymd');
        
        $pv = (int) $this->redis->get("site:{$siteId}:blocked_pv:{$dateStr}");
        $mobilePv = (int) $this->redis->get("site:{$siteId}:blocked_pv_mobile:{$dateStr}");
        $ip = (int) $this->redis->pfCount("site:{$siteId}:blocked_hll_ip:{$dateStr}");
        $mobileIp = (int) $this->redis->pfCount("site:{$siteId}:blocked_hll_ip_mobile:{$dateStr}");

        return [
            'pv' => $pv,
            'mobile_pv' => $mobilePv,
            'ip' => $ip,
            'mobile_ip' => $mobileIp,
        ];
    }
private function getNetworkIdentifier(string $ip): string
    {
        // 【核心防御】：恢复风控的网段级连坐机制！
        // 针对刷量团队的“IPv6秒拨池”，一旦触发风控，直接拉黑其整个 /64 网段
        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);
            if (count($parts) >= 4) {
                return implode(':', array_slice($parts, 0, 4)) . '::/64';
            }
            return $ip;
        }
        
        // 注意：IPv4 保持精确 IP 隔离。
        // 因为国内 IPv4 极度短缺存在大规模 NAT，封禁 IPv4 C段极易引发大面积误杀无辜用户。
        return $ip;
    }
   private function isBlockedProxyIp(string $ip): bool
    {
        try {
            $networkId = $this->getNetworkIdentifier($ip);
            if ($this->redis->get("proxy:blocked_net:{$networkId}")) {
                return true;
            }
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


    public function cleanupProxyHistory(int $batch = 100): int
    {
        $batch = max(1, $batch);
        $queueKey = 'proxy:cleanup_queue';

        try {
            $targets = $this->redis->zRange($queueKey, 0, $batch - 1, true);
        } catch (Throwable $e) {
            return 0;
        }

        if (empty($targets)) {
            return 0;
        }

        $processed = 0;
        foreach ($targets as $ip => $detectedAt) {
            // 【核心防御】：只有明确返回 true（无锁冲突且事务成功），才从 Redis 队列移除
            // 如果返回 false，保留在队列中，下一轮 Worker 唤醒时自动无损接管
            if ($this->cleanupProxyIpData((string) $ip) === true) {
                try {
                    $this->redis->zRem($queueKey, (string) $ip);
                    $processed++;
                } catch (Throwable $e) {
                }
            }
        }
        return $processed;
    }

private function cleanupProxyIpData(string $ip): bool 
    {
        $ip = trim($ip);
        if ($ip === '') {
            return true; 
        }

        $stmt = $this->db->prepare(
            "SELECT site_id, visitor_id,
                    DATE_FORMAT(occurred_at, '%Y-%m-%d %H:00:00') as bucket_start
             FROM pageviews 
             WHERE ip_address = :ip AND is_proxy_risk = 0
             GROUP BY site_id, visitor_id, bucket_start"
        );
        $stmt->execute([':ip' => $ip]);
        $badTraffic = $stmt->fetchAll();

        if (empty($badTraffic)) {
            return true; // 没有需要清洗的脏数据，直接视为成功
        }

        $badVisitors = array_values(array_unique(array_filter(array_column($badTraffic, 'visitor_id'))));

        $this->db->beginTransaction();
        try {
            $updateStmt = $this->db->prepare(
                'UPDATE pageviews SET is_proxy_risk = 1 WHERE ip_address = :ip AND is_proxy_risk = 0'
            );
            $updateStmt->execute([':ip' => $ip]);

            if (!empty($badVisitors)) {
                sort($badVisitors, SORT_STRING);
                $placeholders = implode(',', array_fill(0, count($badVisitors), '?'));
                $deleteAudience = $this->db->prepare("DELETE FROM site_visitor_audience WHERE visitor_id IN ($placeholders)");
                $deleteAudience->execute($badVisitors);
            }
            
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            // 【去掉重试的精髓】：遇到并发锁或任何异常，毫不犹豫直接回滚并返回 false！
            // 绝不阻塞进程，直接把任务还给 Redis 队列。
            return false; 
        }

        // 重新聚合受影响的按小时统计桶
        $processedBuckets = [];
        foreach ($badTraffic as $row) {
            $bucketKey = $row['site_id'] . '_' . $row['bucket_start'];
            if (isset($processedBuckets[$bucketKey])) continue;
            
            try {
                $bucketStartDt = new DateTimeImmutable($row['bucket_start']);
                $bucketEndDt = $bucketStartDt->modify('+1 hour');
                $this->rebuildRollupBucket((int)$row['site_id'], $bucketStartDt, $bucketEndDt);
                $processedBuckets[$bucketKey] = true;
            } catch (Throwable $e) {
                // 如果明细已清洗，即使重算报错也无所谓，下一次大盘 Rollup Worker 扫过时会自动修正
            }
        }
        
        return true; // 彻底执行完毕，返回 true 通知外层出队
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

    public function rebuildRollupBucket(int $siteId, DateTimeImmutable $bucketStart, DateTimeImmutable $bucketEnd): array
    {
        $bucketKey = $bucketStart->format('Y-m-d H:i:s');
        
        // 【架构优化】：引入细粒度分布式互斥锁，从根源消除同一数据桶的并发间隙锁冲突
        $lockKey = "tracker:lock:rollup:{$siteId}:" . md5($bucketKey);
        
        // 尝试获取锁，如果无法获取，说明其他 Worker 正在处理该桶，直接安全退出
        if (!$this->redis->setnx($lockKey, '1')) {
            return [
                'site_id' => $siteId,
                'bucket' => $bucketKey,
                'status' => 'skipped_due_to_lock'
            ];
        }
        // 强制设置过期时间（3分钟），防止 Worker 意外崩溃导致死锁孤岛
        $this->redis->expire($lockKey, 180);

        $start = $bucketStart->format('Y-m-d H:i:s');
        $end = $bucketEnd->format('Y-m-d H:i:s');
        $dayStart = $bucketStart->setTime(0, 0, 0);
        $dayEnd = $dayStart->modify('+1 day');
        $dayStartKey = $dayStart->format('Y-m-d H:i:s');
        $dayEndKey = $dayEnd->format('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
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
                 WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?"
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
                    WHERE site_id = ? AND session_id IS NOT NULL AND is_proxy_risk = 0
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
                    FROM pageviews p WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY dimension_value",
                'host_device' => "SELECT LEFT(CONCAT(COALESCE(canonical_host, '未知域名'), '|', IF(is_mobile = 1, 'mobile', 'desktop')), 255) as dimension_value,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                    FROM pageviews p WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY dimension_value",
                'device' => "SELECT IF(is_mobile = 1, 'mobile', 'desktop') as dimension_value,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                    FROM pageviews p WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY dimension_value",
                'browser' => "SELECT LEFT(" . $this->browserCase('p') . ", 255) as dimension_value,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                    FROM pageviews p WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY dimension_value",
                'referrer_host' => "SELECT dimension_value, pv, uv, new_uv, ips, sessions, duration_sum, page_sum, bounce_count
                    FROM (
                        SELECT LEFT(" . $this->referrerHostExpr('p') . ", 255) as dimension_value,
                            SUM(s.total_pv) as pv,
                            COUNT(DISTINCT p.ip_hash) as uv,
                            COUNT(DISTINCT CASE WHEN a.first_seen >= ? AND a.first_seen < ? THEN p.ip_hash END) as new_uv,
                            COUNT(DISTINCT p.ip_hash) as ips,
                            COUNT(*) as sessions,
                            SUM(s.max_duration) as duration_sum,
                            SUM(s.max_pages) as page_sum,
                            SUM(CASE WHEN s.max_pages <= 1 THEN 1 ELSE 0 END) as bounce_count
                        FROM (
                            SELECT session_id, MIN(id) as first_id, COUNT(*) as total_pv,
                                MAX(duration_seconds) as max_duration, MAX(page_count) as max_pages
                            FROM pageviews
                            WHERE site_id = ? AND session_id IS NOT NULL AND is_proxy_risk = 0
                              AND occurred_at >= ? AND occurred_at < ?
                            GROUP BY session_id
                        ) s
                        JOIN pageviews p ON p.id = s.first_id
                        LEFT JOIN site_visitor_audience a ON p.visitor_id = a.visitor_id AND p.site_id = a.site_id
                        GROUP BY dimension_value
                        ORDER BY ips DESC
                        LIMIT 500
                    ) t",
                'search_engine' => "SELECT LEFT(" . $this->searchEngineCase('p') . ", 255) as dimension_value,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                    FROM pageviews p WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY dimension_value",
                'search_engine_domain' => "SELECT LEFT(CONCAT(" . $this->searchEngineCase('p') . ", '|', COALESCE(canonical_host, host, '未知域名')), 255) as dimension_value,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                    FROM pageviews p WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY dimension_value",
                'keyword_engine' => "SELECT LEFT(CONCAT(COALESCE(keyword,''), '|', " . $this->searchEngineCase('p') . ", '|', COALESCE(canonical_host, host, s.domain, ''), COALESCE(NULLIF(path,''), '/')), 255) as dimension_value,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                    FROM pageviews p
                    JOIN sites s ON s.id = p.site_id
                    WHERE p.site_id = ? AND p.keyword IS NOT NULL AND keyword != '' AND p.is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?
                    GROUP BY dimension_value",
                'title' => "SELECT LEFT(title, 255) as dimension_value,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT ip_hash) as ips
                    FROM pageviews p 
                    WHERE site_id = ? 
                      AND title IS NOT NULL 
                      AND title != '' 
                      AND is_proxy_risk = 0 
                      AND occurred_at >= ? 
                      AND occurred_at < ?
                    GROUP BY dimension_value",
                'audience' => "SELECT LEFT(CASE WHEN a.first_seen >= ? AND a.first_seen < ? THEN 'new' ELSE 'returning' END, 255) as dimension_value,
                    COUNT(*) as pv, SUM(is_unique) as uv, COUNT(DISTINCT p.ip_hash) as ips
                    FROM pageviews p
                    LEFT JOIN site_visitor_audience a ON a.site_id = p.site_id AND a.visitor_id = p.visitor_id
                    WHERE p.site_id = ? AND p.is_proxy_risk = 0 AND p.occurred_at >= ? AND p.occurred_at < ?
                    GROUP BY dimension_value",
            ];

            foreach ($dimensionInserts as $dimension => $sql) {
                $useSessionMetrics = $dimension === 'referrer_host';
                $insert = $this->db->prepare(
                    'INSERT INTO pageview_dimension_rollups (site_id, bucket_start, dimension_type, dimension_value, pv, uv, new_uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
                     SELECT ?, ?, ?, dimension_value, pv, uv, ' .
                    ($useSessionMetrics ? 'new_uv' : '0') . ', ips, ' .
                    ($useSessionMetrics ? 'sessions, duration_sum, page_sum, bounce_count' : '0, 0, 0, 0') .
                    ' FROM (' . $sql . ') t'
                );
                if ($dimension === 'audience' || $dimension === 'referrer_host') {
                    $params = [$dayStartKey, $dayEndKey, $siteId, $start, $end];
                } else {
                    $params = [$siteId, $start, $end];
                }
                $insert->execute(array_merge([$siteId, $bucketKey, $dimension], $params));
            }

            $geoStmt = $this->db->prepare(
                "SELECT ip_address, ip_hash, COUNT(*) as pv, SUM(is_unique) as uv
                 FROM pageviews
                 WHERE site_id = ? AND is_proxy_risk = 0 AND occurred_at >= ? AND occurred_at < ?
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
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0)
                 ON DUPLICATE KEY UPDATE
                    pv = pv + VALUES(pv),
                    uv = uv + VALUES(uv),
                    ip_count = ip_count + VALUES(ip_count)'
            );

            foreach ($regionAgg as $label => $data) {
                $geoInsert->execute([$siteId, $bucketKey, 'region', mb_substr($label, 0, 255), $data['pv'], $data['uv'], $data['ips']]);
            }
            foreach ($countryAgg as $label => $data) {
                $geoInsert->execute([$siteId, $bucketKey, 'country', mb_substr($label, 0, 255), $data['pv'], $data['uv'], $data['ips']]);
            }
            foreach ($ispAgg as $label => $data) {
                $geoInsert->execute([$siteId, $bucketKey, 'isp', mb_substr($label, 0, 255), $data['pv'], $data['uv'], $data['ips']]);
            }

            $pageStmt = $this->db->prepare(
                "INSERT INTO pageview_page_rollups (site_id, bucket_start, path, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
                 SELECT ?, ?, path, pv, uv, ips, sessions, duration_sum, page_sum, bounce_count
                 FROM (
                    SELECT LEFT(COALESCE(p.path,'/'), 512) as path,
                        COUNT(*) as pv, 
                        SUM(p.is_unique) as uv, 
                        COUNT(DISTINCT p.ip_hash) as ips,
                        COUNT(DISTINCT p.session_id) as sessions,
                        SUM(s.max_duration) as duration_sum,
                        SUM(s.max_pages) as page_sum,
                        SUM(CASE WHEN s.max_pages <= 1 THEN 1 ELSE 0 END) as bounce_count
                    FROM pageviews p
                    JOIN (
                        SELECT session_id,
                               MAX(duration_seconds) as max_duration,
                               MAX(page_count) as max_pages
                        FROM pageviews
                        WHERE site_id = ? AND session_id IS NOT NULL AND is_proxy_risk = 0
                          AND occurred_at >= ? AND occurred_at < ?
                        GROUP BY session_id
                    ) s ON p.session_id = s.session_id
                    WHERE p.site_id = ? AND p.is_proxy_risk = 0 AND p.occurred_at >= ? AND p.occurred_at < ?
                    GROUP BY path
                    ORDER BY ips DESC
                    LIMIT 500
                 ) t"
            );
            $pageStmt->execute([$siteId, $bucketKey, $siteId, $start, $end, $siteId, $start, $end]);

            $entryStmt = $this->db->prepare(
                "INSERT INTO pageview_entry_rollups (site_id, bucket_start, path, pv, uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
                 SELECT ?, ?, path, pv, uv, ips, session_count, duration_sum, page_sum, bounce_count
                 FROM (
                    SELECT LEFT(COALESCE(entry.path, '/'), 512) as path,
                        SUM(s.total_pv) as pv, 
                        SUM(s.is_unique) as uv, 
                        COUNT(DISTINCT entry.ip_hash) as ips,
                        COUNT(*) as session_count,
                        SUM(s.max_duration) as duration_sum,
                        SUM(s.max_pages) as page_sum,
                        SUM(CASE WHEN s.max_pages <= 1 THEN 1 ELSE 0 END) as bounce_count
                    FROM (
                        SELECT session_id,
                               MIN(id) as first_id,
                               COUNT(*) as total_pv,
                               MAX(is_unique) as is_unique,
                               MAX(duration_seconds) as max_duration,
                               MAX(page_count) as max_pages
                        FROM pageviews
                        WHERE site_id = ? AND session_id IS NOT NULL AND is_proxy_risk = 0
                          AND occurred_at >= ? AND occurred_at < ?
                        GROUP BY session_id
                    ) s
                    JOIN pageviews entry ON entry.id = s.first_id
                    GROUP BY path
                    ORDER BY ips DESC
                    LIMIT 500
                 ) t"
            );
            $entryStmt->execute([$siteId, $bucketKey, $siteId, $start, $end]);

            $this->db->commit();
            
            // 事务提交成功，释放锁
            $this->redis->del($lockKey);

            return [
                'site_id' => $siteId,
                'bucket' => $bucketKey,
                'pv' => (int) ($totals['views'] ?? 0),
                'uv' => (int) ($totals['uniques'] ?? 0),
                'ips' => (int) ($totals['ips'] ?? 0),
                'sessions' => (int) ($sessions['sessions'] ?? 0),
            ];
        } catch (Throwable $e) {
            // 【修复】：加一层判定和捕获，防止在死掉的连接上执行 rollBack 再次抛出异常
            try {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            } catch (Throwable $rollbackException) {
                // 忽略 rollBack 失败（通常是因为连接本身已经断开了）
            }
            // 发生异常，释放锁
            $this->redis->del($lockKey);
            return [
                'site_id' => $siteId,
                'bucket' => $bucketKey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }
    }

    private function referrerHostExpr(string $alias): string
    {
        return "COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX({$alias}.referrer, '/', 3), '//', -1), ''), '直接访问')";
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

        $pageviewsBatch = [];
        $sessionsBatch = [];
        $rawItems = []; // 【修复】新增：暂存这批次取出的原始数据，等待事务成功后再清理

        while ($processed < $maxBatch) {
            $raw = $this->redis->rPopLPush($this->ingestQueueKey, $this->ingestProcessingKey);
            if ($raw === false || $raw === null) break;

            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || empty($decoded['tracking_id']) || !isset($decoded['payload'])) {
                $this->redis->lRem($this->ingestProcessingKey, $raw, 1);
                continue;
            }

            try {
                $receivedAt = (int) ($decoded['received_at'] ?? time());
                $data = $this->processPageview($decoded['tracking_id'], $decoded['payload'], $receivedAt, true);
                
                if ($data) {
                    if (!empty($data['pageview'])) {
                        $pageviewsBatch[] = $data['pageview'];
                    }
                    if (!empty($data['session'])) {
                        $sessionsBatch[] = $data['session'];
                    }
                }
                
                // 【修复】禁止在这里 lRem！只需记录下来。
                $rawItems[] = $raw;
                $processed++;
            } catch (Throwable $e) {
                // Ignore, 留存队列以便恢复
            }
        }

        // 【最极致的 IO 优化】：事务包裹下的分块批量落地
        if (!empty($pageviewsBatch) || !empty($sessionsBatch)) {
            $this->db->beginTransaction();
            try {
                if (!empty($pageviewsBatch)) {
                    $this->executeBulkInsert('pageviews', array_keys($pageviewsBatch[0]), $pageviewsBatch);
                }
                if (!empty($sessionsBatch)) {
                    usort($sessionsBatch, function ($a, $b) {
                        return strcmp($a['session_id'], $b['session_id']);
                    });
                    
                    $this->executeBulkInsert('sessions', array_keys($sessionsBatch[0]), $sessionsBatch, 
                        'ON DUPLICATE KEY UPDATE last_path = VALUES(last_path), updated_at = VALUES(updated_at), duration_seconds = GREATEST(duration_seconds, VALUES(duration_seconds)), page_count = GREATEST(page_count, VALUES(page_count))'
                    );
                }
                $this->db->commit();
                
                // 【核心修复】：数据库 100% 落地成功后，才批量从 Redis processing 队列中抹除！
                foreach ($rawItems as $raw) {
                    $this->redis->lRem($this->ingestProcessingKey, $raw, 1);
                }
                
            } catch (PDOException $e) {
                $this->db->rollBack();
                if ($e->getCode() === '40001' || str_contains($e->getMessage(), '1213') || str_contains($e->getMessage(), '1205')) {
                    error_log("Ingest Queue Auto-Recovered from Deadlock. Batch kept in processing list. Error: " . $e->getMessage());
                } else {
                    error_log("Bulk Insert PDO Error: " . $e->getMessage());
                }
                // 【核心修复】事务失败，直接 return 0，数据仍留在 processing 列表中
                // 等待 $this->ingestStalledAfter 秒后，会被 recoverStalledIngestQueue 自动捞回队列重试，零丢失！
                return 0;
            } catch (Throwable $e) {
                $this->db->rollBack();
                error_log("Bulk Insert Error: " . $e->getMessage());
                return 0;
            }
        } else {
            // 如果全部都是非法/被过滤流量（没有入库内容），也需要清理掉它们
            foreach ($rawItems as $raw) {
                $this->redis->lRem($this->ingestProcessingKey, $raw, 1);
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
            if ($canonicalHost !== '') {
                $entries[] = ['search_engine_domain', $this->limitText($engine . '|' . $canonicalHost, 255)];
            }
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
            'INSERT INTO pageview_dimension_rollups (site_id, bucket_start, dimension_type, dimension_value, pv, uv, new_uv, ip_count, session_count, duration_sum, page_sum, bounce_count)
             VALUES (:site_id, :bucket_start, :dimension_type, :dimension_value, 1, :uv, 0, :ip_count, :session_count, :duration_sum, :page_sum, :bounce_count)
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
            'SELECT dimension_value, SUM(pv) as views, SUM(uv) as uniques, SUM(new_uv) as new_uv, SUM(ip_count) as ips, SUM(session_count) as sessions, SUM(duration_sum) as duration_sum, SUM(page_sum) as page_sum, SUM(bounce_count) as bounce_count
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

        // 【极客级优化】：在列表展示层用 HLL 精确覆盖 SQL 的虚高累加 (单站)
        if ($dimension === 'host' || $dimension === 'host_device') {
            $now = new DateTimeImmutable('now');
            if ($start->diff($now)->days <= 8) {
                $dates = [];
                $current = $span['start'];
                while ($current < $span['end']) {
                    $dates[] = $current->format('Ymd');
                    $current = $current->modify('+1 day')->setTime(0, 0, 0);
                }

                foreach ($rows as &$row) {
                    $dimHash = md5($row['dimension_value']);
                    $keys = [];
                    foreach ($dates as $ymd) {
                        $keys[] = "site:{$siteId}:hll_dim:{$dimension}:{$dimHash}:{$ymd}";
                    }
                    if (!empty($keys)) {
                        $hllIp = (int) $this->redis->pfCount($keys);
                        if ($hllIp > 0) {
                            $row['ips'] = $hllIp;
                            $row['ip_count'] = $hllIp;
                        }
                    }
                }
            }
        }

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

        return $this->recalcRollupIps([$siteId], 'entry_path', $span['start'], $span['end'], $rows);
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

        // 【极客级优化】：多站聚合底层 HLL 跨站精确拦截 (分享页)
        if ($dimension === 'host' || $dimension === 'host_device') {
            $now = new DateTimeImmutable('now');
            if ($start->diff($now)->days <= 8) {
                $dates = [];
                $current = $start;
                while ($current < $end) {
                    $dates[] = $current->format('Ymd');
                    $current = $current->modify('+1 day')->setTime(0, 0, 0);
                }

                foreach ($rows as &$row) {
                    $dimHash = md5($row['dimension_value']);
                    $keys = [];
                    foreach ($siteIds as $sid) {
                        foreach ($dates as $ymd) {
                            $keys[] = "site:{$sid}:hll_dim:{$dimension}:{$dimHash}:{$ymd}";
                        }
                    }
                    if (!empty($keys)) {
                        $hllIp = (int) $this->redis->pfCount($keys);
                        if ($hllIp > 0) {
                            $row['ips'] = $hllIp;
                            $row['ip_count'] = $hllIp;
                        }
                    }
                }
            }
        }

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


private function markVisitorAudienceState(int $siteId, string $visitorId): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO site_visitor_audience (site_id, visitor_id, first_seen, last_seen_date)
             VALUES (:site_id, :visitor_id, NOW(), CURRENT_DATE())
             ON DUPLICATE KEY UPDATE last_seen_date = VALUES(last_seen_date)'
        );

        $stmt->execute([
            ':site_id' => $siteId,
            ':visitor_id' => $visitorId,
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
            str_contains($ua, 'com.xunlei.browser') || str_contains($ua, 'com.xunlei.downloadprovider')  => '迅雷',
            str_contains($ua, 'mark.via') => 'Via',
            str_contains($ua, 'micromessenger') => '微信',
            str_contains($ua, 'dingtalk') => '钉钉',
            str_contains($ua, 'qq/') => 'QQ',
            (bool) preg_match('/mqqbrowser|qqbrowser/', $ua) => 'QQ',
            str_contains($ua, 'ucbrowser') || str_contains($ua, 'ubrowser') => 'UC',
            str_contains($ua, 'quark') => '夸克',
            str_contains($ua, 'baiduboxapp') || str_contains($ua, 'baidubrowser') || str_contains($ua, 'baidu') => '百度',
            str_contains($ua, 'sogoumse') || str_contains($ua, 'metasr') || str_contains($ua, 'sogoumobilebrowser') || str_contains($ua, 'sogou') => '搜狗',
            str_contains($ua, '2345explorer') || str_contains($ua, '2345chrome') || str_contains($ua, 'mb2345') => '2345',
            str_contains($ua, 'lbbrowser') => '猎豹',
            str_contains($ua, '360se') || str_contains($ua, '360ee') || str_contains($ua, 'qihoobrowser') => '360',
            str_contains($ua, 'wukong-browser') => '悟空 PC',
            str_contains($ua, 'goldbrowser') || str_contains($ua, 'bytedancewebview') => '悟空',
            str_contains($ua, 'xiaomi') || str_contains($ua, 'miuibrowser') => '小米',
            str_contains($ua, 'huaweibrowser') || str_contains($ua, 'huawei') || str_contains($ua, 'hbpc') => '华为',
            str_contains($ua, 'vivobrowser') => 'Vivo',
            str_contains($ua, 'heytapbrowser') => 'HeyTap',
            str_contains($ua, 'oppobrowser') => 'Oppo',
            str_contains($ua, 'slbrowser') || str_contains($ua, 'lenovo') => '联想',
            str_contains($ua, 'samsungbrowser') => '三星',
            (bool) preg_match('/edg(\/|a|ios)|edge/', $ua) => 'Edge',
            (bool) preg_match('/chrome|crios/', $ua) => 'Chrome',
            (bool) preg_match('/firefox|fxios/', $ua) => 'Firefox',
            (bool) preg_match('/msie|trident/', $ua) => 'IE',
            (bool) preg_match('/safari/', $ua) && !(bool) preg_match('/chrome|crios|edg|edge/', $ua) => 'Safari',
            default => '其他'
        };
    }

    private function browserCase(string $alias = ''): string
    {
        $prefix = $alias ? $alias . '.' : '';
        return "CASE
            WHEN LOWER({$prefix}user_agent) REGEXP 'com.xunlei.browser|com.xunlei.downloadprovider' THEN '迅雷'
            WHEN LOWER({$prefix}user_agent) REGEXP 'mark.via' THEN 'Via'
            WHEN LOWER({$prefix}user_agent) REGEXP 'micromessenger' THEN '微信'
            WHEN LOWER({$prefix}user_agent) REGEXP 'dingtalk' THEN '钉钉'
            WHEN LOWER({$prefix}user_agent) REGEXP 'qq/' THEN 'QQ'
            WHEN LOWER({$prefix}user_agent) REGEXP 'mqqbrowser|qqbrowser' THEN 'QQ'
            WHEN LOWER({$prefix}user_agent) REGEXP 'ucbrowser|ubrowser' THEN 'UC'
            WHEN LOWER({$prefix}user_agent) REGEXP 'quark' THEN '夸克'
            WHEN LOWER({$prefix}user_agent) REGEXP 'baiduboxapp|baidubrowser|baidu' THEN '百度'
            WHEN LOWER({$prefix}user_agent) REGEXP 'sogoumse|metasr|sogoumobilebrowser|sogou' THEN '搜狗'
            WHEN LOWER({$prefix}user_agent) REGEXP '2345explorer|2345chrome|mb2345' THEN '2345'
            WHEN LOWER({$prefix}user_agent) REGEXP 'lbbrowser' THEN '猎豹'
            WHEN LOWER({$prefix}user_agent) REGEXP '360se|360ee|qihoobrowser' THEN '360'
            WHEN LOWER({$prefix}user_agent) REGEXP 'wukong-browser' THEN '悟空 PC'
            WHEN LOWER({$prefix}user_agent) REGEXP 'goldbrowser|bytedancewebview' THEN '悟空'
            WHEN LOWER({$prefix}user_agent) REGEXP 'xiaomi|miuibrowser' THEN '小米'
            WHEN LOWER({$prefix}user_agent) REGEXP 'huaweibrowser|huawei|hbpc' THEN '华为'
            WHEN LOWER({$prefix}user_agent) REGEXP 'vivobrowser' THEN 'Vivo'
            WHEN LOWER({$prefix}user_agent) REGEXP 'heytapbrowser' THEN 'HeyTap'
            WHEN LOWER({$prefix}user_agent) REGEXP 'oppobrowser' THEN 'Oppo'
            WHEN LOWER({$prefix}user_agent) REGEXP 'slbrowser|lenovo' THEN '联想'
            WHEN LOWER({$prefix}user_agent) REGEXP 'samsungbrowser' THEN '三星'
            WHEN LOWER({$prefix}user_agent) REGEXP 'edg(/|a|ios)|edge' THEN 'Edge'
            WHEN LOWER({$prefix}user_agent) REGEXP 'chrome|crios' THEN 'Chrome'
            WHEN LOWER({$prefix}user_agent) REGEXP 'firefox|fxios' THEN 'Firefox'
            WHEN LOWER({$prefix}user_agent) REGEXP 'msie|trident' THEN 'IE'
            WHEN LOWER({$prefix}user_agent) REGEXP 'safari' AND LOWER({$prefix}user_agent) NOT REGEXP 'chrome|crios|edg|edge' THEN 'Safari'
            ELSE '其他'
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
            str_contains($ref, 'toutiao.com') || str_contains($ua, 'bytespider') || str_contains($ref, 'douyin.com') => '头条',
            str_contains($ref, 'sogou.com') || str_contains($ua, 'sogou') => '搜狗',
            str_contains($ref, 'sm.cn') || str_contains($ua, 'yisouspider') => '神马',
            str_contains($ref, 'yahoo.com') || str_contains($ua, 'yahoo') => '雅虎',
            str_contains($ref, 'duckduckgo.com') || str_contains($ua, 'duckduckbot') => 'DuckDuckGo',
            str_contains($ua, 'petalbot') => '华为',
            str_contains($ref, 'quark.cn') => '夸克',
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
        return '海外';
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

        // 修改：使用 LEFT JOIN 从维度表(device=mobile)把移动端 IP 聚合出来
        $statement = $this->db->prepare(
            "SELECT DATE(r.bucket_start) as day, 
                    SUM(r.pv) as views, 
                    SUM(r.uv) as uniques, 
                    SUM(r.ip_count) as ip_count,
                    SUM(COALESCE(d.ip_count, 0)) as mobile_ips
             FROM pageview_rollups r
             LEFT JOIN pageview_dimension_rollups d 
               ON r.site_id = d.site_id AND r.bucket_start = d.bucket_start AND d.dimension_type = 'device' AND d.dimension_value = 'mobile'
             WHERE r.site_id = :site_id AND r.bucket_start >= :start AND r.bucket_start < :end
             GROUP BY day
             ORDER BY day ASC"
        );

        $statement->execute([
            ':site_id' => $siteId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
        ]);

        $rows = $statement->fetchAll();

        foreach ($rows as &$row) {
            $ymd = date('Ymd', strtotime($row['day']));
            $ipKey = "site:{$siteId}:hll_ip:{$ymd}";
            $uvKey = "site:{$siteId}:hll_uv:{$ymd}";
            // 新增：提取 Redis 里的移动端 IP 精确值
            $mobileIpKey = "site:{$siteId}:hll_ip_mobile:{$ymd}"; 
            
            if ($this->redis->exists($ipKey)) {
                $row['ip_count'] = (int) $this->redis->pfCount($ipKey);
            }
            if ($this->redis->exists($uvKey)) {
                $row['uniques'] = (int) $this->redis->pfCount($uvKey);
            }
            if ($this->redis->exists($mobileIpKey)) {
                $row['mobile_ips'] = (int) $this->redis->pfCount($mobileIpKey);
            }
        }

        return $rows;
    }

    private function getRollupHourlyStats(int $siteId, DateTimeImmutable $start, DateTimeImmutable $end, bool $allowPartial = false): array
    {
        if (!$allowPartial && !$this->rollupsCoverRange($siteId, $start, $end)) {
            return [];
        }

        // 修改：连表查出 hourly 维度的移动端 IP
        $statement = $this->db->prepare(
            "SELECT r.bucket_start as hour, 
                    r.pv as views, 
                    r.uv as uniques, 
                    r.ip_count as ips,
                    COALESCE(d.ip_count, 0) as mobile_ips
             FROM pageview_rollups r
             LEFT JOIN pageview_dimension_rollups d 
               ON r.site_id = d.site_id AND r.bucket_start = d.bucket_start AND d.dimension_type = 'device' AND d.dimension_value = 'mobile'
             WHERE r.site_id = :site_id AND r.bucket_start >= :start AND r.bucket_start < :end
             ORDER BY r.bucket_start ASC"
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

    public function getSearchEngineData(int $siteId, string $range = 'today', ?string $domain = null): array
    {
        return [
            'engines' => $this->getSearchEngines($siteId, $range, $domain),
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
        'browsers' => $this->getBrowserBreakdown($siteId, $range, 50), 
    ];
}

    public function getRegionData(int $siteId, string $range = 'today'): array
    {
        return [
            'regions' => $this->getRegionStats($siteId, $range, 200),
            'countries' => $this->getCountryStats($siteId, $range, 200),
        ];
    }

    public function getIspData(int $siteId, string $range = 'today', int $page = 1, int $perPage = 50): array
    {
        return $this->getIspStatsPaged($siteId, $range, $page, $perPage);
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
private function getHllKeysForRange(int $siteId, string $prefix, string $range): array 
    {
        // 【关键修复】：如果查询范围是 'all' 或超过 8 天(缓存周期)，放弃 HLL 精确计算，触发外层安全降级
        if ($range === 'all' || $range === '30d') {
            return []; 
        }

        [$start, $end] = $this->rollupRangeBounds($range);
        
        $now = new DateTimeImmutable('now');
        if ($start < $now->modify('-8 days')) {
            return []; // 兜底防御，防止由于自定义时间跨度过大导致数据丢失
        }

        $keys = [];
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);
        foreach ($period as $dt) {
            $keys[] = "site:{$siteId}:{$prefix}:" . $dt->format('Ymd');
        }
        return $keys;
    }
    public function getTotals(int $siteId, string $range): array
    {
        $cacheKey = "totals:{$siteId}:{$range}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $rollupTotals = $this->aggregateTotalsWithRollups($siteId, $start, $end);

            // 【后续调整 3】：使用 Redis PFCOUNT 覆盖 SQL 的 SUM() 机械相加
            $ipKeys = $this->getHllKeysForRange($siteId, 'hll_ip', $range);
            $uvKeys = $this->getHllKeysForRange($siteId, 'hll_uv', $range);
            
            if (!empty($ipKeys)) {
                $hllIp = (int) $this->redis->pfCount($ipKeys);
                if ($hllIp > 0) $rollupTotals['ip_count'] = $hllIp;
            }
            if (!empty($uvKeys)) {
                $hllUv = (int) $this->redis->pfCount($uvKeys);
                if ($hllUv > 0) $rollupTotals['uniques'] = $hllUv;
            }

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

        // 【修复】：读取真实的全局新访客数据
        $audience = $this->getNewVsReturning($siteId, $range);
        $realNewVisitors = (int) ($audience['new'] ?? 0);

        $rows = $this->getEntryRollupRows($siteId, $range, 200);

        return [
            'summary' => [
                'ips' => (int) ($summary['ips'] ?? 0),
                'views' => (int) ($summary['views'] ?? 0),
                'uv' => (int) ($summary['uv'] ?? ($summary['uniques'] ?? 0)),
                'new' => $realNewVisitors, // 使用真实数据
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

        // 【修复】：读取真实的全局新访客数据
        $audience = $this->getNewVsReturning($siteId, $range);
        $realNewVisitors = (int) ($audience['new'] ?? 0);

        $rollupRows = $this->getPageRollupRows($siteId, $range, 200);

        return [
            'summary' => [
                'ips' => (int) ($summary['ips'] ?? 0),
                'views' => (int) ($summary['views'] ?? 0),
                'uv' => (int) ($summary['uv'] ?? ($summary['uniques'] ?? 0)),
                'new' => $realNewVisitors, // 使用真实数据
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
            $referrer = trim($row['dimension_value'] ?? '');
            
            // 【新增】：如果没有来路，标记为直接访问并放行显示
            if ($referrer === '') {
                $referrer = '直接访问';
            }
            
            // 剔除自有域名（但必须保留直接访问）
            if ($referrer !== '直接访问' && $domains && $this->isOwnReferrer($referrer, $domains)) {
                continue;
            }

            // 提前计算好所有的指标，避免 Undefined variable 报错
            $sessions = (int) ($row['sessions'] ?? 0);
            $avgPages = $sessions > 0 ? (float) ($row['page_sum'] ?? 0) / $sessions : 0;
            $avgDuration = $sessions > 0 ? (float) ($row['duration_sum'] ?? 0) / $sessions : 0;
            $bounceRate = $sessions > 0 ? (float) ($row['bounce_count'] ?? 0) / $sessions : 0;

            $filtered[] = [
                'referrer' => $referrer,
                'sessions' => $sessions,
                'ips' => (int) ($row['ips'] ?? 0),
                'uniques' => (int) ($row['uniques'] ?? 0),
                'new' => (int) ($row['new_uv'] ?? 0), // 读取真实新访客数据
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
            // 【提速核心】：不再去查库，直接从 worker 预热好的聚合列表里把新访客加起来
            $totals['new'] += (int) ($row['new'] ?? 0); 
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
        
        // 彻底删掉了调用 getNewVsReturning 的逻辑，实现 0 次查询 pageviews 明细表

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
                "SELECT COUNT(*) as sessions
                 FROM sessions
                 WHERE site_id = :site_id AND updated_at >= DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)"
            );
            $stmt->execute([':site_id' => $siteId]);
            $results[$minutes] = (int)($stmt->fetchColumn() ?: 0);
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

        $conditions = ['site_id = :site_id', 'start_time BETWEEN :start AND :end'];
        $params = [
            ':site_id' => $siteId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
        ];

        if (!empty($filters['ip'])) {
            $conditions[] = 'ip_address LIKE :ip';
            $params[':ip'] = '%' . $filters['ip'] . '%';
        }
        if (!empty($filters['keyword'])) {
            $conditions[] = 'keyword LIKE :keyword';
            $params[':keyword'] = '%' . $filters['keyword'] . '%';
        }
        if (!empty($filters['entry'])) {
            $conditions[] = 'entry_path LIKE :entry';
            $params[':entry'] = '%' . $filters['entry'] . '%';
        }
        if (!empty($filters['session'])) {
            $conditions[] = 'session_id LIKE :session';
            $params[':session'] = '%' . $filters['session'] . '%';
        }
        if (!empty($filters['visitor']) && in_array($filters['visitor'], ['new', 'return'], true)) {
            $conditions[] = $filters['visitor'] === 'new' ? 'is_unique = 1' : 'is_unique = 0';
        }
        if (!empty($filters['engine'])) {
            $conditions[] = 'engine = :engine';
            $params[':engine'] = $filters['engine'];
        }
        if (!empty($filters['city'])) {
            $conditions[] = "COALESCE(NULLIF(city_name,''), NULLIF(region_name,''), NULLIF(country_name,''), '未知') LIKE :city";
            $params[':city'] = '%' . $filters['city'] . '%';
        }

        $sql = "SELECT COUNT(*) as total FROM sessions WHERE " . implode(' AND ', $conditions);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)(($stmt->fetch())['total'] ?? 0);
    }

    private function getVisitDetails(int $siteId, array $filters, int $page = 1, int $perPage = 50): array
    {
        [$start, $end] = $this->visitFiltersWindow($filters);
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $conditions = ['site_id = :site_id', 'start_time BETWEEN :start AND :end'];
        $params = [
            ':site_id' => $siteId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s')
        ];

        if (!empty($filters['ip'])) {
            $conditions[] = 'ip_address LIKE :ip';
            $params[':ip'] = '%' . $filters['ip'] . '%';
        }
        if (!empty($filters['keyword'])) {
            $conditions[] = 'keyword LIKE :keyword';
            $params[':keyword'] = '%' . $filters['keyword'] . '%';
        }
        if (!empty($filters['entry'])) {
            $conditions[] = 'entry_path LIKE :entry';
            $params[':entry'] = '%' . $filters['entry'] . '%';
        }
        if (!empty($filters['session'])) {
            $conditions[] = 'session_id LIKE :session';
            $params[':session'] = '%' . $filters['session'] . '%';
        }
        if (!empty($filters['visitor']) && in_array($filters['visitor'], ['new', 'return'], true)) {
            $conditions[] = $filters['visitor'] === 'new' ? 'is_unique = 1' : 'is_unique = 0';
        }
        if (!empty($filters['city'])) {
            $conditions[] = "COALESCE(NULLIF(city_name,''), NULLIF(region_name,''), NULLIF(country_name,''), '未知') LIKE :city";
            $params[':city'] = '%' . $filters['city'] . '%';
        }
        if (!empty($filters['engine'])) {
            $conditions[] = 'engine = :engine';
            $params[':engine'] = $filters['engine'];
        }

        $sql = "SELECT
                    start_time as occurred_at,
                    updated_at,
                    session_id,
                    ip_address,
                    is_unique,
                    user_agent,
                    referrer,
                    last_path as path,
                    keyword,
                    duration_seconds,
                    page_count,
                    entry_path,
                    COALESCE(NULLIF(city_name,''), NULLIF(region_name,''), NULLIF(country_name,''), '未知') as region,
                    engine
                FROM sessions
                WHERE " . implode(' AND ', $conditions) . "
                ORDER BY start_time DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

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
            
            $rollup = $this->aggregateDimensionRollups($siteId, 'keyword_engine', $span['start'], $span['end'], 6000);
            $keywordFilters = $this->ingestFilters['keyword_filters'] ?? '';

            if (!empty($rollup)) {
                $keywords = [];
                foreach ($rollup as $row) {
                    [$keyword, $engine, $entry] = array_pad(explode('|', $row['dimension_value'] ?? '', 3), 3, '');
                    $keyword = trim((string)$keyword);
                    if ($keyword === '') {
                        continue;
                    }
                    
                    // 1. 系统级过滤：丢弃纯小写英文和纯小写英文加数字的垃圾词
                    if (preg_match('/^[a-z0-9]+$/', $keyword) && preg_match('/[a-z]/', $keyword)) {
                        continue;
                    }
                    
                    // 2. 用户级过滤：丢弃后台设置的屏蔽词
                    if ($keywordFilters !== '' && $this->keywordMatchesFilters($keyword, '', '', $keywordFilters)) {
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
                    $keywords[$keyword]['engines'][] = $engine ?: '未知';
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
private function applyBotFilters(?string $engine, ?string $domain, array &$params): string
    {
        $sql = '';
        if ($engine !== null && $engine !== '' && $engine !== 'all') {
            $sql .= " AND engine = :engine";
            $params[':engine'] = $engine;
        }
        if ($domain !== null && $domain !== '' && $domain !== 'all') {
            $sql .= " AND domain = :domain";
            $params[':domain'] = $domain;
        }
        return $sql;
    }
    private function getBotTraffic(int $siteId, string $range, ?string $engine = null, ?string $domain = null, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $cacheKey = "bots:{$siteId}:{$range}:" . ($engine ?? 'all') . ':' . ($domain ?? 'all') . ":{$page}:{$perPage}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $engine, $domain, $page, $perPage) {
            [$rangeSql, $params] = $this->rangeClause($range);
            $filterSql = $this->applyBotFilters($engine, $domain, $params); // 复用

            $params[':limit'] = $perPage;
            $params[':offset'] = ($page - 1) * $perPage;

            $statement = $this->db->prepare(
                "SELECT path, referrer, user_agent, ip_address, domain, occurred_at, engine
                FROM pageview_bot_logs
                WHERE site_id = :site_id {$rangeSql}{$filterSql}
                ORDER BY occurred_at DESC LIMIT :limit OFFSET :offset"
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
            $filterSql = $this->applyBotFilters($engine, $domain, $params); // 复用

            $statement = $this->db->prepare(
                "SELECT COUNT(*) FROM pageview_bot_logs WHERE site_id = :site_id {$rangeSql}{$filterSql}"
            );
            $statement->execute(array_merge([':site_id' => $siteId], $params));

            return ['total' => (int) $statement->fetchColumn()];
        });
    }

    private function getBotEngines(int $siteId, string $range, ?string $domain = null): array
    {
        $cacheKey = "bot_engines:{$siteId}:{$range}:" . ($domain ?? 'all');

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $domain) {
            [$rangeSql, $params] = $this->rangeClause($range);
            $filterSql = $this->applyBotFilters(null, $domain, $params); // 复用

            $statement = $this->db->prepare(
                "SELECT engine, COUNT(*) as total FROM pageview_bot_logs 
                WHERE site_id = :site_id {$rangeSql}{$filterSql} 
                GROUP BY engine ORDER BY total DESC"
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
        $minuteBucket = (int) floor($now->getTimestamp() / 300); // 维持 5 分钟粒度缓存
        $cacheKey = "predictions:{$siteId}:{$minuteBucket}";

        $cached = $this->redis->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }

        // 1. 获取今日/昨日精确去重总盘
        $todayTotals = $this->getTotals($siteId, 'today');
        $yesterdayTotals = $this->getTotals($siteId, 'yesterday');

        $todayViews = max(0, (int)($todayTotals['views'] ?? 0));
        $todayIps = max(0, (int)($todayTotals['ip_count'] ?? 0));
        $yesterdayFullViews = max(0, (int)($yesterdayTotals['views'] ?? 0));
        $yesterdayFullIps = max(0, (int)($yesterdayTotals['ip_count'] ?? 0));

        $hour = (int)$now->format('H');
        $minute = (int)$now->format('i');
        $second = (int)$now->format('s');
        $timeFraction = ($hour * 3600 + $minute * 60 + $second) / 86400;

        $yesterdayStart = $now->setTime(0, 0, 0)->sub(new DateInterval('P1D'));
        $yesterdayYmd = $yesterdayStart->format('Ymd');

        // ====================================================================
        // = HLL 跨桶合并：获取昨日精确到当前小时的边缘去重 IP =
        // ====================================================================
        $yestIpKeysUpToHour = [];
        $yestMobileIpKeysUpToHour = [];
        for ($i = 0; $i < $hour; $i++) {
            $h = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
            $yestIpKeysUpToHour[] = "site:{$siteId}:hll_ip:{$yesterdayYmd}{$h}";
            $yestMobileIpKeysUpToHour[] = "site:{$siteId}:hll_ip_mobile:{$yesterdayYmd}{$h}";
        }

        $yestIpKeysNextHour = $yestIpKeysUpToHour;
        $yestMobileIpKeysNextHour = $yestMobileIpKeysUpToHour;
        $currentH = str_pad((string)$hour, 2, '0', STR_PAD_LEFT);
        $yestIpKeysNextHour[] = "site:{$siteId}:hll_ip:{$yesterdayYmd}{$currentH}";
        $yestMobileIpKeysNextHour[] = "site:{$siteId}:hll_ip_mobile:{$yesterdayYmd}{$currentH}";

        $yesterdayIpsUpToHour = !empty($yestIpKeysUpToHour) ? (int) $this->redis->pfCount($yestIpKeysUpToHour) : 0;
        $yesterdayIpsUpToNextHour = (int) $this->redis->pfCount($yestIpKeysNextHour);
        $yesterdayIpsInHour = max(0, $yesterdayIpsUpToNextHour - $yesterdayIpsUpToHour);

        $yesterdayMobileIpsUpToHour = !empty($yestMobileIpKeysUpToHour) ? (int) $this->redis->pfCount($yestMobileIpKeysUpToHour) : 0;
        $yesterdayMobileIpsUpToNextHour = (int) $this->redis->pfCount($yestMobileIpKeysNextHour);
        $yesterdayMobileIpsInHour = max(0, $yesterdayMobileIpsUpToNextHour - $yesterdayMobileIpsUpToHour);

        $hourFraction = ($minute * 60 + $second) / 3600;
        $yesterdayPartialIps = $yesterdayIpsUpToHour + ($yesterdayIpsInHour * $hourFraction);
        $yesterdayPartialMobileIps = $yesterdayMobileIpsUpToHour + ($yesterdayMobileIpsInHour * $hourFraction);

        // ==== PV 沿用 SQL，获取精确时间进度 ====
        $yesterdayHourStart = $yesterdayStart->setTime($hour, 0, 0);
        $yesterdayPaceStats = $this->getRangeStats($siteId, $yesterdayStart, $yesterdayHourStart);
        $yesterdayViewsUpToHour = max(0, (int)($yesterdayPaceStats['views'] ?? 0));
        
        $yesterdayNextHourDt = $yesterdayHourStart->modify('+1 hour');
        $yesterdayHourStats = $this->getRangeStats($siteId, $yesterdayHourStart, $yesterdayNextHourDt);
        $yesterdayViewsInHour = max(0, (int)($yesterdayHourStats['views'] ?? 0));
        $yesterdayPartialViews = $yesterdayViewsUpToHour + ($yesterdayViewsInHour * $hourFraction);

        $deviceData = $this->getDeviceBreakdown($siteId, 'yesterday');
        $yesterdayFullMobileIps = max(0, (int)($deviceData['mobile']['ips'] ?? 0));

        // ====================================================================
        // = 防爆核心：如果读不到 HLL 数据，优雅降级回时钟进度，杜绝暴涨 =
        // ====================================================================
        $progressFractionViews = $yesterdayFullViews > 0 ? ($yesterdayPartialViews / $yesterdayFullViews) : $timeFraction;
        
        if ($yesterdayPartialIps <= 0 || $yesterdayFullIps <= 0) {
            $progressFractionIps = $timeFraction;
        } else {
            $progressFractionIps = $yesterdayPartialIps / $yesterdayFullIps;
        }

        if ($yesterdayPartialMobileIps <= 0 || $yesterdayFullMobileIps <= 0) {
            $progressFractionMobileIps = $timeFraction;
        } else {
            $progressFractionMobileIps = $yesterdayPartialMobileIps / $yesterdayFullMobileIps;
        }

        // 强行兜底：即便提取到的数据极小，也不能低于 5%（即 0.05），防止被除数放大数百倍
        if ($progressFractionViews < 0.05) $progressFractionViews = max(0.05, $timeFraction);
        if ($progressFractionIps < 0.05) $progressFractionIps = max(0.05, $timeFraction);
        if ($progressFractionMobileIps < 0.05) $progressFractionMobileIps = max(0.05, $timeFraction);

        $progressFractionViews = max(0.01, min(1.0, $progressFractionViews));
        $progressFractionIps = max(0.01, min(1.0, $progressFractionIps));
        $progressFractionMobileIps = max(0.01, min(1.0, $progressFractionMobileIps));

        if ($timeFraction >= 0.99) {
            $progressFractionViews = 1.0;
            $progressFractionIps = 1.0;
            $progressFractionMobileIps = 1.0;
        }

        $predictedViews = $todayViews;
        $predictedIps = $todayIps;
        $todayDeviceData = $this->getDeviceBreakdown($siteId, 'today');
        $currentMobileViews = max(0, (int)($todayDeviceData['mobile']['views'] ?? 0));
        $currentMobileIps = max(0, (int)($todayDeviceData['mobile']['ips'] ?? 0));

        if ($timeFraction < 0.1) {
            $averages = $this->getHistoricalAverages($siteId, 30);
            $expectedViews = $yesterdayFullViews > 0 ? $yesterdayFullViews : max(0, (int)($averages['views'] ?? 0));
            $expectedIps = $yesterdayFullIps > 0 ? $yesterdayFullIps : max(0, (int)($averages['ips'] ?? 0));
            
            $predictedViews = (int) round($todayViews + $expectedViews * (1 - $timeFraction));
            $predictedIps = (int) round($todayIps + $expectedIps * (1 - $timeFraction));
            
            $mobileViewRatio = $todayViews > 0 ? ($currentMobileViews / $todayViews) : 0;
            $predictedMobileViews = (int) round($predictedViews * $mobileViewRatio);
            $predictedMobileIps = (int) round($predictedIps * $mobileViewRatio); 
        } else {
            $predictedViews = (int) round($todayViews / $progressFractionViews);
            $predictedIps = (int) round($todayIps / $progressFractionIps);
            $predictedMobileIps = (int) round($currentMobileIps / $progressFractionMobileIps);
            
            $mobileViewRatio = $todayViews > 0 ? ($currentMobileViews / $todayViews) : 0;
            $predictedMobileViews = (int) round($predictedViews * $mobileViewRatio);
        }

        $predictions = [
            'views' => max($todayViews, $predictedViews),
            'ips' => max($todayIps, $predictedIps),
            'mobile_views' => max($currentMobileViews, $predictedMobileViews),
            'mobile_ips' => max($currentMobileIps, $predictedMobileIps), 
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

private function isDataCenterAsn(array $asnMeta, string $userAgent = '', string $ispDomain = ''): bool
    {
        $asnName = strtolower(trim((string) ($asnMeta['name'] ?? '')));
        $ispName = strtolower(trim((string) ($asnMeta['isp'] ?? '')));
        $combined = trim($asnName . ' ' . $ispName . ' ' . strtolower($ispDomain));
        
        if ($combined === '') {
            return false;
        }

        if (str_contains($combined, 'apple') || str_contains($combined, 'icloud')) {
            return false; 
        }

$needles = [
            // 国际大厂
            'amazon', 'aws', 'amazon web services', 'google', 'gcp', 'microsoft', 'azure',
            'oracle', 'oracle cloud', 'alibaba', 'aliyun', 'tencent', 'huawei cloud', 'baidu',
            // 常见 VPS/IDC 厂商
            'digitalocean', 'linode', 'vultr', 'hetzner', 'ovh', 'leaseweb', 'gcore', 
            'cloudflare', 'akamai', 'fastly', 'ucloud', 'qingcloud',
            // 剔除了 network, server, host。保留真正代表机房的专属名词
            'datacenter', 'data center', 'colo', 'hosting', 'cloud computing', 'vps', 
            // 次级灰黑产常见机房
            'xtom', 'zenlayer', 'akile', 'rfchost', 'ipxo', 'larus', 'cogent', 'winspeed', 'lshiy',
            // 国内云特征
            '阿里云', '腾讯云', '华为云', '百度云', '天翼云', '移动云', '联通云', '金山云', '青云', '优刻得', '数据中心'
        ];

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($combined, $needle)) {
                if (str_contains(strtolower($userAgent), 'safari') && str_contains(strtolower($userAgent), 'mobile')) {
                    return false; 
                }
                return true;
            }
        }

        return false;
    }

    private function isSuspiciousUserAgent(string $userAgent, array $headerMeta = []): bool
    {
        $ua = strtolower(trim($userAgent));
        if ($ua === '') {
            return true;
        }

        if ($this->isSearchEngineSpider($ua)) {
            return false;
        }

        // 风控侧获取 JS 能力证明
        $hasJsProof = !empty($headerMeta['showp']);

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
                if ($hasJsProof) {
                    $realHeadless = ['headless', 'phantomjs', 'puppeteer', 'playwright', 'selenium', 'chromedriver', 'cypress'];
                    foreach ($realHeadless as $rh) {
                        if (str_contains($ua, $rh)) {
                            return true;
                        }
                    }
                    return false; // 风控评分也对其豁免，不加风险分
                }
                return true;
            }
        }

        return strlen($ua) < 20;
    }

private function isBot(string $userAgent, array $payload = []): bool
{
    $ua = strtolower(trim($userAgent));
    if ($ua === '') {
        return true;
    }

    if (str_contains($ua, 'petalbot')) {
        return true;
    }

    // 【修复 1】：遇到正常的搜索引擎蜘蛛，直接判定为 bot，不进入正常 PV 统计
    if ($this->isSearchEngineSpider($ua)) {
        return true; 
    }

    // 获取该请求是否具备执行 JS 的能力证明
    $hasJsProof = !empty($payload['showp']) && !empty($payload['fingerprint']);

    $bots = [
        'bot', 'spider', 'monitor', 'crawler', 'postman', 'curl/', 'wget/',
        'windowspowershell/', 'python-', 'python-requests', 'python-urllib', 'httpclient/',
        'go-http-client/', 'libwww-perl', 'java/', 'okhttp', 'apache-httpclient',
        'feedburner/', 'headless', 'cloudflare', 'gocolly/', 'scrapy/', 'zgrab/',
        'phantomjs', 'axios', 'apachebench', 'wkhtmltopdf', 'playwright', 'puppeteer',
        'chromedriver', 'cypress', 'selenium', 'node-fetch', 'aiohttp', 'httpx',
        'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot',
        'masscan', 'nmap', 'sqlmap', 'nessus', 'acunetix'
    ];

    foreach ($bots as $needle) {
        if ($needle !== '' && str_contains($ua, $needle)) {
            if ($hasJsProof) {
                $realHeadless = ['headless', 'phantomjs', 'puppeteer', 'playwright', 'selenium', 'chromedriver', 'cypress'];
                $isRealHeadless = false;
                foreach ($realHeadless as $rh) {
                    if (str_contains($ua, $rh)) {
                        $isRealHeadless = true; 
                        break;
                    }
                }
                if (!$isRealHeadless) {
                    return false; 
                }
            }
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
            'yahoo',
            'duckduckbot',
        ];

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($ua, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isMobile(string $userAgent, array $payload = []): bool
    {
        if ($userAgent === '') {
            return false;
        }

        // 优先级 1：Client Hints 绝对判定 (现代标准，无视 UA 伪装，已通过前端 sec_m 或请求头获取)
        $secMobile = trim((string) ($payload['sec_ch_ua_mobile'] ?? ''));
        if ($secMobile === '?1' || str_contains(strtolower($secMobile), 'true')) {
            return true;
        }
        if ($secMobile === '?0' || str_contains(strtolower($secMobile), 'false')) {
            return false;
        }

        // 优先级 2：传统 UA 正则匹配 (只保留绝对属于移动端的词汇，剔除 harmonyos 以防误判鸿蒙 PC)
        $ua = strtolower($userAgent);
        $realUa = explode(' xrw/', $ua)[0];
        $needles = ['mobile', 'android', 'iphone', 'ipad', 'ipod', 'micromessenger', 'windows phone'];
        foreach ($needles as $needle) {
            if (str_contains($realUa, $needle)) {
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
        return;
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
        $ensureColumn('title', 'VARCHAR(255)', 'AFTER canonical_host');
        $ensureColumn('path', 'VARCHAR(2048)');
        $ensureColumn('referrer', 'VARCHAR(2048)');
        $ensureColumn('user_agent', 'VARCHAR(1024)');
        $ensureColumn('session_id', 'VARCHAR(64)');
        $ensureColumn('visitor_id', 'VARCHAR(128)', 'AFTER session_id');
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
            "CREATE TABLE IF NOT EXISTS site_visitor_audience (
                site_id INT UNSIGNED NOT NULL,
                visitor_id VARCHAR(128) NOT NULL,
                first_seen DATETIME NOT NULL,
                last_seen_date DATE NOT NULL,
                PRIMARY KEY (site_id, visitor_id),
                INDEX idx_last_seen_date (last_seen_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );

        $this->ensureHashPartitioned('site_visitor_audience');
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
                user_id INT UNSIGNED NOT NULL DEFAULT 0,
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
            'path_filters' => trim((string) ($filters['path_filters'] ?? '')),
            'asn_filters' => trim((string) ($filters['asn_filters'] ?? '')),
            'ua_filters' => trim((string) ($filters['ua_filters'] ?? '')),
        ];
    }

public function getMobileBreakdown(int $siteId, string $range): array
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
                    
                    $mechanicSumRow = $result[0];
                    
                    $ipKeys = $this->getHllKeysForRange($siteId, 'hll_ip', $range);
                    $mobileIpKeys = $this->getHllKeysForRange($siteId, 'hll_ip_mobile', $range);
                    
                    $globalTotalIps = !empty($ipKeys) ? (int) $this->redis->pfCount($ipKeys) : $mechanicSumRow['ips'];
                    $globalMobileIps = !empty($mobileIpKeys) ? (int) $this->redis->pfCount($mobileIpKeys) : $mechanicSumRow['mobile_ips'];
                    
                    // 构建全局汇总行，并强制塞入 sum_ips 以适配前端渲染
                    $globalRow = [
                        'domain' => '全局汇总',
                        'views' => $mechanicSumRow['views'],               
                        'mobile_views' => $mechanicSumRow['mobile_views'], 
                        'ips' => $globalTotalIps,
                        'mobile_ips' => $globalMobileIps,
                        'sum_ips' => $mechanicSumRow['ips'],               // 注入累加 IP
                        'sum_mobile_ips' => $mechanicSumRow['mobile_ips'], // 注入累加移动 IP
                        'has_sum_comparison' => true                       // 开启对比标识
                    ];
                    
                    array_shift($result); 
                    array_unshift($result, $globalRow);
                    
                    return $result;
                }
            }
            return [
                ['domain' => '全局汇总', 'views' => 0, 'ips' => 0, 'mobile_views' => 0, 'mobile_ips' => 0, 'has_sum_comparison' => false]
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
            'yahoo' => '雅虎蜘蛛',
            'duckduckbot' => 'DuckDuckGo蜘蛛',
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
            'yahoo.com' => '雅虎',
            'duckduckgo.com' => 'DuckDuckGo',
            'douyin.com' => '抖音',
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
            WHEN LOWER(COALESCE({$prefix}referrer,'')) LIKE '%toutiao.com%' OR LOWER(COALESCE({$prefix}referrer,'')) LIKE '%douyin.com%' OR LOWER(COALESCE({$prefix}user_agent,'')) LIKE '%bytespider%' THEN '头条'
            WHEN LOWER(COALESCE({$prefix}referrer,'')) LIKE '%sogou.com%' OR LOWER(COALESCE({$prefix}user_agent,'')) LIKE '%sogouspider%' THEN '搜狗'
            WHEN LOWER(COALESCE({$prefix}referrer,'')) LIKE '%sm.cn%' OR LOWER(COALESCE({$prefix}user_agent,'')) LIKE '%yisouspider%' THEN '神马'
            WHEN LOWER(COALESCE({$prefix}referrer,'')) LIKE '%yahoo.com%' OR LOWER(COALESCE({$prefix}user_agent,'')) LIKE '%yahoo%' THEN '雅虎'
            WHEN LOWER(COALESCE({$prefix}referrer,'')) LIKE '%duckduckgo.com%' OR LOWER(COALESCE({$prefix}user_agent,'')) LIKE '%duckduckbot%' THEN 'DuckDuckGo'
            WHEN LOWER(COALESCE({$prefix}user_agent,'')) LIKE '%petalbot%' THEN '华为'
            WHEN LOWER(COALESCE({$prefix}referrer,'')) LIKE '%quark.cn%' THEN '夸克'
            ELSE '其他'
        END";
    }

    private function getSearchEngines(int $siteId, string $range, ?string $domain = null): array
    {
        $cacheKey = "search_engines:{$siteId}:{$range}:" . ($domain ?? 'all');

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $domain) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if (!$span) {
                return [];
            }

            // === 走带域名筛选的新聚合表 ===
            if ($domain !== null && $domain !== '' && $domain !== 'all') {
                $rollup = $this->aggregateDimensionRollups($siteId, 'search_engine_domain', $span['start'], $span['end'], 500);
                $mapped = [];
                $domainLower = strtolower($domain);
                
                foreach ($rollup as $row) {
                    $val = $row['dimension_value'] ?? '';
                    [$engine, $host] = array_pad(explode('|', $val, 2), 2, '');
                    
                    if ($engine === '其他' || strtolower($host) !== $domainLower) {
                        continue;
                    }
                    if (!isset($mapped[$engine])) {
                        $mapped[$engine] = ['engine' => $engine, 'ips' => 0];
                    }
                    $mapped[$engine]['ips'] += (int) ($row['ips'] ?? 0);
                }
                
                $results = array_values($mapped);
                usort($results, fn($a, $b) => $b['ips'] <=> $a['ips']);
                return $results;
            }

            // === 默认全局查询 ===
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
                        'ips' => (int) ($row['ips'] ?? 0),
                    ];
                }
                
                usort($mapped, fn($a, $b) => $b['ips'] <=> $a['ips']);
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
            
            // 加入 'yahoo.com', 'duckduckgo.com', 'douyin.com'
            $blocked = ['baidu', 'google', 'bing.', 'sm.cn', 'quark.cn', 'so.com', 'sogou', 'bytedance', 'toutiao', 'yahoo.com', 'duckduckgo.com', 'douyin.com'];
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
            $desktopIps = 0;

            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if ($span) {
                // 读取基础的 PV 数据 (PV 依然使用 SQL)
                $rollup = $this->aggregateDimensionRollups($siteId, 'device', $span['start'], $span['end'], 2);
                $lookup = [];
                foreach ($rollup as $row) {
                    $lookup[$row['dimension_value'] ?? ''] = $row;
                }

                $mobileViews = (int) ($lookup['mobile']['views'] ?? 0);
                $desktopViews = (int) ($lookup['desktop']['views'] ?? 0);

                // 获取降级兜底用的旧版 IP 数据
                $mobileIps = (int) ($lookup['mobile']['ips'] ?? 0);
                $totals = $this->aggregateTotalsWithRollups($siteId, $span['start'], $span['end']);
                $totalIps = (int) ($totals['ip_count'] ?? 0);
                $desktopIps = max(0, $totalIps - $mobileIps);

                // 【关键修复】：使用 HLL 精确覆盖设备拆分数据的 IP
                $ipKeys = $this->getHllKeysForRange($siteId, 'hll_ip', $range);
                $mobileIpKeys = $this->getHllKeysForRange($siteId, 'hll_ip_mobile', $range);
                $desktopIpKeys = $this->getHllKeysForRange($siteId, 'hll_ip_desktop', $range);

                if (!empty($ipKeys)) {
                    $totalIps = (int) $this->redis->pfCount($ipKeys);
                }
                if (!empty($mobileIpKeys)) {
                    $mobileIps = (int) $this->redis->pfCount($mobileIpKeys);
                }
                if (!empty($desktopIpKeys)) {
                    $desktopIps = (int) $this->redis->pfCount($desktopIpKeys);
                } else {
                    // 如果桌面端没有专属 HLL Key，使用总去重 IP 减去移动端去重 IP
                    $desktopIps = max(0, $totalIps - $mobileIps);
                }
            }

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
                // 1. 将查询条数放大（例如乘以 3），防止在 SQL 阶段按 PV 截断时丢弃了高 IP 但低 PV 的小众浏览器
                $rollup = $this->aggregateDimensionRollups($siteId, 'browser', $span['start'], $span['end'], $limit * 3);

                if (!empty($rollup)) {
                    $browsers = array_map(fn ($row) => [
                        'browser' => $row['dimension_value'],
                        'views' => (int) ($row['views'] ?? 0),
                        'ips' => (int) ($row['ips'] ?? 0),
                    ], $rollup);

                    // 2. 新增：使用 usort 按照 ips (独立 IP 数) 降序排列
                    usort($browsers, fn($a, $b) => $b['ips'] <=> $a['ips']);

                    // 3. 截取最终需要展示的数量并返回
                    return array_slice($browsers, 0, $limit);
                }
            }
            return [];
        });
    }

public function getRegionStats(int $siteId, string $range, int $limit = 50): array
    {
        $cacheKey = "regions_china_only:{$siteId}:{$range}:{$limit}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $limit) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            if ($span) {
                $rollup = $this->aggregateDimensionRollups($siteId, 'region', $span['start'], $span['end'], $limit * 10);

                if (!empty($rollup)) {
                    $validRegions = [
                        '北京', '天津', '上海', '重庆', '河北', '山西', '辽宁', '吉林', '黑龙江',
                        '江苏', '浙江', '安徽', '福建', '江西', '山东', '河南', '湖北', '湖南',
                        '广东', '海南', '四川', '贵州', '云南', '陕西', '甘肃', '青海', '台湾',
                        '内蒙古', '广西', '西藏', '宁夏', '新疆', '香港', '澳门'
                    ];

                    $filteredMap = [];

                    foreach ($rollup as $row) {
                        $region = $row['dimension_value'] ?? '';
                        $views = (int) ($row['views'] ?? 0);
                        $ips = (int) ($row['ips'] ?? 0);

                        $matchedRegion = null;
                        foreach ($validRegions as $vr) {
                            if (str_starts_with($region, $vr)) {
                                $matchedRegion = $vr;
                                break;
                            }
                        }

                        if ($matchedRegion) {
                            if (!isset($filteredMap[$matchedRegion])) {
                                $filteredMap[$matchedRegion] = ['region' => $matchedRegion, 'views' => 0, 'ips' => 0];
                            }
                            $filteredMap[$matchedRegion]['views'] += $views;
                            $filteredMap[$matchedRegion]['ips'] += $ips;
                        }
                    }

                    $results = array_values($filteredMap);

                    usort($results, fn($a, $b) => $b['ips'] <=> $a['ips']);

                    return array_slice($results, 0, $limit);
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

    private function getIspStatsPaged(int $siteId, string $range, int $page = 1, int $perPage = 50): array
    {
        $cacheKey = "isp_paged:{$siteId}:{$range}:{$page}:{$perPage}";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $page, $perPage) {
            [$start, $end] = $this->rollupRangeBounds($range);
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            
            if ($span) {
                $countStmt = $this->db->prepare(
                    "SELECT COUNT(DISTINCT dimension_value) 
                     FROM pageview_dimension_rollups 
                     WHERE site_id = :site_id AND dimension_type = 'isp' AND bucket_start >= :start AND bucket_start < :end"
                );
                $countStmt->execute([
                    ':site_id' => $siteId,
                    ':start' => $span['start']->format('Y-m-d H:i:s'),
                    ':end' => $span['end']->format('Y-m-d H:i:s'),
                ]);
                $total = (int) $countStmt->fetchColumn();

                $offset = ($page - 1) * $perPage;
                $statement = $this->db->prepare(
                    "SELECT dimension_value, SUM(pv) as views, SUM(ip_count) as ips
                     FROM pageview_dimension_rollups
                     WHERE site_id = :site_id AND dimension_type = 'isp' AND bucket_start >= :start AND bucket_start < :end
                     GROUP BY dimension_value
                     ORDER BY ips DESC, views DESC
                     LIMIT :limit OFFSET :offset"
                );

                $statement->bindValue(':site_id', $siteId, PDO::PARAM_INT);
                $statement->bindValue(':start', $span['start']->format('Y-m-d H:i:s'), PDO::PARAM_STR);
                $statement->bindValue(':end', $span['end']->format('Y-m-d H:i:s'), PDO::PARAM_STR);
                $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
                $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
                $statement->execute();

                $rows = $statement->fetchAll();

                if (!empty($rows)) {
                    $isps = array_map(fn ($row) => [
                        'isp' => $row['dimension_value'],
                        'views' => (int) ($row['views'] ?? 0),
                        'ips' => (int) ($row['ips'] ?? 0),
                    ], $rows);

                    return ['isps' => $isps, 'total' => $total];
                }
            }
            return ['isps' => [], 'total' => 0];
        });
    }

    public function getNewVsReturning(int $siteId, string $range): array
    {
        // 缓存键名升至 _v5，确保立即跳过旧的慢查询缓存
        $cacheKey = "new_vs_returning:{$siteId}:{$range}_v5";

        return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range) {
            [$start, $end] = $this->rollupRangeBounds($range);

            // 1. 获取全站精准去重 UV 和 绝对去重总 IP
            $totalUv = 0;
            $totalIp = 0;
            $uvKeys = $this->getHllKeysForRange($siteId, 'hll_uv', $range);
            $ipKeys = $this->getHllKeysForRange($siteId, 'hll_ip', $range);
            
            if (!empty($uvKeys)) {
                $totalUv = (int) $this->redis->pfCount($uvKeys);
            }
            if (!empty($ipKeys)) {
                $totalIp = (int) $this->redis->pfCount($ipKeys);
            }
            
            // 降级兜底
            if ($totalUv === 0 || $totalIp === 0) {
                $totals = $this->aggregateTotalsWithRollups($siteId, $start, $end);
                $totalUv = $totalUv ?: (int) ($totals['uniques'] ?? 0);
                $totalIp = $totalIp ?: (int) ($totals['ip_count'] ?? 0);
            }

            // 2. 读取 SQL 聚合基础数据（获取 PV 以及为历史数据提供基数）
            $span = $this->rollupSpanForRange($siteId, $start, $end);
            $rows = $span ? $this->aggregateDimensionRollups($siteId, 'audience', $span['start'], $span['end'], 2) : [];
            
            $newViews = 0; $returningViews = 0; $sqlNewIps = 0; $sqlReturningIps = 0;
            foreach ($rows as $row) {
                $val = $row['dimension_value'] ?? '';
                if ($val === 'new') {
                    $newViews += (int) ($row['views'] ?? 0);
                    $sqlNewIps += (int) ($row['ips'] ?? 0); 
                } elseif ($val === 'returning') {
                    $returningViews += (int) ($row['views'] ?? 0);
                    $sqlReturningIps += (int) ($row['ips'] ?? 0);
                }
            }

            // 3. 读取全新的 HLL 独立精准去重新老访客 IP
            $newIpKeys = $this->getHllKeysForRange($siteId, 'hll_ip_new', $range);
            $returningIpKeys = $this->getHllKeysForRange($siteId, 'hll_ip_returning', $range);
            
            // 检查是否有 HLL 新版数据产生
            if (!empty($newIpKeys) && $this->redis->exists($newIpKeys[0] ?? '') !== 0) {
                $finalNewIps = (int) $this->redis->pfCount($newIpKeys);
                $finalReturningIps = (int) $this->redis->pfCount($returningIpKeys);
            } else {
                // 如果是历史数据，则直接使用 SQL 累加虚高值作为基数
                $finalNewIps = $sqlNewIps;
                $finalReturningIps = $sqlReturningIps;
            }

            // ==========================================
            // = 核心修正：等比收敛算法，强制对齐总 IP =
            // ==========================================
            // 由于存在同一个公网 IP (网吧/公司) 下同时包含新老设备的情况，
            // 导致绝对真实的 (New IP + Returning IP) 也会略微 > Total IP。
            // 加上如果属于老数据的 SQL 累加，这个差距会极大。
            // 在此将其按比例强制压缩，使其和恰好等于全局去重总 IP，杜绝图表不合理。
            $sumIps = $finalNewIps + $finalReturningIps;
            if ($sumIps > $totalIp && $totalIp > 0) {
                $ratio = $totalIp / $sumIps;
                $finalNewIps = (int) round($finalNewIps * $ratio);
                // 使用减法而非乘法，确保相加之和绝对等于 totalIp，不留 1 个 IP 的小数浮点误差
                $finalReturningIps = max(0, $totalIp - $finalNewIps);
            }

            // 4. 计算最终的新老访客 UV 分布（保持逻辑对齐）
            $newUv = $finalNewIps; 
            $returningUv = max(0, $totalUv - $newUv);

            // 极端情况防御对齐
            if ($newUv > $totalUv && $totalUv > 0) {
                $newUv = $totalUv;
                $returningUv = 0;
            }

            return [
                'new' => $newViews, // 新访客 PV
                'returning' => $returningViews, 
                'new_ips' => $finalNewIps,           // <--- 已彻底修复的 IP 数据
                'returning_ips' => $finalReturningIps, // <--- 已彻底修复的 IP 数据
                'new_uv' => $newUv, 
                'returning_uv' => $returningUv,
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
                return $query['wd']; 
            }
        }

        // ==================== 夸克搜索（quark.cn），显式读取 q 参数 ====================
        if (str_contains($refererHost, 'quark.cn')) {
            if (!empty($query['q'])) {
                return $query['q'];
            }
        }

        // ==================== 谷歌/必应/360/头条/搜狗/神马/夸克 ====================
        $paramQ = !empty($query['q']) ? $query['q'] : null;
        $paramKeyword = !empty($query['keyword']) ? $query['keyword'] : null;
        $paramWord = !empty($query['word']) ? $query['word'] : null;

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
                'mobile_ips' => (int) ($row['mobile_ips'] ?? 0), // 新增
            ];
        }

        $labels = [];
        // 新增 series 字段
        $series = ['views' => [], 'uniques' => [], 'ips' => [], 'mobile_ips' => []];

        for ($i = 0; $i < 24; $i++) {
            $label = $dayStart->modify("+{$i} hour")->format('H:00');
            $labels[] = $label;
            $series['views'][] = $map[$label]['views'] ?? 0;
            $series['uniques'][] = $map[$label]['uniques'] ?? 0;
            $series['ips'][] = $map[$label]['ips'] ?? 0;
            $series['mobile_ips'][] = $map[$label]['mobile_ips'] ?? 0; // 新增
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
                'mobile_ips' => (int) ($row['mobile_ips'] ?? 0), // 新增
            ];
        }

        $labels = [];
        // 新增 series 字段
        $series = ['views' => [], 'uniques' => [], 'ips' => [], 'mobile_ips' => []];
        $period = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));

        foreach ($period as $date) {
            $day = $date->format('Y-m-d');
            $labels[] = $day;
            $series['views'][] = $map[$day]['views'] ?? 0;
            $series['uniques'][] = $map[$day]['uniques'] ?? 0;
            $series['ips'][] = $map[$day]['ips'] ?? 0;
            $series['mobile_ips'][] = $map[$day]['mobile_ips'] ?? 0; // 新增
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
        try {
            $stmt = $this->db->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
            $stmt->execute([':key' => $key]);
            $value = $stmt->fetchColumn();

            return $value ? (json_decode($value, true) ?: null) : null;
        } catch (Throwable $e) {
            return null;
        }
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
        $merged['path_filters'] = trim((string) ($merged['path_filters'] ?? ''));
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

    public function updateIngestFilters(string $ipFilters, string $keywordFilters, string $pathFilters = ''): array
    {
        $payload = [
            'ip_filters' => trim($ipFilters),
            'keyword_filters' => trim($keywordFilters),
            'path_filters' => trim($pathFilters),
            'asn_filters' => trim((string) ($this->ingestFilters['asn_filters'] ?? '')),
            'ua_filters' => trim((string) ($this->ingestFilters['ua_filters'] ?? '')),
        ];
        $this->setSetting('ingest_filters', $payload);
        $this->hydrateIngestFilters();
        return $payload;
    }

    public function updateIngestFiltersWithAsn(string $ipFilters, string $keywordFilters, string $pathFilters, string $asnFilters, string $uaFilters = ''): array
    {
        $payload = [
            'ip_filters' => trim($ipFilters),
            'keyword_filters' => trim($keywordFilters),
            'path_filters' => trim($pathFilters),
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
                'DELETE FROM site_visitor_audience WHERE last_seen_date < :cutoff_date LIMIT :batch',
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
            // 新增：连带清理过期的 sessions
            $this->deleteBatched(
                'DELETE FROM sessions WHERE start_time < :cutoff LIMIT :batch',
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

        $isAdmin = $GLOBALS['is_admin'] ?? false;
        $currentUserId = $GLOBALS['current_user_id'] ?? 0;

        // === 新增：安全校验，防止恶意构造表单跨权分享别人的站点 ===
        if (!$isAdmin) {
            $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
            $stmt = $this->db->prepare("SELECT id FROM sites WHERE id IN ($placeholders) AND user_id = ?");
            $params = $siteIds;
            $params[] = $currentUserId;
            $stmt->execute($params);
            $validIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // 如果查出属于该用户的站点数量和传入的数量不一致，直接阻断
            if (count($validIds) !== count($siteIds)) {
                throw new InvalidArgumentException('越权操作：所选站点中包含您无权访问的站点');
            }
        }
        // ==========================================================

        $token = bin2hex(random_bytes(12));

        $statement = $this->db->prepare(
            'INSERT INTO share_pages (name, token, site_ids, user_id, created_at) VALUES (:name, :token, :site_ids, :user_id, NOW())'
        );
        $statement->execute([
            ':name' => $name,
            ':token' => $token,
            ':site_ids' => json_encode($siteIds),
            ':user_id' => $currentUserId, 
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'name' => $name,
            'token' => $token,
            'site_ids' => $siteIds,
        ];
    }

public function getSharePages(?int $userId = null): array
    {
        // 统一逻辑：未传 userId 则查询当前身份对应的数据（管理员 0，用户 UID）
        $uid = ($userId !== null) ? $userId : ($GLOBALS['current_user_id'] ?? 0);

        $query = $this->db->prepare('SELECT id, name, token, site_ids, created_at FROM share_pages WHERE user_id = :uid ORDER BY created_at DESC');
        $query->execute([':uid' => $uid]);
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
        $isAdmin = $GLOBALS['is_admin'] ?? false;
        $currentUserId = $GLOBALS['current_user_id'] ?? 0;

        if ($isAdmin) {
            $stmt = $this->db->prepare('DELETE FROM share_pages WHERE id = :id');
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $this->db->prepare('DELETE FROM share_pages WHERE id = :id AND user_id = :uid');
            $stmt->execute([':id' => $id, ':uid' => $currentUserId]);
        }
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
        // 缓存版本号升至 v8，强制刷新
        $cacheKey = "share_report:{$token}:{$range}_v8";

        return $this->cacheAggregate($cacheKey, 20, function () use ($token, $range) {
            $share = $this->getShareByToken($token);
            if (!$share || empty($share['site_ids'])) {
                return null;
            }

            [$start, $end] = $this->rollupRangeBounds($range);

            $originalRows = $this->getHostDeviceRollupRowsForSites($share['site_ids'], $start, $end);
            
            $globalSumRow = null;
            $mechanicSumRow = null;
            $normalRows = [];

            if (!empty($originalRows)) {
                foreach ($originalRows as $row) {
                    if ($row['domain'] === '全局汇总') {
                        $globalSumRow = $row;
                    } elseif ($row['domain'] === '汇总') {
                        $mechanicSumRow = $row;
                    } else {
                        $normalRows[] = $row;
                    }
                }
            }

            if (!$globalSumRow && !$mechanicSumRow) {
                $globalSumRow = ['domain' => '全局汇总', 'views' => 0, 'ips' => 0, 'mobile_views' => 0, 'mobile_ips' => 0];
                $mechanicSumRow = $globalSumRow;
            } elseif (!$globalSumRow) {
                $globalSumRow = $mechanicSumRow;
            } elseif (!$mechanicSumRow) {
                $mechanicSumRow = $globalSumRow;
            }

            $mergedGlobalRow = [
                'domain' => '全局汇总',
                'views' => $globalSumRow['views'],
                'mobile_views' => $globalSumRow['mobile_views'],
                'ips' => $globalSumRow['ips'],                       
                'mobile_ips' => $globalSumRow['mobile_ips'],         
                'sum_ips' => $mechanicSumRow['ips'],                 
                'sum_mobile_ips' => $mechanicSumRow['mobile_ips'],   
                'has_sum_comparison' => true // 全局汇总保留双数据展示                         
            ];

            $processedRows = $normalRows;
            array_unshift($processedRows, $mergedGlobalRow);

            if ($range === 'today') {
                $predViews = 0; $sumPredIps = 0; $predMobileViews = 0; $sumPredMobileIps = 0;
                $sumTodayIps = 0; $sumTodayMobileIps = 0;

                $todayAllIpKeys = [];
                $todayAllMobileIpKeys = [];
                $yestAllIpKeys = [];
                $yestAllMobileIpKeys = [];
                $todayDateStr = date('Ymd');
                $yestDateStr = date('Ymd', strtotime('-1 day'));

                foreach ($share['site_ids'] as $siteId) {
                    $sid = (int) $siteId;
                    
                    $todayAllIpKeys[] = "site:{$sid}:hll_ip:{$todayDateStr}";
                    $todayAllMobileIpKeys[] = "site:{$sid}:hll_ip_mobile:{$todayDateStr}";
                    $yestAllIpKeys[] = "site:{$sid}:hll_ip:{$yestDateStr}";
                    $yestAllMobileIpKeys[] = "site:{$sid}:hll_ip_mobile:{$yestDateStr}";

                    // 获取并累加机械预测值
                    $pred = $this->getPredictions($sid);
                    $predViews += (int)($pred['views'] ?? 0);
                    $sumPredIps += (int)($pred['ips'] ?? 0);
                    $predMobileViews += (int)($pred['mobile_views'] ?? 0);
                    $sumPredMobileIps += (int)($pred['mobile_ips'] ?? 0);

                    // 获取今日实时产生的各站机械 IP 用于计算去重比例
                    $tTotals = $this->getTotals($sid, 'today');
                    $sumTodayIps += (int)($tTotals['ip_count'] ?? 0);
                    
                    $tDevices = $this->getDeviceBreakdown($sid, 'today');
                    $sumTodayMobileIps += (int)($tDevices['mobile']['ips'] ?? 0);
                }

                $yestDedupIps = !empty($yestAllIpKeys) ? (int) $this->redis->pfCount($yestAllIpKeys) : 0;
                $yestDedupMobileIps = !empty($yestAllMobileIpKeys) ? (int) $this->redis->pfCount($yestAllMobileIpKeys) : 0;

                $todayDedupIps = !empty($todayAllIpKeys) ? (int) $this->redis->pfCount($todayAllIpKeys) : 0;
                $todayDedupMobileIps = !empty($todayAllMobileIpKeys) ? (int) $this->redis->pfCount($todayAllMobileIpKeys) : 0;

                $dedupPredIps = $sumTodayIps > 0 ? (int) round($sumPredIps * ($todayDedupIps / $sumTodayIps)) : $sumPredIps;
                $dedupPredMobileIps = $sumTodayMobileIps > 0 ? (int) round($sumPredMobileIps * ($todayDedupMobileIps / $sumTodayMobileIps)) : $sumPredMobileIps;

                $dedupPredIps = max($todayDedupIps, $dedupPredIps);
                $dedupPredMobileIps = max($todayDedupMobileIps, $dedupPredMobileIps);

                $predictionRow = [
                    'domain' => '全局预测',
                    'views' => $predViews,
                    'mobile_views' => $predMobileViews,
                    'ips' => $dedupPredIps,                             
                    'mobile_ips' => $dedupPredMobileIps,                 
                    'yesterday_ips' => $yestDedupIps,                   
                    'yesterday_mobile_ips' => $yestDedupMobileIps,       
                    'is_prediction' => true
                    // 取消了 has_sum_comparison，不显示累加小字
                ];

                array_unshift($processedRows, $predictionRow);
            }

            return [
                'share' => $share,
                'rows' => $processedRows,
            ];
        });
    }
private function getHostDeviceRollupRowsForSites(array $siteIds, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $hosts = $this->aggregateDimensionRollupsForSites($siteIds, 'host', $start, $end, 500);
        if (empty($hosts)) {
            return [];
        }

        $hostDevices = $this->aggregateDimensionRollupsForSites($siteIds, 'host_device', $start, $end, 1000);
        $result = $this->formatHostDeviceBreakdown($hosts, $hostDevices);

        // 使用 HLL 跨多站点、跨天合并精准去重
        $allIpKeys = [];
        $allMobileIpKeys = [];
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);
        
        foreach ($siteIds as $sid) {
            foreach ($period as $dt) {
                $ymd = $dt->format('Ymd');
                $allIpKeys[] = "site:{$sid}:hll_ip:{$ymd}";
                $allMobileIpKeys[] = "site:{$sid}:hll_ip_mobile:{$ymd}";
            }
        }

        $globalTotalIps = !empty($allIpKeys) ? (int) $this->redis->pfCount($allIpKeys) : $result[0]['ips'];
        $globalMobileIps = !empty($allMobileIpKeys) ? (int) $this->redis->pfCount($allMobileIpKeys) : $result[0]['mobile_ips'];

        $globalRow = [
            'domain' => '全局汇总',
            'views' => $result[0]['views'],               
            'mobile_views' => $result[0]['mobile_views'], 
            'ips' => $globalTotalIps,
            'mobile_ips' => $globalMobileIps,
        ];

        array_unshift($result, $globalRow);
        
        return $result;
    }
    public function deleteSite(int $siteId): void
    {
        // === 新增：严格越权拦截，验证该站点是否属于当前操作者 ===
        if (!$this->getSite($siteId)) {
            throw new RuntimeException('越权操作：无权删除该站点或站点不存在');
        }
        // ========================================================
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
    
    private function executeBulkInsert(string $table, array $columns, array $dataRows, string $onDuplicate = ''): void
    {
        if (empty($dataRows)) return;
        
        $colCount = count($columns);
        $rowPlaceholders = '(' . implode(',', array_fill(0, $colCount, '?')) . ')';
        
        // 每次最多打包 200 条，绝对防止突破 MySQL 65535 占位符上限
        $chunks = array_chunk($dataRows, 200);
        foreach ($chunks as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $values[] = $rowPlaceholders;
                foreach ($columns as $col) {
                    $params[] = $row[$col] ?? null;
                }
            }
            
            $sql = "INSERT INTO {$table} (" . implode(',', $columns) . ") VALUES " . implode(',', $values) . " " . $onDuplicate;
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }
    }
    /**
     * 生成符合 RFC 4122 标准的 UUID v4 (去除短横线，返回 32 位 Hex 字符串)
     * 完美兼容 stat.php 中的 /^[a-f0-9]{16,128}$/i 正则校验
     */
    private function generateUuidV4Hex(): string
    {
        return sprintf('%04x%04x%04x%04x%04x%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    public function getContentAnalysisData(int $siteId, string $range = 'today', int $limit = 100): array
{
    $cacheKey = "content_analysis:{$siteId}:{$range}:{$limit}";

    return $this->cacheAggregate($cacheKey, 20, function () use ($siteId, $range, $limit) {
        [$start, $end] = $this->rollupRangeBounds($range);
        $span = $this->rollupSpanForRange($siteId, $start, $end);
        if (!$span) {
            return [];
        }

        // 复用底层聚合查询
        $rows = $this->aggregateDimensionRollups($siteId, 'title', $span['start'], $span['end'], $limit * 2);
        
        $contents = [];
        foreach ($rows as $row) {
            $views = (int) ($row['views'] ?? 0);
            $uniques = (int) ($row['uniques'] ?? 0);
            $ips = (int) ($row['ips'] ?? 0);
            
            // 【定义热度算法】
            // 示例：IP 带来 5 分，独立访客 3 分，普通刷新 1 分
            $heatScore = ($ips * 5) + ($uniques * 3) + ($views * 1);

            $contents[] = [
                'title' => $row['dimension_value'],
                'views' => $views,
                'uniques' => $uniques,
                'ips' => $ips,
                'heat_score' => $heatScore
            ];
        }

        // 根据热度分倒序排列
        usort($contents, fn($a, $b) => $b['heat_score'] <=> $a['heat_score']);

        return array_slice($contents, 0, $limit);
    });
}
}
