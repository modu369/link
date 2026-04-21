<?php
// 1. 引入全局初始化文件，它已经处理了 session_start、数据库连接和登出逻辑
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

// 2. 权限锁：该页面为“系统设置”，仅限管理员访问
if (!$GLOBALS['is_admin']) {
    header('Location: /sites.php');
    exit;
}

// 3. 逻辑处理：直接使用 init.php 提供的 $db 实例
$getSetting = function (string $key) use ($db): ?array {
    $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    if (!$value) return null;
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

// 加载现有配置
$account = $getSetting('admin') ?? [];
$retention = $getSetting('retention') ?? [];
$brandingSetting = $getSetting('branding') ?? [];
$loginEntrySetting = $getSetting('login_entry') ?? [];
$ingestFilters = $getSetting('ingest_filters') ?? [];

// 初始值合并
$account = array_merge($config['app']['admin'] ?? [], $account);
$retention = array_merge($config['retention'] ?? [], $retention);
$ingestFilters = array_merge(['ip_filters' => '', 'keyword_filters' => '', 'asn_filters' => '', 'ua_filters' => ''], $ingestFilters);

$message = null;
$error = null;

// 处理提交动作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 更新管理员账号
    if ($action === 'update_admin') {
        $user = trim($_POST['user'] ?? '');
        $pass = trim($_POST['pass'] ?? '');
        $pass2 = trim($_POST['pass2'] ?? '');
        if ($user === '' || $pass === '') {
            $error = '账号和密码均不能为空';
        } elseif ($pass !== $pass2) {
            $error = '两次输入的密码不一致';
        } else {
            $account = ['user' => $user, 'pass_hash' => password_hash($pass, PASSWORD_BCRYPT)];
            $setSetting('admin', $account);
            $_SESSION['admin_user'] = $account['user'];
            $message = '账号信息已更新';
        }
    }

    // 更新品牌设置
    if ($action === 'update_branding') {
        $brandingNew = [
            'base_url' => trim($_POST['base_url'] ?? ''),
            'brand_title' => trim($_POST['brand_title'] ?? ''),
            'brand_subtitle' => trim($_POST['brand_subtitle'] ?? ''),
        ];
        $setSetting('branding', $brandingNew);
        $branding = array_merge($branding, $brandingNew); // 更新当前页面显示的 branding 变量
        $message = '品牌设置已更新';
    }

    // 其他原有逻辑（数据保留、入库过滤、隐藏入口）保持不变...
    if ($action === 'update_retention') {
        $retention = [
            'days' => max(0, (int)$_POST['days']),
            'pageviews_days' => max(0, (int)$_POST['pageviews_days']),
            'cleanup_hour' => min(23, max(0, (int)$_POST['cleanup_hour'])),
        ];
        $setSetting('retention', $retention);
        $message = '数据保留策略已更新';
    }

    if ($action === 'update_login_entry') {
        $entry = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['login_entry'] ?? ''));
        $setSetting('login_entry', ['entry' => $entry ?: 'admin']);
        $message = '隐蔽入口已更新';
    }

    if ($action === 'update_ingest_filters') {
        $ingestFilters = [
            'ip_filters' => trim($_POST['ip_filters'] ?? ''),
            'keyword_filters' => trim($_POST['keyword_filters'] ?? ''),
            'asn_filters' => trim($_POST['asn_filters'] ?? ''),
            'ua_filters' => trim($_POST['ua_filters'] ?? ''),
        ];
        $setSetting('ingest_filters', $ingestFilters);
        $message = '过滤规则已更新';
    }
}

