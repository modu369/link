<?php
require __DIR__ . '/init.php';
require __DIR__ . '/layout.php';

$trackingId = $selectedSite['tracking_id'] ?? '';
$baseUrl = rtrim($branding['base_url'] ?? $config['app']['base_url'] ?? 'http://localhost', '/');
$scriptCode = sprintf(
    '<script>(function(w,d){var s=d.createElement("script");s.src="%s/js/?id=%s";s.async=true;(d.head||d.body).appendChild(s);}(window,document));</script>',
    $baseUrl,
    $trackingId
);
$encodeEval = static function (string $source): string {
    $words = array_values(array_unique(preg_split('/\W+/', $source, -1, PREG_SPLIT_NO_EMPTY)));
    usort($words, static function (string $a, string $b): int {
        return strlen($b) <=> strlen($a);
    });
    $indexMap = [];
    foreach ($words as $index => $word) {
        $indexMap[$word] = $index;
    }
    $packedSource = preg_replace_callback('/\b\w+\b/', static function (array $matches) use ($indexMap): string {
        $word = $matches[0];
        if (!array_key_exists($word, $indexMap)) {
            return $word;
        }
        return base_convert((string) $indexMap[$word], 10, 36);
    }, $source);
    $keywordList = implode('|', $words);
    $base = 36;
    $count = count($words);
    $payload = sprintf(
        ";eval(function(p,a,c,k,e,r){e=function(c){return c.toString(a)};if(!''.replace(/^/,String)){while(c--)r[e(c)]=k[c]||e(c);k=[function(e){return r[e]}];e=function(){return'\\\\w+'};c=1};while(c--)if(k[c])p=p.replace(new RegExp('\\\\b'+e(c)+'\\\\b','g'),k[c]);return p}('%s',%d,%d,'%s'.split('|'),0,{}));",
        addslashes($packedSource),
        $base,
        $count,
        addslashes($keywordList)
    );
    return '<script>' . $payload . '</script>';
};
$extractScriptBody = static function (string $script): string {
    if (preg_match('/<script>(.*)<\\/script>/s', $script, $matches)) {
        return $matches[1];
    }
    return $script;
};
$encodedScript = $encodeEval($extractScriptBody($scriptCode));

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
                        <p class="muted" style="margin:2px 0 0;">浏览器不支持或禁用 Cookie 时将无法记录独立访客等指标，此类流量将不计入报表。</p>
                    </div>
                </div>
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <code class="inline" id="trackingCode"><?= htmlspecialchars($scriptCode, ENT_QUOTES, 'UTF-8') ?></code>
                    <button class="ghost" type="button" id="copyCode">复制代码</button>
                    <div class="muted" style="margin-top:6px;">Eval 加密版（可选）</div>
                    <code class="inline" id="trackingCodeEval"><?= htmlspecialchars($encodedScript, ENT_QUOTES, 'UTF-8') ?></code>
                    <button class="ghost" type="button" id="copyCodeEval">复制加密版</button>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
<script>
    const btn = document.getElementById('copyCode');
    const btnEval = document.getElementById('copyCodeEval');
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
    if (btnEval) {
        btnEval.addEventListener('click', async () => {
            const text = document.getElementById('trackingCodeEval')?.innerText || '';
            try {
                await navigator.clipboard.writeText(text);
                btnEval.textContent = '已复制';
                setTimeout(() => (btnEval.textContent = '复制加密版'), 1500);
            } catch (e) {
                btnEval.textContent = '复制失败';
                setTimeout(() => (btnEval.textContent = '复制加密版'), 1500);
            }
        });
    }
</script>
<?php render_footer(); ?>
