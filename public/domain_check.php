<?php
// ==== 1. 封装核心检测函数：纯 PHP 异步并发 UDP 探针 (极致并发版) ====

if (!function_exists('check_mainland_accessibility')) {
    function check_mainland_accessibility($domain) {
        // 4个绝对纯正的大陆省级节点
        $dns_servers = ['202.96.128.86', '202.106.0.20', '218.30.118.6', '221.12.1.227']; 
        $cf_ranges = [
            '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '104.16.0.0/13',
            '104.24.0.0/14', '108.162.192.0/18', '131.0.72.0/22', '141.101.64.0/18',
            '162.158.0.0/15', '172.64.0.0/13', '173.245.48.0/20', '188.114.96.0/20',
            '190.93.240.0/20', '197.234.240.0/22', '198.41.128.0/17'
        ];

        // 1. 构造原生 DNS UDP 数据包
        $rand_id = random_bytes(2);
        $header = $rand_id . "\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00";
        $qname = '';
        foreach (explode('.', $domain) as $part) {
            $qname .= chr(strlen($part)) . $part;
        }
        $qname .= "\x00";
        $question = $qname . "\x00\x01\x00\x01"; // A记录(1), IN类(1)
        $payload = $header . $question;

        // 2. 创建异步非阻塞 Sockets，瞬间向所有大陆节点齐发探针
        $sockets = [];
        foreach ($dns_servers as $dns) {
            $sock = @stream_socket_client("udp://$dns:53", $errno, $errstr, 1, STREAM_CLIENT_ASYNC_CONNECT);
            if ($sock) {
                stream_set_blocking($sock, false); // 设为非阻塞
                fwrite($sock, $payload);
                $sockets[(int)$sock] = ['sock' => $sock, 'dns' => $dns];
            }
        }

        // 3. IO 多路复用监听 (总超时强制锁定在 1.2 秒)
        $timeout = 1.2; 
        $endTime = microtime(true) + $timeout;
        $clean_ips = [];

        while (!empty($sockets) && microtime(true) < $endTime) {
            $read = array_column($sockets, 'sock');
            $write = null;
            $except = null;
            
            // 计算剩余可等待的微秒数
            $timeLeft = max(0, $endTime - microtime(true));
            $tv_sec = (int) floor($timeLeft);
            $tv_usec = (int) (($timeLeft - $tv_sec) * 1000000);

            // 监听哪个节点最先返回数据
            if (stream_select($read, $write, $except, $tv_sec, $tv_usec) > 0) {
                foreach ($read as $sock) {
                    $response = fread($sock, 512);
                    $sockId = (int)$sock;
                    unset($sockets[$sockId]); // 收到了就把它从等待池剔除

                    if (empty($response) || strlen($response) < 12) continue;
                    
                    // 解析 DNS 响应数量
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
                        if ($testIp === '127.0.0.1' || $testIp === '0.0.0.0') {
                            return ['status' => 'polluted', 'ip' => $testIp, 'msg' => '遭 GFW 污染 (强指回环/空地址)'];
                        }

                        $is_cf = false;
                        $ip_long = ip2long($testIp);
                        if ($ip_long !== false) {
                            foreach ($cf_ranges as $cidr) {
                                list($subnet, $mask) = explode('/', $cidr);
                                // 安全的位运算匹配网段
                                $netmask = ~((1 << (32 - (int)$mask)) - 1) & 0xFFFFFFFF;
                                if (($ip_long & $netmask) === (ip2long($subnet) & $netmask)) {
                                    $is_cf = true;
                                    break;
                                }
                            }
                        }

                        if ($is_cf) {
                            $clean_ips[] = $testIp;
                        } else {
                            // GFW 抢答污染包永远跑得最快，只要发现非 CF，立刻秒判并结束函数！
                            return ['status' => 'polluted', 'ip' => $testIp, 'msg' => '遭 GFW 污染 (强指非CF拦截节点)'];
                        }
                    }
                }
            }
        }

        // 走到这里代表超时结束。如果有任何一个节点查到了干净 IP，就算成功
        if (!empty($clean_ips)) {
            return ['status' => 'clean', 'ip' => $clean_ips[0], 'msg' => '访问正常 (大陆解析至CF)'];
        }

        // 4 个节点并发了 1.2 秒全都彻底没回音，实锤被墙阻断
        return ['status' => 'error', 'ip' => '--', 'msg' => '遭 GFW 阻断 (国内完全无响应)'];
    }
}

// =========================================================
// ==== 2. 核心拦截：如果是 CLI 定时任务加载，立刻终止文件后续执行 ====
if (php_sapi_name() === 'cli') {
    return;
}
// ====================================================================

