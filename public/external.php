<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

// === 强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    die('您无权访问该站点的数据。');
}
// ==================================

// 获取当前用户ID (适配系统中可能的 session 结构)
$userId = $_SESSION['user_id'] ?? ($_SESSION['admin_id'] ?? ($selectedSite['user_id'] ?? 0));

// 初始化数据库连接
$config = require __DIR__ . '/../config/config.php';
$db = Database::connection($config['db']);
$settingKey = "user_{$userId}_blocked_external_refs";

// ==================================
// 处理屏蔽/解除屏蔽的 POST 请求
// ==================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$settingKey]);
    $existing = json_decode($stmt->fetchColumn() ?: '[]', true);
    
    $domain = trim($_POST['domain'] ?? '');
    if ($domain !== '') {
        if ($_POST['action'] === 'block') {
            if (!in_array($domain, $existing)) {
                $existing[] = $domain;
            }
        } elseif ($_POST['action'] === 'unblock') {
            $existing = array_values(array_filter($existing, fn($d) => $d !== $domain));
        }
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $json = json_encode($existing, JSON_UNESCAPED_UNICODE);
        $stmt->execute([$settingKey, $json, $json]);
    }
    
    // 动作完成后刷新页面以防表单重复提交
    header("Location: /external.php?site={$siteId}&range={$range}");
    exit;
}

// ==================================
// 获取屏蔽列表并构建过滤闭包
// ==================================
$stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
$stmt->execute([$settingKey]);
$blockedDomains = json_decode($stmt->fetchColumn() ?: '[]', true);

$isBlocked = function($host) use ($blockedDomains) {
    foreach ($blockedDomains as $bd) {
        if ($host === $bd) return true;
        // 支持通配符匹配 (如 *.spam.com 匹配 sub.spam.com)
        if (str_starts_with($bd, '*.') && str_ends_with($host, substr($bd, 1))) return true;
    }
    return false;
};

// ==================================
// 获取数据、过滤及分页计算
// ==================================
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$data = $selectedSite ? $tracker->getExternalLinkData($siteId, $range) : null;
$allLinks = $data['links'] ?? [];

// 如果存在屏蔽规则，进行实时数据过滤
if (!empty($blockedDomains) && !empty($allLinks)) {
    $allLinks = array_values(array_filter($allLinks, fn($row) => !$isBlocked($row['host'])));
}

$totalLinks = count($allLinks);
$totalPages = max(1, (int) ceil($totalLinks / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$links = array_slice($allLinks, ($page - 1) * $perPage, $perPage);

render_head('外部链接 - 统计后台');
render_topbar($branding);
?>
<style>
    /* 屏蔽列表标签及按钮样式 */
    .blocked-tags-container { padding: 12px 16px; background: #fff1f0; border-radius: 6px; margin-bottom: 16px; border: 1px solid #ffccc7; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .blocked-tag { display: inline-flex; align-items: center; background: #fff; color: #cf1322; border: 1px solid #ffa39e; padding: 3px 8px; border-radius: 4px; font-size: 13px; font-weight: 500; }
    .blocked-tag-btn { background: none; border: none; color: #cf1322; cursor: pointer; padding: 0 0 0 6px; font-size: 16px; line-height: 1; }
    .blocked-tag-btn:hover { color: #a8071a; }
    
    .action-btn { background: none; border: none; cursor: pointer; color: #808695; font-size: 13px; text-decoration: underline; margin-right: 12px; transition: color 0.2s; padding: 0; }
    .action-btn:hover { color: #cf1322; }

    /* 防止超长 URL 撑破表格导致布局错乱 */
    .url-ellipsis {
        display: inline-block;
        max-width: 400px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        vertical-align: middle;
        color: #1e293b;
        font-weight: 500;
    }
</style>

<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'external', $range); ?>
    <main class="content">
        <?php if (!$selectedSite): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">来路分析 · 外部链接</h2>
                        <p class="muted" style="margin:2px 0 0;">剔除搜索引擎与自身域名的外部来源</p>
                    </div>
                    <?php render_range_filters($allowedRanges, $range, 'external', (int) $selectedSite['id']); ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title" style="margin-bottom:12px;">
                    <h3 style="margin:0;">外部链接列表</h3>
                    <span class="muted">按 PV 排序 · 每页 <?= $perPage ?> 条</span>
                </div>

                <?php if (!empty($blockedDomains)): ?>
                    <div class="blocked-tags-container">
                        <span style="font-size:13px; color:#cf1322; font-weight:600; margin-right:4px;">已屏蔽来源：</span>
                        <?php foreach ($blockedDomains as $bd): ?>
                            <span class="blocked-tag">
                                <?= htmlspecialchars($bd, ENT_QUOTES, 'UTF-8') ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="unblock">
                                    <input type="hidden" name="domain" value="<?= htmlspecialchars($bd, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="blocked-tag-btn" title="解除屏蔽">&times;</button>
                                </form>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <table>
                    <thead>
                        <tr>
                            <th style="width: 50%">来源域名</th>
                            <th>PV</th>
                            <th>IP</th>
                            <th style="width: 160px; text-align:right;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($links)): ?>
                        <tr><td colspan="4" class="muted" style="text-align:center;">暂无外部链接或数据已被全部屏蔽</td></tr>
                    <?php else: ?>
                        <?php foreach ($links as $row): ?>
                            <tr>
                                <td>
                                    <span class="url-ellipsis" title="<?= htmlspecialchars($row['host'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($row['host'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td><?= (int) $row['views'] ?></td>
                                <td><?= (int) $row['ips'] ?></td>
                                <td style="text-align:right;">
                                    <button type="button" class="action-btn" onclick="blockExact('<?= htmlspecialchars($row['host'], ENT_QUOTES, 'UTF-8') ?>')">屏蔽精准</button>
                                    <button type="button" class="action-btn" style="margin-right:0;" onclick="blockRoot('<?= htmlspecialchars($row['host'], ENT_QUOTES, 'UTF-8') ?>')">屏蔽根域</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                <?php render_pagination($page, $totalPages, '/external.php', ['site' => (int) $siteId, 'range' => $range]); ?>
            </section>

            <script>
                function submitBlockForm(domain) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `<input type="hidden" name="action" value="block"><input type="hidden" name="domain" value="${domain}">`;
                    document.body.appendChild(form);
                    form.submit();
                }

                function blockExact(domain) {
                    if (confirm('确定要将该确切的域名加入屏蔽名单吗？\n\n' + domain + '\n\n加入后，来自该域名的流量将不再显示在此列表中。')) {
                        submitBlockForm(domain);
                    }
                }

                function blockRoot(domain) {
                    // 智能推导根域名 (处理普通域名和 .com.cn 等复合后缀)
                    let parts = domain.split('.');
                    let defaultRoot = '*.' + domain;
                    
                    if (parts.length > 2) {
                        if (parts[parts.length - 1].length === 2 && parts[parts.length - 2].length <= 3) {
                            defaultRoot = '*.' + parts.slice(-3).join('.');
                        } else {
                            defaultRoot = '*.' + parts.slice(-2).join('.');
                        }
                    }

                    let userConfirmedRule = prompt('请确认要屏蔽的通配符规则：\n这将屏蔽该规则下的所有子域名。', defaultRoot);
                    if (userConfirmedRule && userConfirmedRule.trim() !== '') {
                        submitBlockForm(userConfirmedRule.trim());
                    }
                }
            </script>
        <?php endif; ?>
    </main>
</div>
<?php render_footer(); ?>
