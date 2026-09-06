<?php
// ==== 1. 核心辅助函数：精准识别 CF IP ====
if (!function_exists('is_cf_ip')) {
    function is_cf_ip($ip) {
        if ($ip === '0.0.0.0' || $ip === '127.0.0.1' || empty($ip)) return false;
        $cf_ranges = [
            // Cloudflare 官方核心 CDN IP 段
            '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '104.16.0.0/13',
            '104.24.0.0/14', '108.162.192.0/18', '131.0.72.0/22', '141.101.64.0/18',
            '162.158.0.0/15', '172.64.0.0/13', '173.245.48.0/20', '188.114.96.0/20',
            '190.93.240.0/20', '197.234.240.0/22', '198.41.128.0/17',
            
            // 额外补充的 Cloudflare 企业/特殊服务 IP 段
            '141.193.213.0/24','172.64.145.0/24'
        ];
        
        $ip_long = ip2long($ip);
        if ($ip_long === false) return false;
        
        $ip_unsigned = (float) sprintf('%u', $ip_long);
        foreach ($cf_ranges as $cidr) {
            list($subnet, $mask) = explode('/', $cidr);
            $subnet_unsigned = (float) sprintf('%u', ip2long($subnet));
            $network_size = pow(2, (32 - (int)$mask));
            
            if ($ip_unsigned >= $subnet_unsigned && $ip_unsigned < ($subnet_unsigned + $network_size)) {
                return true;
            }
        }
        return false;
    }
}

// ==== 1.4 新增：动态获取可用的中国移动探测节点 ====
if (!function_exists('get_active_cm_locations')) {
    function get_active_cm_locations() {
        // 第一层缓存：当前 PHP 进程内存，避免单次批量查询时重复请求
        static $memoryCache = null;
        if ($memoryCache !== null) {
            return $memoryCache;
        }

        global $redis;
        $cacheKey = "globalping_cm_asns_cache";
        
        // 第二层缓存：Redis 缓存 10 分钟
        if (isset($redis) && $redis) {
            $cached = $redis->get($cacheKey);
            if ($cached) {
                $memoryCache = json_decode($cached, true);
                if (is_array($memoryCache) && !empty($memoryCache)) {
                    return $memoryCache;
                }
            }
        }

        // 纯中国移动 ASN 列表
        $pureCmAsns = [
            9808, 9314, 24311, 24400, 24445, 56040, 56041, 56042, 
            56043, 56044, 56045, 56046, 56047, 56048, 137539
        ];

        // 请求节点列表
        $ch = curl_init('https://api.globalping.io/v1/probes');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: Globalping-Scanner/1.0']
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        $activeAsns = [];
        if ($res) {
            $probes = json_decode($res, true);
            if (is_array($probes)) {
                foreach ($probes as $probe) {
                    $loc = $probe['location'] ?? [];
                    $asn = (int)($loc['asn'] ?? 0);
                    $country = strtoupper($loc['country'] ?? '');
                    $ready = $probe['ready'] ?? false;
                    
                    if ($country === 'CN' && $ready && in_array($asn, $pureCmAsns)) {
                        $activeAsns[$asn] = true; // 去重
                    }
                }
            }
        }

        $asnList = array_keys($activeAsns);

        // 如果 API 失败或者没有可用的节点，提供硬编码的兜底方案（移动骨干及常见省网）
        if (empty($asnList)) {
            $asnList = [9808, 24400, 56041, 24445, 56046];
        }

        // 随机打乱，避免只盯着几个固定节点薅
        shuffle($asnList);
        
        // 最多取 3 个可用节点
        $selected = array_slice($asnList, 0, 3);
        
        // 格式化为 API 需要的 locations 结构
        $locations = array_map(function($asn) {
            return ['asn' => $asn];
        }, $selected);

        $memoryCache = $locations;

        // 写入 Redis 缓存，存活时间 10 分钟 (600秒)
        if (isset($redis) && $redis) {
            $redis->setex($cacheKey, 600, json_encode($locations));
        }

        return $locations;
    }
}