// ==== 3. 下面是 Web 环境专属逻辑 ====
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
    if (!$canCheck) { echo json_encode(['error' => '检测冷却中']); exit; }

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
                <h2 style="margin:0; font-size: 18px; color: #17233d;">GFW 防火墙状态检测</h2>
                <span class="muted" style="display:block; margin-top: 6px;">
                    本工具内置大陆专线探针，可秒级检测域名是否遭到 DNS 抢答污染或阻断。
                    <?= $isAdmin ? '<span style="color:#2d8cf0;">[管理员特权] 无冷却时间限制。</span>' : '(冷却时间：10分钟)' ?>
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
                        <?= $canCheck ? '开始检测' : '冷却中 (' . ceil($ttl/60) . '分钟后可用)' ?>
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
                            <th style="padding: 12px; color: #515a6e; font-weight: 600;">GFW 状态</th>
                            <th style="padding: 12px; color: #515a6e; font-weight: 600;">详细诊断</th>
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
                                        $statusHtml = '<span style="color:#19be6b; font-weight:600;">✅ 访问正常 (纯净)</span>';
                                        $msgHtml = '<span style="color:#808695">' . htmlspecialchars($res['msg'] ?? '--') . '</span>';
                                    } elseif (($res['status'] ?? '') === 'polluted') {
                                        $statusHtml = '<span style="color:#ed4014; font-weight:600;">🚨 已被墙 (DNS污染)</span>';
                                        $msgHtml = '<span style="color:#ed4014">' . htmlspecialchars($res['msg'] ?? '--') . '</span>';
                                    } else {
                                        $statusHtml = '<span style="color:#ff9900; font-weight:600;">⚠️ 解析异常 (阻断)</span>';
                                        $msgHtml = '<span style="color:#ff9900">' . htmlspecialchars($res['msg'] ?? '--') . '</span>';
                                    }
                                }
                            ?>
                            <tr style="border-bottom: 1px solid #f0f0f0;">
                                <td style="padding: 12px; font-family: monospace; color: #17233d;"><?= htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="padding: 12px;" id="ip-<?= $idx ?>"><?= $ipHtml ?></td>
                                <td style="padding: 12px;" id="status-<?= $idx ?>"><?= $statusHtml ?></td>
                                <td style="padding: 12px;" id="msg-<?= $idx ?>"><?= $msgHtml ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</div>

<script>
const domains = <?= json_encode($allDomains) ?>;
const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
let completedCount = 0;
let finalResultsCache = {};

async function startDetection() {
    const btn = document.getElementById('start-btn');
    const progressText = document.getElementById('progress-text');
    
    btn.disabled = true;
    btn.innerText = '安全检测中...';
    btn.style.background = '#57a3f3';
    btn.style.cursor = 'wait';
    
    progressText.style.display = 'inline';
    progressText.innerText = `进度: 0/${domains.length}`;
    completedCount = 0;
    finalResultsCache = {};

    domains.forEach((dom, idx) => {
        document.getElementById('ip-' + idx).innerHTML = '<span style="color:#2d8cf0;">📡 探测中...</span>';
        document.getElementById('status-' + idx).innerText = '--';
        document.getElementById('msg-' + idx).innerText = '--';
    });

    const maxConcurrency = 5; 
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
                    renderSingleResult(idx, { status: 'error', ip: '--', msg: '接口异常' });
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
        const saveForm = new FormData();
        saveForm.append('action', 'save_cache');
        saveForm.append('data', JSON.stringify(finalResultsCache));
        await fetch('', { method: 'POST', body: saveForm });
    }

    if (isAdmin) {
        btn.innerText = '开始检测';
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
    
    progressText.style.display = 'none';
    const oldTip = document.getElementById('cache-tip');
    if (oldTip) oldTip.remove();
    
    const tipSpan = document.createElement('span');
    tipSpan.id = 'cache-tip';
    tipSpan.style = 'font-size: 13px; color: #19be6b; margin-left: 12px;';
    tipSpan.innerHTML = '✅ 所有域名已探测完毕';
    btn.parentNode.appendChild(tipSpan);
}

function renderSingleResult(idx, res) {
    let statusHtml = '';
    let msgHtml = '';
    
    const ipHtml = `<span style="font-family: monospace; color:#515a6e;">${res.ip || '--'}</span>`;

    if (res.status === 'clean') {
        statusHtml = '<span style="color:#19be6b; font-weight:600;">✅ 访问正常 (纯净)</span>';
        msgHtml = `<span style="color:#808695">${res.msg}</span>`;
    } else if (res.status === 'polluted') {
        statusHtml = '<span style="color:#ed4014; font-weight:600;">🚨 已被墙 (DNS污染)</span>';
        msgHtml = `<span style="color:#ed4014">${res.msg}</span>`;
    } else {
        statusHtml = '<span style="color:#ff9900; font-weight:600;">⚠️ 解析异常 (阻断)</span>';
        msgHtml = `<span style="color:#ff9900">${res.msg}</span>`;
    }

    document.getElementById('ip-' + idx).innerHTML = ipHtml;
    document.getElementById('status-' + idx).innerHTML = statusHtml;
    document.getElementById('msg-' + idx).innerHTML = msgHtml;
}
</script>

<?php render_footer(); ?>
