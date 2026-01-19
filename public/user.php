<?php
session_start();

$config = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/layout.php';

if (($_GET['action'] ?? '') === 'logout') {
    $entry = $config['security']['login_entry'] ?? 'admin';
    session_destroy();
    $redirectEntry = $entry !== '' ? '?entry=' . urlencode($entry) : '';
    header('Location: /index.php' . $redirectEntry);
    exit;
}

if (!($_SESSION['admin_logged_in'] ?? false)) {
    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body><h1 style="text-align:center;font-family:Helvetica Neue, Helvetica, PingFang SC, Hiragino Sans GB, Microsoft YaHei, 微软雅黑, Arial, sans-serif;">403 Forbidden</h1></body></html>';
    exit;
}

$db = Database::connection($config['db']);

$getSetting = function (string $key) use ($db): ?array {
    $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    if (!$value) {
        return null;
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
};

$setSetting = function (string $key, array $value) use ($db): void {
    $stmt = $db->prepare(
        'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:key, :value, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    );
    $stmt->execute([
        ':key' => $key,
        ':value' => json_encode($value, JSON_UNESCAPED_UNICODE),
    ]);
};

$account = $getSetting('admin') ?? [];
$retention = $getSetting('retention') ?? [];
$branding = $getSetting('branding') ?? [];
$loginEntrySetting = $getSetting('login_entry') ?? [];
$ingestFilters = $getSetting('ingest_filters') ?? [];

$account = array_merge($config['app']['admin'] ?? [], $account);
$retention = array_merge($config['retention'] ?? [], $retention);
$brandingFallback = $config['branding'] ?? [
    'base_url' => $config['app']['base_url'] ?? 'http://localhost',
    'brand_title' => 'V6统计后台',
    'brand_subtitle' => '亿级数据索引优化',
];
$branding = array_merge($brandingFallback, $branding);
$branding['base_url'] = trim($branding['base_url'] ?? '') ?: $brandingFallback['base_url'];
$branding['brand_title'] = trim($branding['brand_title'] ?? '') ?: $brandingFallback['brand_title'];
$branding['brand_subtitle'] = trim($branding['brand_subtitle'] ?? '') ?: $brandingFallback['brand_subtitle'];

$loginEntry = trim((string) ($loginEntrySetting['entry'] ?? ''));
if ($loginEntry === '') {
    $loginEntry = $config['security']['login_entry'] ?? 'admin';
}

$ingestFilters = array_merge(['ip_filters' => '', 'keyword_filters' => '', 'asn_filters' => '', 'ua_filters' => ''], $ingestFilters);
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_admin') {
        $user = trim($_POST['user'] ?? '');
        $pass = trim($_POST['pass'] ?? '');
        $pass2 = trim($_POST['pass2'] ?? '');
        if ($user === '' || $pass === '') {
            $error = '账号和密码均不能为空';
        } elseif ($pass !== $pass2) {
            $error = '两次输入的密码不一致';
        } else {
            $account = [
                'user' => $user,
                'pass_hash' => password_hash($pass, PASSWORD_BCRYPT),
            ];
            $setSetting('admin', $account);
            $_SESSION['admin_user'] = $account['user'];
            $message = '账号信息已更新';
        }
    }

    if ($action === 'update_retention') {
        $days = (int) ($_POST['days'] ?? 0);
        $hour = (int) ($_POST['cleanup_hour'] ?? 3);
        $pageviewsDays = (int) ($_POST['pageviews_days'] ?? 0);
        $retention = [
            'days' => max(0, $days),
            'pageviews_days' => max(0, $pageviewsDays),
            'cleanup_hour' => min(23, max(0, $hour)),
        ];
        $setSetting('retention', $retention);
        $message = '数据保留策略已更新';
    }

    if ($action === 'update_branding') {
        $base = trim($_POST['base_url'] ?? '');
        $title = trim($_POST['brand_title'] ?? '');
        $subtitle = trim($_POST['brand_subtitle'] ?? '');
        $branding = [
            'base_url' => trim($base) ?: $brandingFallback['base_url'],
            'brand_title' => trim($title) ?: $brandingFallback['brand_title'],
            'brand_subtitle' => trim($subtitle) ?: $brandingFallback['brand_subtitle'],
        ];
        $setSetting('branding', $branding);
        $message = '站点基址与标题已更新';
    }

    if ($action === 'update_login_entry') {
        $entry = trim($_POST['login_entry'] ?? '');
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($entry));
        if ($sanitized === '') {
            $sanitized = $config['security']['login_entry'] ?? 'admin';
        }
        $setSetting('login_entry', ['entry' => $sanitized]);
        $loginEntry = $sanitized;
        $message = '隐蔽入口已更新';
    }

    if ($action === 'manual_cleanup') {
        $days = (int) ($_POST['cleanup_days'] ?? 0);
        $pageviewsDays = (int) ($_POST['cleanup_pageviews_days'] ?? 0);
        if ($days > 0) {
            require __DIR__ . '/../src/RedisClient.php';
            require __DIR__ . '/../src/Tracker.php';
            $redis = RedisClient::connection($config['redis']);
            $tracker = new Tracker($db, $redis, $config);
            $tracker->manualCleanup($days, $pageviewsDays > 0 ? $pageviewsDays : null);
            $message = "已清理 {$days} 天前的数据";
        } else {
            $error = '请输入需要清理的天数';
        }
    }

    if ($action === 'update_ingest_filters') {
        $ipFilters = trim($_POST['ip_filters'] ?? '');
        $keywordFilters = trim($_POST['keyword_filters'] ?? '');
        $asnFilters = trim($_POST['asn_filters'] ?? '');
        $uaFilters = trim($_POST['ua_filters'] ?? '');
        $ingestFilters = [
            'ip_filters' => $ipFilters,
            'keyword_filters' => $keywordFilters,
            'asn_filters' => $asnFilters,
            'ua_filters' => $uaFilters,
        ];
        $setSetting('ingest_filters', $ingestFilters);
        $message = '过滤规则已更新';
    }
}

