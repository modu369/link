<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$trackingId = $selectedSite['tracking_id'] ?? '';
$baseUrl = rtrim($branding['base_url'] ?? $config['app']['base_url'] ?? 'http://localhost', '/');
$scriptCode = sprintf(
    '<script src="%s/js/tracker.js" data-site="%s"></script>',
    $baseUrl,
    $trackingId
);

render_head('获取代码 - 统计后台');
render_topbar($branding);
?>
<div class="data-layout">
    <?php render_sidebar($sites, $siteId, $selectedSite, 'code', $range); ?>
    <main class="content">
        <?php if (!$selectedSite): ?>
            <div class="card empty">请选择或创建站点后查看数据。</div>
        <?php else: ?>
            <section class="card">
                <div class="section-title">
                    <div>
                        <h2 style="margin:0;">获取统计代码</h2>
                        <p class="muted" style="margin:2px 0 0;">将以下代码插入到站点页面的 <code>&lt;head&gt;</code> 或 <code>&lt;body&gt;</code> 末尾。</p>
                    </div>
                </div>
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <code class="inline" id="trackingCode"><?= htmlspecialchars($scriptCode, ENT_QUOTES, 'UTF-8') ?></code>
                    <button class="ghost" type="button" id="copyCode">复制代码</button>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
<script>
    const btn = document.getElementById('copyCode');
    if (btn) {
        btn.addEventListener('click', async () => {
            const text = document.getElementById('trackingCode')?.innerText || '';
            try {
                await navigator.clipboard.writeText(text);
                btn.textContent = '已复制';
                setTimeout(() => (btn.textContent = '复制代码'), 1500);
            } catch (e) {
                btn.textContent = '复制失败';
                setTimeout(() => (btn.textContent = '复制代码'), 1500);
            }
        });
    }
</script>
<?php render_footer(); ?>
