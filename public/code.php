<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';
// === 新增：强制水平越权拦截 ===
if ($siteId > 0 && !$selectedSite) {
    // 恶意修改 site_id 参数，或者站点已被删除
    die('您无权访问该站点的数据。');
}
// ==================================
$trackingId = $selectedSite['tracking_id'] ?? '';
$baseUrl = rtrim($branding['base_url'] ?? $config['app']['base_url'] ?? 'http://localhost', '/');

// 生成新版 async defer 标准直链代码，对搜索引擎蜘蛛100%可见
$scriptCode = sprintf(
    '<script src="%s/js/?id=%s" async defer></script>',
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
                        <div style="display:flex; align-items:center; gap: 8px;">
                            <h2 style="margin:0;">获取统计代码</h2>
                            <span style="background:#e8f5e9;color:#166534;border:1px solid #bbf7d0;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600;">推荐使用</span>
                        </div>
                        <p class="muted" style="margin:8px 0 0;">将以下代码插入到站点页面的 <code>&lt;head&gt;</code> 或 <code>&lt;body&gt;</code> 末尾。</p>
                        <p class="muted" style="margin:4px 0 0;">此格式为标准异步加载，<b>完全不影响网站加载速度。</b></p>
                    </div>
                </div>
                <div style="display:flex; flex-direction:column; gap:12px; margin-top:16px;">
                    <code class="inline" id="trackingCode" style="font-size: 14px; padding: 12px;"><?= htmlspecialchars($scriptCode, ENT_QUOTES, 'UTF-8') ?></code>
                    <div>
                        <button class="ghost" type="button" id="copyCode">复制代码</button>
                    </div>
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