// ==== 1.5 新增：Globalping 双盲交叉验证核心引擎 (Redis 6小时缓存版) ====
if (!function_exists('check_sni_fake_wall')) {
    function check_sni_fake_wall($domain) {
        global $redis; 
        
        // 【核心修改】：通过 Redis 缓存 8小时（28800秒）。6小时内测过的域名直接读缓存跳过，完美匹配定时任务周期！
        // $cacheKey = "sni_wall_cache:" . md5($domain);
        // if ($redis) {
        //     $cached = $redis->get($cacheKey);
        //     if ($cached !== false && $cached !== null) {
        //         return $cached; 
        //     }
        // }

        // 获取动态的有效移动探测节点
        $dynamicLocations = get_active_cm_locations();

        // 引入外层变量 dynamicLocations
        $createTask = function($target) use ($dynamicLocations) {
            $payload = json_encode([
                'type' => 'http',
                'target' => $target,
                'locations' => $dynamicLocations,
                'measurementOptions' => ['request' => ['method' => 'HEAD']],
                'limit' => count($dynamicLocations) // 动态限制总数量，避免请求量超载报错
            ]);
            $ch = curl_init('https://api.globalping.io/v1/measurements');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: Mozilla/5.0'],
                CURLOPT_TIMEOUT => 5
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($code === 202) {
                $data = json_decode($res, true);
                return $data['id'] ?? null;
            }
            return null;
        };

        $targetId = $createTask($domain);
        $controlId = $createTask('www.baidu.com');

        // 如果任务分配失败，安全放行
        if (!$targetId || !$controlId) {
            return 'clean';
        }

        $pollTask = function($id) {
            $ch = curl_init("https://api.globalping.io/v1/measurements/{$id}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_TIMEOUT => 5
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            return json_decode($res, true);
        };

        $targetData = null;
        $controlData = null;
        $attempts = 0;

        while ($attempts < 10) {
            sleep(2);
            $attempts++;
            if (!$targetData || ($targetData['status'] ?? '') !== 'finished') $targetData = $pollTask($targetId);
            if (!$controlData || ($controlData['status'] ?? '') !== 'finished') $controlData = $pollTask($controlId);
            if (($targetData['status'] ?? '') === 'finished' && ($controlData['status'] ?? '') === 'finished') break;
        }

        if (($targetData['status'] ?? '') !== 'finished' || ($controlData['status'] ?? '') !== 'finished') {
            return 'clean'; 
        }

        $controlMap = [];
        foreach ($controlData['results'] as $r) {
            $key = ($r['probe']['city'] ?? '') . '_AS' . ($r['probe']['asn'] ?? '');
            $controlMap[$key] = $r;
        }

        $wallFound = false;
        foreach ($targetData['results'] as $tItem) {
            $key = ($tItem['probe']['city'] ?? '') . '_AS' . ($tItem['probe']['asn'] ?? '');
            if (!isset($controlMap[$key])) continue;

            $cItem = $controlMap[$key];
            $cRaw = strtolower($cItem['result']['rawOutput'] ?? '');
            
            $cSuccess = (!empty($cItem['result']['rawHeaders']) || strpos($cRaw, 'http/1.1 200') !== false || ($cItem['result']['status'] ?? '') === 'finished');
            
            if ($cSuccess) {
                $tRaw = strtolower($tItem['result']['rawOutput'] ?? '');
                $tSuccess = (!empty($tItem['result']['rawHeaders']) || strpos($tRaw, 'http/1.1 200') !== false || ($tItem['result']['status'] ?? '') === 'finished');
                
                $tReset = (strpos($tRaw, 'reset by peer') !== false || strpos($tRaw, 'connection reset') !== false || strpos($tRaw, 'econnreset') !== false);
                
                if (!$tSuccess && $tReset) {
                    $wallFound = true;
                    break;
                }
            }
        }

        $result = $wallFound ? 'fake_wall' : 'clean';
        
        // 写入 Redis 缓存，有效期 8小时 (28800秒)
        // if ($redis) {
        //     $redis->setex($cacheKey, 28800, $result);
        // }

        return $result;
    }
}

// ==== 2. 封装核心检测函数 ====
if (!function_exists('check_mainland_accessibility')) {
    function check_mainland_accessibility($domain) {
        $dns_servers = [
            '202.98.192.67', '202.98.192.67', '202.98.192.67', '202.98.192.67',
            '202.98.198.167', '202.98.198.167', '202.98.198.167', '202.98.198.167'
        ]; 

        $rand_id = random_bytes(2);
        $header = $rand_id . "\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00";
        $qname = '';
        foreach (explode('.', $domain) as $part) {
            $qname .= chr(strlen($part)) . $part;
        }
        $qname .= "\x00";
        $question = $qname . "\x00\x01\x00\x01"; 
        $payload = $header . $question;

        $sockets = [];
        foreach ($dns_servers as $dns) {
            $sock = @stream_socket_client("udp://$dns:53", $errno, $errstr, 1, STREAM_CLIENT_ASYNC_CONNECT);
            if ($sock) {
                stream_set_blocking($sock, false); 
                fwrite($sock, $payload);
                $sockets[(int)$sock] = ['sock' => $sock, 'dns' => $dns];
            }
        }

        $timeout = 2.0; 
        $endTime = microtime(true) + $timeout;

        while (!empty($sockets) && microtime(true) < $endTime) {
            $read = array_column($sockets, 'sock');
            $write = null;
            $except = null;
            
            $timeLeft = max(0, $endTime - microtime(true));
            $tv_sec = (int) floor($timeLeft);
            $tv_usec = (int) (($timeLeft - $tv_sec) * 1000000);

            if (stream_select($read, $write, $except, $tv_sec, $tv_usec) > 0) {
                foreach ($read as $sock) {
                    $response = fread($sock, 512);
                    $sockId = (int)$sock;
                    
                    if (!isset($sockets[$sockId])) continue;
                    fclose($sock);
                    unset($sockets[$sockId]); 

                    if (empty($response) || strlen($response) < 12) continue;
                    
                    $ans_count = (ord($response[6]) << 8) + ord($response[7]);
                    if ($ans_count === 0) continue;

                    $offset = 12 + strlen($qname) + 4;
                    $ips = [];
                    for ($i = 0; $i < $ans_count; $i++) {
                        if ($offset >= strlen($response)) break;
                        while ($offset < strlen($response)) {
                            $len = ord($response[$offset]);
                            if (($len & 0xC0) === 0xC0) { $offset += 2; break; }
                            elseif ($len === 0) { $offset += 1; break; }
                            else { $offset += $len + 1; }
                        }
                        if ($offset + 10 > strlen($response)) break;
                        
                        $type = (ord($response[$offset]) << 8) + ord($response[$offset+1]);
                        $class = (ord($response[$offset+2]) << 8) + ord($response[$offset+3]);
                        $rdlength = (ord($response[$offset+8]) << 8) + ord($response[$offset+9]);
                        $offset += 10;
                        
                        if ($type === 1 && $class === 1 && $rdlength === 4) {
                            $ips[] = ord($response[$offset]) . '.' . ord($response[$offset+1]) . '.' . ord($response[$offset+2]) . '.' . ord($response[$offset+3]);
                        }
                        $offset += $rdlength;
                    }

                    if (!empty($ips)) {
                        $testIp = $ips[0];
                        cleanup_sockets($sockets);

                        if ($testIp === '127.0.0.1' || $testIp === '0.0.0.0' || strpos($testIp, '127.') === 0) {
                            return ['status' => 'polluted', 'ip' => $testIp, 'msg' => "遭墙阻断 (骨干节点强指黑洞)"];
                        }

                        if (is_cf_ip($testIp)) {
                            // 【熔断降级处理】：触发深层 SNI 对照验证
                            $sniResult = check_sni_fake_wall($domain);
                            if ($sniResult === 'fake_wall') {
                                return ['status' => 'fake_wall', 'ip' => $testIp, 'msg' => "局部阻断 (移动 SNI 假墙)"];
                            } elseif ($sniResult === 'quota_exceeded') {
                                return ['status' => 'clean', 'ip' => $testIp, 'msg' => "访问正常 (API额度熔断，仅验证UDP)"];
                            }
                            return ['status' => 'clean', 'ip' => $testIp, 'msg' => "访问正常 (三网骨干验证纯净)"];
                        } else {
                            return ['status' => 'polluted', 'ip' => $testIp, 'msg' => "遭墙污染 (骨干节点劫持脏IP)"];
                        }
                    }
                }
            }
        }
        
        cleanup_sockets($sockets);
        return ['status' => 'error', 'ip' => '--', 'msg' => '⚠️ 解析异常 (跨海 UDP 严重丢包)'];
    }
}
if (!function_exists('cleanup_sockets')) {
    function cleanup_sockets(&$sockets) {
        foreach ($sockets as $s) {
            if (is_resource($s['sock'])) {
                fclose($s['sock']);
            }
        }
        $sockets = [];
    }
}

if (!function_exists('check_domain_health')) {
    function check_domain_health($domain) {
        $res = check_mainland_accessibility($domain);
        return [
            'successCount' => ($res['status'] === 'clean') ? 3 : 0,
            'maxRetries' => 3,
            'lastParsed' => [
                'code' => ($res['status'] === 'clean') ? 200 : -1,
                'data' => $res['ip'],
                'msg' => $res['msg'],
                'status' => $res['status']
            ]
        ];
    }
}

// ==== 3. 核心拦截：CLI 执行中止 ====
if (php_sapi_name() === 'cli') {
    return;
}

// ==== 以下保持您原有的 Web 渲染、AJAX 请求等逻辑不变 ====
require_once __DIR__ . '/init.php';
if ($siteId > 0 && !$selectedSite) {
    die('您无权访问该站点的数据。');
}

$allDomains = [];
if ($selectedSite) {
    $allDomains[] = $selectedSite['domain'];
    $extraDomains = $tracker->getSiteDomains($siteId);
    foreach ($extraDomains as $ed) {
        if (!empty($ed['domain'])) {
            $allDomains[] = $ed['domain'];
        }
    }
    $allDomains = array_values(array_unique($allDomains));
}

$limitKey = "domain_check_limit:{$siteId}";
$resultKey = "domain_check_results:{$siteId}";
$isAdmin = $GLOBALS['is_admin'] ?? false;
$ttl = $redis->ttl($limitKey);

$canCheck = $isAdmin ? true : ($ttl <= 0);

$cachedResults = [];
$rawResults = $redis->get($resultKey);
if ($rawResults) {
    $cachedResults = json_decode($rawResults, true) ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_single') {
    header('Content-Type: application/json');
    $domain = $_POST['domain'] ?? '';
    if (empty($domain)) { echo json_encode(['error' => '域名不能为空']); exit; }
    if (!in_array($domain, $allDomains)) { echo json_encode(['error' => '非法请求']); exit; }
    
    $res = check_mainland_accessibility($domain);
    echo json_encode(['success' => true, 'domain' => $domain, 'result' => $res]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_cache') {
    header('Content-Type: application/json');
    $rawData = $_POST['data'] ?? '[]';
    if (strlen($rawData) > 102400) { echo json_encode(['success' => false]); exit; }

    $allResults = json_decode($rawData, true);
    if (is_array($allResults) && !empty($allResults)) {
        $cleanResults = [];
        foreach ($allDomains as $d) {
            if (isset($allResults[$d])) $cleanResults[$d] = $allResults[$d];
        }
        if (!$isAdmin) $redis->setex($limitKey, 20 * 60, time()); 
        $redis->setex($resultKey, 86400, json_encode($cleanResults, JSON_UNESCAPED_UNICODE));
    }
    echo json_encode(['success' => true]);
    exit;
}

require __DIR__ . '/layout.php';
render_head('网站检测 - 统计后台');
render_topbar($branding);
?>

<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'domain_check', $range); ?>
    
    <main class="content">
        <section class="card">
            <div class="section-title" style="margin-bottom: 24px;">
                <h2 style="margin:0; font-size: 18px; color: #17233d;">GFW 防火墙深度检测引擎</h2>
                <span class="muted" style="display:block; margin-top: 6px;">
                    结合UDP 探针与跨海分布式节点，深度检测 DNS 污染及隐藏的SNI 阻断。<br>
                    <span style="color:#ff9900;font-size:12px;">※ 注：深度检测会调用分布式三网节点回传，过程可能需要 15-20 秒，请耐心等待。</span><br>
                    <?= $isAdmin ? '<span style="color:#2d8cf0;font-size:12px;">[管理员特权] 无冷却时间限制。</span>' : '<span style="font-size:12px;">(批量冷却时间：10分钟)</span>' ?>
                </span>
            </div>

            <?php if (!$selectedSite): ?>
                <div class="empty">请选择站点后进行检测。</div>
            <?php elseif (empty($allDomains)): ?>
                <div class="empty">该站点尚未配置任何域名。</div>
            <?php else: ?>
                <div style="margin-bottom: 20px; display: flex; align-items: center; gap: 12px;">
                    <button id="start-btn" 
                            style="padding: 8px 16px; border-radius: 4px; font-weight: 500; cursor: <?= $canCheck ? 'pointer' : 'not-allowed' ?>; background: <?= $canCheck ? '#2d8cf0' : '#f8f8f9' ?>; color: <?= $canCheck ? '#fff' : '#c5c8ce' ?>; border: 1px solid <?= $canCheck ? '#2d8cf0' : '#dcdee2' ?>; transition: all 0.2s;"
                            <?= !$canCheck ? 'disabled' : '' ?> 
                            onclick="startDetection()">
                        <?= $canCheck ? '深度批量检测' : '冷却中 (' . ceil($ttl/60) . '分钟后可用)' ?>
                    </button>
                    <span id="progress-text" style="font-size: 13px; color: #808695; display: none;">进度: 0/<?= count($allDomains) ?></span>
                    
                    <?php if (!$canCheck && !empty($cachedResults)): ?>
                        <span style="font-size: 13px; color: #ff9900;" id="cache-tip">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:2px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            当前显示为上次检测结果
                        </span>
                    <?php endif; ?>
                </div>

                <table style="width: 100%; border-collapse: collapse; text-align: left; background: #fff;">
                    <thead>
                        <tr style="border-bottom: 1px solid #e8eaec;">
                            <th style="padding: 12px; color: #515a6e; font-weight: 600;">待检测域名</th>
                            <th style="padding: 12px; color: #515a6e; font-weight: 600;">大陆区解析 IP</th>
                            <th style="padding: 12px; color: #515a6e; font-weight: 600;">深度监测状态</th>
                            <th style="padding: 12px; color: #515a6e; font-weight: 600;">诊断详情</th>
                            <th style="padding: 12px; color: #515a6e; font-weight: 600; width: 80px; text-align: center;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allDomains as $idx => $domain): ?>
                            <?php 
                                $res = $cachedResults[$domain] ?? null;
                                $ipHtml = '<span style="color: #808695;">未检测</span>';
                                $statusHtml = '--';
                                $msgHtml = '--';

                                if ($res) {
                                    $ipHtml = '<span style="font-family: monospace; color:#515a6e;">' . htmlspecialchars($res['ip'] ?? '--') . '</span>';
                                    
                                    if (($res['status'] ?? '') === 'clean') {
                                        $statusHtml = '<span style="color:#19be6b; font-weight:600;">✅ 正常通畅</span>';
                                        $msgHtml = '<span style="color:#808695">' . htmlspecialchars($res['msg'] ?? '--') . '</span>';
                                    } elseif (($res['status'] ?? '') === 'polluted') {
                                        $statusHtml = '<span style="color:#ed4014; font-weight:600;">🚨 骨干网被墙</span>';
                                        $msgHtml = '<span style="color:#ed4014">' . htmlspecialchars($res['msg'] ?? '--') . '</span>';
                                    } elseif (($res['status'] ?? '') === 'fake_wall') {
                                        // 【深度渲染】当探针返回 fake_wall 时，精准展示局部阻断
                                        $statusHtml = '<span style="color:#eab308; font-weight:600;">⚠️ 局部阻断墙</span>';
                                        $msgHtml = '<span style="color:#eab308">' . htmlspecialchars($res['msg'] ?? '--') . '</span>';
                                    } else {
                                        $statusHtml = '<span style="color:#ff9900; font-weight:600;">⚠️ 解析异常</span>';
                                        $msgHtml = '<span style="color:#ff9900">' . htmlspecialchars($res['msg'] ?? '--') . '</span>';
                                    }
                                }
                            ?>
                            <tr style="border-bottom: 1px solid #f0f0f0;">
                                <td style="padding: 12px; font-family: monospace; color: #17233d;"><?= htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="padding: 12px;" id="ip-<?= $idx ?>"><?= $ipHtml ?></td>
                                <td style="padding: 12px;" id="status-<?= $idx ?>"><?= $statusHtml ?></td>
                                <td style="padding: 12px;" id="msg-<?= $idx ?>"><?= $msgHtml ?></td>
                                <td style="padding: 12px; text-align: center;">
                                    <button class="single-check-btn" id="btn-<?= $idx ?>" 
                                            onclick="checkSingleDomain(<?= $idx ?>, '<?= htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') ?>')" 
                                            style="background: #fff; border: 1px solid #dcdee2; color: #515a6e; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.2s;">
                                        检测
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</div>

<style>
.single-check-btn:hover:not(:disabled) {
    border-color: #2d8cf0;
    color: #2d8cf0;
}
.single-check-btn:disabled {
    background: #f8f8f9 !important;
    color: #c5c8ce !important;
    cursor: not-allowed !important;
}
</style>

<script>
const domains = <?= json_encode($allDomains) ?>;
const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
let completedCount = 0;

let currentCache = <?= json_encode((object)$cachedResults, JSON_UNESCAPED_UNICODE) ?>;

async function startDetection() {
    const btn = document.getElementById('start-btn');
    const progressText = document.getElementById('progress-text');
    
    btn.disabled = true;
    btn.innerText = '正在执行双盲测试...';
    btn.style.background = '#57a3f3';
    btn.style.cursor = 'wait';
    document.querySelectorAll('.single-check-btn').forEach(b => b.disabled = true);
    
    progressText.style.display = 'inline';
    progressText.innerText = `进度: 0/${domains.length}`;
    completedCount = 0;
    let finalResultsCache = {};

    domains.forEach((dom, idx) => {
        document.getElementById('ip-' + idx).innerHTML = '<span style="color:#2d8cf0;">📡 探测中...</span>';
        document.getElementById('status-' + idx).innerText = '--';
        document.getElementById('msg-' + idx).innerText = '--';
    });

    const maxConcurrency = 3; 
    let currentIndex = 0;

    async function worker() {
        while (currentIndex < domains.length) {
            const idx = currentIndex++;
            const domain = domains[idx];

            const formData = new FormData();
            formData.append('action', 'check_single');
            formData.append('domain', domain);

            try {
                const res = await fetch('', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success && data.result) {
                    renderSingleResult(idx, data.result);
                    finalResultsCache[domain] = data.result;
                } else {
                    renderSingleResult(idx, { status: 'error', ip: '--', msg: data.error || '接口异常' });
                }
            } catch (err) {
                renderSingleResult(idx, { status: 'error', ip: '--', msg: '网络断开' });
            }
            
            completedCount++;
            progressText.innerText = `进度: ${completedCount}/${domains.length}`;
        }
    }

    const workers = [];
    for (let i = 0; i < maxConcurrency; i++) {
        workers.push(worker());
    }

    await Promise.all(workers);

    if (Object.keys(finalResultsCache).length > 0) {
        currentCache = { ...currentCache, ...finalResultsCache };
        const saveForm = new FormData();
        saveForm.append('action', 'save_cache');
        saveForm.append('data', JSON.stringify(currentCache));
        await fetch('', { method: 'POST', body: saveForm });
    }

    if (isAdmin) {
        btn.innerText = '深度批量检测';
        btn.style.background = '#2d8cf0';
        btn.style.color = '#fff';
        btn.style.borderColor = '#2d8cf0';
        btn.style.cursor = 'pointer';
        btn.disabled = false;
    } else {
        btn.innerText = '检测完成 (10分钟冷却)';
        btn.style.background = '#f8f8f9';
        btn.style.color = '#c5c8ce';
        btn.style.borderColor = '#dcdee2';
        btn.style.cursor = 'not-allowed';
    }
    
    document.querySelectorAll('.single-check-btn').forEach(b => b.disabled = false);
    progressText.style.display = 'none';
    const oldTip = document.getElementById('cache-tip');
    if (oldTip) oldTip.remove();
    
    const tipSpan = document.createElement('span');
    tipSpan.id = 'cache-tip';
    tipSpan.style = 'font-size: 13px; color: #19be6b; margin-left: 12px;';
    tipSpan.innerHTML = '✅ 双盲探测完毕';
    btn.parentNode.appendChild(tipSpan);
}

async function checkSingleDomain(idx, domain) {
    const btn = document.getElementById('btn-' + idx);
    btn.disabled = true;
    btn.innerText = '穿透中..';

    document.getElementById('ip-' + idx).innerHTML = '<span style="color:#2d8cf0;">📡 调度探针...</span>';
    document.getElementById('status-' + idx).innerText = '--';
    document.getElementById('msg-' + idx).innerText = '--';

    const formData = new FormData();
    formData.append('action', 'check_single');
    formData.append('domain', domain);

    try {
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success && data.result) {
            renderSingleResult(idx, data.result);
            
            currentCache[domain] = data.result;
            const saveForm = new FormData();
            saveForm.append('action', 'save_cache');
            saveForm.append('data', JSON.stringify(currentCache));
            fetch('', { method: 'POST', body: saveForm }); 

        } else {
            renderSingleResult(idx, { status: 'error', ip: '--', msg: data.error || '接口异常' });
        }
    } catch (err) {
        renderSingleResult(idx, { status: 'error', ip: '--', msg: '网络断开' });
    }

    btn.disabled = false;
    btn.innerText = '检测';
}