// 4. 渲染页面
render_head('系统设置 - 统计后台');
render_topbar($branding);
?>
<div class="sites-layout">
    <section class="card">
        <div class="section-title">
            <h2>管理员信息</h2>
            <span class="pill">修改超级管理账号及密码</span>
        </div>
        <?php if ($message): ?><p style="color:green;">✅ <?= htmlspecialchars($message) ?></p><?php endif; ?>
        <?php if ($error): ?><p style="color:red;">⚠️ <?= htmlspecialchars($error) ?></p><?php endif; ?>
        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end;">
            <input type="hidden" name="action" value="update_admin">
            <div class="form-control" style="margin:0;">
                <label>账号</label>
                <input type="text" name="user" value="<?= htmlspecialchars($account['user'] ?? '') ?>" required>
            </div>
            <div class="form-control" style="margin:0;">
                <label>新密码</label>
                <input type="password" name="pass" placeholder="不修改请留空" required>
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
            <span class="pill">更新全局标题与跟踪脚本基址</span>
        </div>
        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end;">
            <input type="hidden" name="action" value="update_branding">
            <div class="form-control" style="margin:0;">
                <label>基址（含 http/https）</label>
                <input type="text" name="base_url" value="<?= htmlspecialchars($branding['base_url'] ?? '') ?>" required>
            </div>
            <div class="form-control" style="margin:0;">
                <label>大标题</label>
                <input type="text" name="brand_title" value="<?= htmlspecialchars($branding['brand_title'] ?? '') ?>" required>
            </div>
            <div class="form-control" style="margin:0;">
                <label>小标题</label>
                <input type="text" name="brand_subtitle" value="<?= htmlspecialchars($branding['brand_subtitle'] ?? '') ?>" required>
            </div>
            <div><button type="submit">保存品牌</button></div>
        </form>
    </section>
    <section class="card">
        <div class="section-title">
            <h2>安全：隐蔽入口设置</h2>
            <span class="pill">设置管理员专属登录路径</span>
        </div>
        <form method="post" style="display:flex;gap:12px;align-items:end;">
            <input type="hidden" name="action" value="update_login_entry">
            <div class="form-control" style="margin:0; flex:1; max-width: 400px;">
                <label>入口路径 (对应 /index.php?entry=...)</label>
                <input type="text" name="login_entry" value="<?= htmlspecialchars($loginEntrySetting['entry'] ?? 'admin') ?>" required>
            </div>
            <div><button type="submit">保存入口</button></div>
        </form>
    </section>
    <section class="card">
        <div class="section-title">
            <h2>数据保留与自动清理</h2>
            <span class="pill">设置数据自动过期时间</span>
        </div>
        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;align-items:end;">
            <input type="hidden" name="action" value="update_retention">
            <div class="form-control" style="margin:0;">
                <label>报表保留天数</label>
                <input type="number" name="days" value="<?= (int)$retention['days'] ?>" required>
            </div>
            <div class="form-control" style="margin:0;">
                <label>明细日志保留天数</label>
                <input type="number" name="pageviews_days" value="<?= (int)$retention['pageviews_days'] ?>" required>
            </div>
            <div class="form-control" style="margin:0;">
                <label>清理小时 (0-23)</label>
                <input type="number" name="cleanup_hour" value="<?= (int)$retention['cleanup_hour'] ?>" required>
            </div>
            <div><button type="submit">保存策略</button></div>
        </form>
    </section>
    <section class="card">
        <div class="section-title">
            <h2>全局过滤规则</h2>
            <span class="pill">符合规则的流量将不计入统计 (每行一条)</span>
        </div>
        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;align-items:start;">
            <input type="hidden" name="action" value="update_ingest_filters">
            
            <div class="form-control" style="margin:0;">
                <label>IP 过滤 (支持通配符/CIDR)</label>
                <textarea name="ip_filters" rows="4"><?= htmlspecialchars($ingestFilters['ip_filters'] ?? '') ?></textarea>
            </div>
            
            <div class="form-control" style="margin:0;">
                <label>ASN 过滤 (支持编号或名称)</label>
                <textarea name="asn_filters" rows="4"><?= htmlspecialchars($ingestFilters['asn_filters'] ?? '') ?></textarea>
            </div>
            
            <div class="form-control" style="margin:0;">
                <label>UA 特征过滤 (支持包含匹配)</label>
                <textarea name="ua_filters" rows="4"><?= htmlspecialchars($ingestFilters['ua_filters'] ?? '') ?></textarea>
            </div>
            
            <div class="form-control" style="margin:0;">
                <label>关键词/路径过滤 (包含匹配)</label>
                <textarea name="keyword_filters" rows="4"><?= htmlspecialchars($ingestFilters['keyword_filters'] ?? '') ?></textarea>
            </div>
            
            <div style="grid-column: 1 / -1; text-align: right;">
                <button type="submit">保存过滤规则</button>
            </div>
        </form>
    </section>
</div>
<?php render_footer(); ?>
