<?php
// ==== 1. 封装公共检测函数，供 CLI 定时任务和 AJAX 共用 ====
if (!function_exists('check_domain_health')) {
    function check_domain_health($domain) {
        $targetUrl = str_starts_with($domain, 'http') ? $domain : "http://www." . $domain;
        $apiUrl = "https://v2.xxapi.cn/api/status?url=" . urlencode($targetUrl);
        
        $successCount = 0;
        $lastParsed = null;
        $maxRetries = 3; 
        
        for ($i = 0; $i < $maxRetries; $i++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 4); 
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            curl_close($ch);

            if ($response !== false) {
                $parsed = json_decode($response, true);
                if ($parsed && isset($parsed['code'])) {
                    $lastParsed = $parsed;
                    if ($parsed['code'] == 200 && ($parsed['data'] == '200' || $parsed['data'] == '403')) {
                        $successCount++;
                    }
                }
            }
            usleep(300000);
        }
        return ['successCount' => $successCount, 'lastParsed' => $lastParsed, 'maxRetries' => $maxRetries];
    }
}
// =========================================================

// ==== 2. 核心拦截：如果是 CLI 定时任务加载，立刻终止文件后续执行 ====
// 必须放在 init.php 之前，防止触发 Session 和 403 拦截！
if (php_sapi_name() === 'cli') {
    return;
}
// ====================================================================

// ==== 3. 下面是 Web 环境专属逻辑 ====
require_once __DIR__ . '/init.php';

// 防止水平越权
if ($siteId > 0 && !$selectedSite) {
    die('您无权访问该站点的数据。');
}

// 获取当前站点所有绑定的域名
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

// 管理员特权：随时可测
$canCheck = $isAdmin ? true : ($ttl <= 0);

// 获取上次缓存的检测结果
$cachedResults = [];
$rawResults = $redis->get($resultKey);
if ($rawResults) {
    $cachedResults = json_decode($rawResults, true) ?: [];
}

// ================= 处理 AJAX 接口：每次只处理【单个】域名 =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_single') {
    header('Content-Type: application/json');
    $domain = $_POST['domain'] ?? '';
    
    if (empty($domain)) {
        echo json_encode(['error' => '域名不能为空']);
        exit;
    }

    // [安全修复 1]：防止越权检测任意域名（防御滥用你的服务器作为免费代理）
    if (!in_array($domain, $allDomains)) {
        echo json_encode(['error' => '非法请求：该域名不属于当前站点']);
        exit;
    }

    // [安全修复 2]：防止绕过前端冷却时间恶意并发请求（防御接口层面的 CC/资源耗尽攻击）
    if (!$canCheck) {
        echo json_encode(['error' => '检测冷却中，请稍后再试']);
        exit;
    }

    $checkRes = check_domain_health($domain);
    $lastParsed = $checkRes['lastParsed'];

    $finalResult = [];
    if ($lastParsed) {
        $lastParsed['success_rate'] = $checkRes['successCount'] . '/' . $checkRes['maxRetries'];
        $finalResult = $lastParsed;
    } else {
        $finalResult = ['code' => -1, 'msg' => '请求超时或无响应', 'data' => '--', 'success_rate' => '0/3'];
    }

    echo json_encode(['success' => true, 'domain' => $domain, 'result' => $finalResult]);
    exit;
}

// ================= 处理 AJAX 接口：检测全部完成后写入缓存 =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_cache') {
    header('Content-Type: application/json');
    
    // [安全修复 3.1]：限制传入数据的大小，防止恶意塞入超大 Payload 导致 Redis 内存耗尽
    $rawData = $_POST['data'] ?? '[]';
    if (strlen($rawData) > 102400) { // 限制最大 100KB
        echo json_encode(['success' => false, 'error' => 'Payload too large']);
        exit;
    }

    $allResults = json_decode($rawData, true);
    
    if (is_array($allResults) && !empty($allResults)) {
        // [安全修复 3.2]：清洗缓存数据，只保存当前站点实际配置的域名数据（防御缓存投毒）
        $cleanResults = [];
        foreach ($allDomains as $d) {
            if (isset($allResults[$d])) {
                $cleanResults[$d] = $allResults[$d];
            }
        }

        if (!$isAdmin) {
            $redis->setex($limitKey, 10 * 60, time()); // 10分钟冷却
        }
        // 只将清洗后的安全数据写入 Redis
        $redis->setex($resultKey, 86400, json_encode($cleanResults, JSON_UNESCAPED_UNICODE));
    }
    echo json_encode(['success' => true]);
    exit;
}
// ======================================================

require __DIR__ . '/layout.php';
render_head('网站检测 - 统计后台');
render_topbar($branding);
?>