function renderSingleResult(idx, res) {
    let statusHtml = '';
    let msgHtml = '';
    
    const ipHtml = `<span style="font-family: monospace; color:#515a6e;">${res.ip || '--'}</span>`;

    if (res.status === 'clean') {
        statusHtml = '<span style="color:#19be6b; font-weight:600;">✅ 正常通畅</span>';
        msgHtml = `<span style="color:#808695">${res.msg}</span>`;
    } else if (res.status === 'polluted') {
        statusHtml = '<span style="color:#ed4014; font-weight:600;">🚨 骨干网被墙</span>';
        msgHtml = `<span style="color:#ed4014">${res.msg}</span>`;
    } else if (res.status === 'fake_wall') {
        statusHtml = '<span style="color:#eab308; font-weight:600;">⚠️ 局部阻断墙</span>';
        msgHtml = `<span style="color:#eab308">${res.msg}</span>`;
    } else {
        statusHtml = '<span style="color:#ff9900; font-weight:600;">⚠️ 解析异常</span>';
        msgHtml = `<span style="color:#ff9900">${res.msg}</span>`;
    }

    document.getElementById('ip-' + idx).innerHTML = ipHtml;
    document.getElementById('status-' + idx).innerHTML = statusHtml;
    document.getElementById('msg-' + idx).innerHTML = msgHtml;
}
</script>

<?php render_footer(); ?>