render_head('用户中心 - 统计后台');
render_topbar($branding);
?>
<div class="sites-layout">
    <section class="card">
        <div class="section-title">
            <h2>个人信息</h2>
            <span class="pill">修改账号及密码</span>
        </div>
        <?php if ($message): ?><p style="color:#0f172a; margin-top:0;">✅ <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($error): ?><p style="color:#ef4444; margin-top:0;">⚠️ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end;">
            <input type="hidden" name="action" value="update_admin">
            <div class="form-control" style="margin:0;">
                <label>账号</label>
                <input type="text" name="user" value="<?= htmlspecialchars($account['user'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-control" style="margin:0;">
                <label>新密码</label>
                <input type="password" name="pass" placeholder="新的登录密码" required>
            </div>
            <div class="form-control" style="margin:0;">
                <label>确认密码</label>
                <input type="password" name="pass2" placeholder="再次输入" required>
            </div>
            <div><button type="submit">保存账号</button></div>
        </form>
    </section>

    <section class="card">
        <div class="section-title">
            <h2>品牌与基址设置</h2>
            <span class="pill">更新顶部标题、描述与跟踪脚本基址</span>
        </div>
        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end;">
            <input type="hidden" name="action" value="update_branding">
            <div class="form-control" style="margin:0;">
                <label>基址（站点域名）</label>
                <input type="text" name="base_url" value="<?= htmlspecialchars($branding['base_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="https://stats.example.com" required>
            </div>
            <div class="form-control" style="margin:0;">
                <label>大标题</label>
                <input type="text" name="brand_title" value="<?= htmlspecialchars($branding['brand_title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="V6统计后台" required>
            </div>
            <div class="form-control" style="margin:0;">
                <label>小标题</label>
                <input type="text" name="brand_subtitle" value="<?= htmlspecialchars($branding['brand_subtitle'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="亿级数据索引优化" required>
            </div>
            <div><button type="submit">保存品牌</button></div>
        </form>
    </section>

    <section class="card">
        <div class="section-title">
            <h2>隐蔽登录入口</h2>
            <span class="pill">设置后仅通过 ?entry=此标识 才能打开登录页</span>
        </div>
        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;align-items:end;">
            <input type="hidden" name="action" value="update_login_entry">
            <div class="form-control" style="margin:0;">
                <label>入口标识</label>
                <input type="text" name="login_entry" value="<?= htmlspecialchars($loginEntry, ENT_QUOTES, 'UTF-8') ?>" placeholder="例如 admin2024 或 secret-door" required>
                <p class="muted" style="margin:6px 0 0;">访问 <code>/index.php?entry=<?= htmlspecialchars($loginEntry, ENT_QUOTES, 'UTF-8') ?></code> 才会出现登录表单</p>
            </div>
            <div><button type="submit">保存入口</button></div>
        </form>
    </section>

    <section class="card">
        <div class="section-title">
            <h2>数据保留与清理</h2>
            <span class="pill">自动清理与手动清理（按 IP/PV 数据量优化）</span>
        </div>
        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;align-items:end;">
            <input type="hidden" name="action" value="update_retention">
            <div class="form-control" style="margin:0;">
                <label>保留天数</label>
                <input type="number" name="days" min="0" value="<?= (int) ($retention['days'] ?? 0) ?>" required>
            </div>
            <div class="form-control" style="margin:0;">
                <label>Pageviews 保留天数</label>
                <input type="number" name="pageviews_days" min="0" value="<?= (int) ($retention['pageviews_days'] ?? 0) ?>" required>
            </div>
            <div class="form-control" style="margin:0;">
                <label>自动清理执行小时（0-23）</label>
                <input type="number" name="cleanup_hour" min="0" max="23" value="<?= (int) ($retention['cleanup_hour'] ?? 3) ?>" required>
            </div>
            <div><button type="submit">保存策略</button></div>
        </form>
        <form method="post" style="margin-top:16px;display:flex;gap:12px;align-items:center;">
            <input type="hidden" name="action" value="manual_cleanup">
            <label style="font-weight:600;">手动清理</label>
            <input type="number" name="cleanup_days" min="1" placeholder="清理多少天前" style="padding:10px 12px;border-radius:8px;border:1px solid #e2e8f0;">
            <input type="number" name="cleanup_pageviews_days" min="0" placeholder="Pageviews 清理天数（可选）" style="padding:10px 12px;border-radius:8px;border:1px solid #e2e8f0;">
            <button type="submit" class="ghost">立即清理</button>
        </form>
        <p class="muted" style="margin-top:10px;">自动与手动清理会同步删除 pageview_rollups、pageview_dimension_rollups、pageview_page_rollups、pageview_entry_rollups、pageview_bot_logs 与 site_ip_audience 中超期数据；pageviews 可设置独立保留天数，并按批次删除以降低大表锁定时间。建议在业务低峰通过计划任务调用本页或 CLI 清理，避免高峰 IO。</p>
    </section>

    <section class="card">
        <div class="section-title">
            <h2>IP / UA / 关键词过滤</h2>
            <span class="pill">命中规则的数据不入库</span>
        </div>
        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;align-items:start;">
            <input type="hidden" name="action" value="update_ingest_filters">
            <div class="form-control" style="margin:0;">
                <label>IP 段 / IP 过滤</label>
                <textarea name="ip_filters" rows="4" placeholder="支持 CIDR(10.0.0.0/24)、范围(10.0.0.1-10.0.0.10)、前缀(10.0.)，多条用换行或逗号分隔"><?= htmlspecialchars($ingestFilters['ip_filters'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div class="form-control" style="margin:0;">
                <label>关键词过滤</label>
                <textarea name="keyword_filters" rows="4" placeholder="匹配关键词、路径或引荐来源中的内容，多条用换行或逗号分隔"><?= htmlspecialchars($ingestFilters['keyword_filters'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div class="form-control" style="margin:0;">
                <label>ASN 过滤</label>
                <textarea name="asn_filters" rows="4" placeholder="支持 ASN 号（AS12345 或 12345）或组织名称关键词，多条用换行或逗号分隔"><?= htmlspecialchars($ingestFilters['asn_filters'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div class="form-control" style="margin:0;">
                <label>UA 特征过滤</label>
                <textarea name="ua_filters" rows="4" placeholder="匹配 UA 中的关键词，例如 headless、python-requests、selenium、scrapy、curl、okhttp 等，多条用换行或逗号分隔"><?= htmlspecialchars($ingestFilters['ua_filters'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div style="align-self:end;"><button type="submit">保存过滤</button></div>
        </form>
        <p class="muted" style="margin-top:10px;">命中 IP 段、ASN、UA 特征或关键词规则的数据将不进入 pageviews 与 rollup 汇总，可用于拦截伪装爬虫与刷量流量。</p>
    </section>
</div>
<?php render_footer(); ?>