<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'domain_check', $range); ?>
    
    <main class="content">
        <section class="card">
            <div class="section-title" style="margin-bottom: 24px;">
                <h2 style="margin:0; font-size: 18px; color: #17233d;">网站连通性检测</h2>
                <span class="muted" style="display:block; margin-top: 6px;">
                    采用电信线检测，快速获取所有域名状态。
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
                            <th style="padding: 12px; color: #515a6e; font-weight: 600;">健康度 (3次连测)</th>
                            <th style="padding: 12px; color: #515a6e; font-weight: 600;">状态判决</th>
                            <th style="padding: 12px; color: #515a6e; font-weight: 600;">最新反馈</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allDomains as $idx => $domain): ?>
                            <?php 
                                $res = $cachedResults[$domain] ?? null;
                                $rateHtml = '<span style="color: #808695;">未检测</span>';
                                $statusHtml = '--';
                                $msgHtml = '--';

                                if ($res) {
                                    $rate = $res['success_rate'] ?? '0/3';
                                    list($succ, $total) = explode('/', $rate);
                                    
                                    if ($succ == 3) {
                                        $rateHtml = '<span style="color:#19be6b; font-weight:600;">3/3 (极速)</span>';
                                        $statusHtml = '<span style="color:#19be6b; font-weight:600;">访问正常</span>';
                                    } elseif ($succ == 2) {
                                        $rateHtml = '<span style="color:#ff9900; font-weight:600;">2/3 (轻微干扰)</span>';
                                        $statusHtml = '<span style="color:#ff9900; font-weight:600;">偶发丢包</span>';
                                    } elseif ($succ == 1) {
                                        $rateHtml = '<span style="color:#ed4014; font-weight:600;">1/3 (严重干扰)</span>';
                                        $statusHtml = '<span style="color:#ed4014; font-weight:600;">间歇性阻断</span>';
                                    } else {
                                        $rateHtml = '<span style="color:#ed4014; font-weight:600;">0/3 (彻底断开)</span>';
                                        $statusHtml = '<span style="color:#ed4014; font-weight:600; text-decoration: underline;">已被墙/宕机</span>';
                                    }
                                    
                                    $codeStr = $res['data'] ?: 'N/A';
                                    $msgColor = ($res['code'] === 200 && ($codeStr == '200' || $codeStr == '403')) ? '#19be6b' : '#ed4014';
                                    $msgHtml = '<span style="color:#808695">HTTP: <span style="color:'.$msgColor.'">' . htmlspecialchars($codeStr, ENT_QUOTES, 'UTF-8') . '</span> | ' . htmlspecialchars($res['msg'] ?? '--', ENT_QUOTES, 'UTF-8') . '</span>';
                                }
                            ?>
                            <tr style="border-bottom: 1px solid #f0f0f0;">
                                <td style="padding: 12px; font-family: monospace; color: #17233d;"><?= htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="padding: 12px;" id="rate-<?= $idx ?>"><?= $rateHtml ?></td>
                                <td style="padding: 12px;" id="status-<?= $idx ?>"><?= $statusHtml ?></td>
                                <td style="padding: 12px; color: #808695;" id="msg-<?= $idx ?>"><?= $msgHtml ?></td>
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
        document.getElementById('rate-' + idx).innerHTML = '<span style="color:#2d8cf0;">📡 队列中...</span>';
        document.getElementById('status-' + idx).innerText = '--';
        document.getElementById('msg-' + idx).innerText = '--';
    });

    const maxConcurrency = 5; 
    let currentIndex = 0;

    async function worker() {
        while (currentIndex < domains.length) {
            const idx = currentIndex++;
            const domain = domains[idx];

            document.getElementById('rate-' + idx).innerHTML = '<span style="color:#2d8cf0;">📡 测速中...</span>';

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
                    renderSingleResult(idx, { code: -1, data: '--', msg: '接口异常', success_rate: '0/3' });
                }
            } catch (err) {
                renderSingleResult(idx, { code: -1, data: '--', msg: '网络断开', success_rate: '0/3' });
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
    tipSpan.innerHTML = '✅ 所有域名已安全检测完毕';
    btn.parentNode.appendChild(tipSpan);
}

function renderSingleResult(idx, res) {
    const rate = res.success_rate || '0/3';
    const succ = parseInt(rate.split('/')[0]);
    
    let rateHtml, statusHtml;
    if (succ === 3) {
        rateHtml = '<span style="color:#19be6b; font-weight:600;">3/3 (极速)</span>';
        statusHtml = '<span style="color:#19be6b; font-weight:600;">访问正常</span>';
    } else if (succ === 2) {
        rateHtml = '<span style="color:#ff9900; font-weight:600;">2/3 (轻微干扰)</span>';
        statusHtml = '<span style="color:#ff9900; font-weight:600;">偶发丢包</span>';
    } else if (succ === 1) {
        rateHtml = '<span style="color:#ed4014; font-weight:600;">1/3 (严重干扰)</span>';
        statusHtml = '<span style="color:#ed4014; font-weight:600;">间歇性阻断</span>';
    } else {
        rateHtml = '<span style="color:#ed4014; font-weight:600;">0/3 (彻底断开)</span>';
        statusHtml = '<span style="color:#ed4014; font-weight:600; text-decoration: underline;">已被墙/宕机</span>';
    }

    document.getElementById('rate-' + idx).innerHTML = rateHtml;
    document.getElementById('status-' + idx).innerHTML = statusHtml;
    
    const codeStr = res.data || 'N/A';
    const msgColor = (res.code === 200 && (codeStr == '200' || codeStr == '403')) ? '#19be6b' : '#ed4014';
    document.getElementById('msg-' + idx).innerHTML = `<span style="color:#808695">HTTP: <span style="color:${msgColor}">${codeStr}</span> | ${res.msg || '--'}</span>`;
}
</script>

<?php render_footer(); ?>
