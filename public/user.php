<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$account = $tracker->getAdminAccount($config['app']['admin']);
$retention = $tracker->getRetentionSettings($config['retention']);
$branding = $tracker->getBrandingSettings($brandingFallback);
$loginEntry = $tracker->getLoginEntry();
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
            $account = $tracker->updateAdminAccount($user, $pass);
            $_SESSION['admin_user'] = $account['user'];
            $message = '账号信息已更新';
        }
    }

    if ($action === 'update_retention') {
        $days = (int) ($_POST['days'] ?? 0);
        $hour = (int) ($_POST['cleanup_hour'] ?? 3);
        $retention = $tracker->updateRetentionSettings($days, $hour);
        $message = '数据保留策略已更新';
    }

    if ($action === 'update_branding') {
        $base = trim($_POST['base_url'] ?? '');
        $title = trim($_POST['brand_title'] ?? '');
        $subtitle = trim($_POST['brand_subtitle'] ?? '');
        $branding = $tracker->updateBrandingSettings($base, $title, $subtitle);
        $message = '站点基址与标题已更新';
    }

    if ($action === 'update_login_entry') {
        $entry = trim($_POST['login_entry'] ?? '');
        $loginEntry = $tracker->updateLoginEntry($entry);
        $message = '隐蔽入口已更新';
    }

    if ($action === 'manual_cleanup') {
        $days = (int) ($_POST['cleanup_days'] ?? 0);
        if ($days > 0) {
            $tracker->manualCleanup($days);
            $message = "已清理 {$days} 天前的数据";
        } else {
            $error = '请输入需要清理的天数';
        }
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
                <label>自动清理执行小时（0-23）</label>
                <input type="number" name="cleanup_hour" min="0" max="23" value="<?= (int) ($retention['cleanup_hour'] ?? 3) ?>" required>
            </div>
            <div><button type="submit">保存策略</button></div>
        </form>
        <form method="post" style="margin-top:16px;display:flex;gap:12px;align-items:center;">
            <input type="hidden" name="action" value="manual_cleanup">
            <label style="font-weight:600;">手动清理</label>
            <input type="number" name="cleanup_days" min="1" placeholder="清理多少天前" style="padding:10px 12px;border-radius:8px;border:1px solid #e2e8f0;">
            <button type="submit" class="ghost">立即清理</button>
        </form>
        <p class="muted" style="margin-top:10px;">自动与手动清理会同步删除 pageviews、pageview_rollups、pageview_dimension_rollups、pageview_page_rollups、pageview_entry_rollups 与 site_ip_audience 中超期数据；pageviews 按批次删除以降低大表锁定时间。建议在业务低峰通过计划任务调用本页或 CLI 清理，避免高峰 IO。</p>
    </section>
</div>
<?php render_footer(); ?>
