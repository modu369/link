<?php
declare(strict_types=1);

const SETTINGS_PATH = __DIR__ . '/data/settings.json';
const SCHEDULE_PATH = __DIR__ . '/data/schedule.json';

function defaultReplacementsText(): string
{
    return trim(<<<'TEXT'
第1季=\n第2季=第二季
第3季=第三季
第4季=第四季
第5季=第五季
第6季=第六季
第7季=第七季
第8季=第八季
第9季=第九季
第10季=第十季
第11季=第十一季
第12季=第十二季
第13季=第十三季
第14季=第十四季
第15季=第十五季
第16季=第十六季
第17季=第十七季
第18季=第十八季
第19季=第十九季
第20季=第二十季
第21季=第二十一季
第22季=第二十二季
第23季=第二十三季
第24季=第二十四季
第25季=第二十五季
第26季=第二十六季
第27季=第二十七季
第28季=第二十八季
 第1季=\n 第2季=第二季
 第3季=第三季
 第4季=第四季
 第5季=第五季
 第6季=第六季
 第7季=第七季
 第8季=第八季
 第9季=第九季
 第10季=第十季
 第11季=第十一季
 第12季=第十二季
 第13季=第十三季
 第14季=第十四季
 第15季=第十五季
 第16季=第十六季
 第17季=第十七季
 第18季=第十八季
 第19季=第十九季
 第20季=第二十季
 第21季=第二十一季
 第22季=第二十二季
 第23季=第二十三季
 第24季=第二十四季
 第25季=第二十五季
 第26季=第二十六季
 第27季=第二十七季
 第28季=第二十八季
 第一部=\n 第二部=第二季
 第三部=第三季
 第四部=第四季
 第五部=第五季
 第六部=第六季
 第七部=第七季
 第八部=第八季
 第九部=第九季
 第十部=第十季
 第十二部=第十二季
 第十三部=第十三季
 第十四部=第十四季
 第十五部=第十五季
 第十六部=第十六季
 第十七部=第十七季
 第十八部=第十八季
 第十九部=第十九季
 第二十部=第二十季
 第二十一部=第二十一季
 第二十二部=第二十二季
 第二十三部=第二十三季
 第二十四部=第二十四季
 第二十五部=第二十五季
 第二十六部=第二十六季
 第二十七部=第二十七季
 第二十八部=第二十八季
第一部=\n第二部=第二季
第三部=第三季
第四部=第四季
第五部=第五季
第六部=第六季
第七部=第七季
第八部=第八季
第九部=第九季
第十部=第十季
第十二部=第十二季
第十三部=第十三季
第十四部=第十四季
第十五部=第十五季
第十六部=第十六季
第十七部=第十七季
第十八部=第十八季
第十九部=第十九季
第二十部=第二十季
第二十一部=第二十一季
第二十二部=第二十二季
第二十三部=第二十三季
第二十四部=第二十四季
第二十五部=第二十五季
第二十六部=第二十六季
第二十七部=第二十七季
第二十八部=第二十八季
 第1部=\n 第2部=第二季
 第3部=第三季
 第4部=第四季
 第5部=第五季
 第6部=第六季
 第7部=第七季
 第8部=第八季
 第9部=第九季
 第10部=第十季
 第11部=第十一季
 第12部=第十二季
 第13部=第十三季
 第14部=第十四季
 第15部=第十五季
 第16部=第十六季
 第17部=第十七季
 第18部=第十八季
 第19部=第十九季
 第20部=第二十季
 第21部=第二十一季
 第22部=第二十二季
 第23部=第二十三季
 第24部=第二十四季
 第25部=第二十五季
 第26部=第二十六季
 第27部=第二十七季
 第28部=第二十八季
第1部=\n第2部=第二季
第3部=第三季
第4部=第四季
第5部=第五季
第6部=第六季
第7部=第七季
第8部=第八季
第9部=第九季
第10部=第十季
第11部=第十一季
第12部=第十二季
第13部=第十三季
第14部=第十四季
第15部=第十五季
第16部=第十六季
第17部=第十七季
第18部=第十八季
第19部=第十九季
第20部=第二十季
第21部=第二十一季
第22部=第二十二季
第23部=第二十三季
第24部=第二十四季
第25部=第二十五季
第26部=第二十六季
第27部=第二十七季
第28部=第二十八季
第一季=\n   第一季=\n   第二季=第二季
   第三季=第三季
   第四季=第四季
   第五季=第五季
   第六季=第六季
   第七季=第七季
   第八季=第八季
   第九季=第九季
   第十季=第十季
   第十一季=第十一季
   第十二季=第十二季
   第十三季=第十三季
   第十四季=第十四季
   第十五季=第十五季
   第十六季=第十六季
   第十七季=第十七季
   第十八季=第十八季
   第十九季=第十九季
   第二十季=第二十季
   第二十一季=第二十一季
   第二十二季=第二十二季
   第二十三季=第二十三季
   第二十四季=第二十四季
   第二十五季=第二十五季
   第二十六季=第二十六季
   第二十七季=第二十七季
   第二十八季=第二十八季
 第一季=\n 第二季=第二季
 第三季=第三季
 第四季=第四季
 第五季=第五季
 第六季=第六季
 第七季=第七季
 第八季=第八季
 第九季=第九季
 第十季=第十季
 第十一季=第十一季
 第十二季=第十二季
 第十三季=第十三季
 第十四季=第十四季
 第十五季=第十五季
 第十六季=第十六季
 第十七季=第十七季
 第十八季=第十八季
 第十九季=第十九季
 第二十季=第二十季
 第二十一季=第二十一季
 第二十二季=第二十二季
 第二十三季=第二十三季
 第二十四季=第二十四季
 第二十五季=第二十五季
 第二十六季=第二十六季
 第二十七季=第二十七季
 第二十八季=第二十八季
 普通话版=普通话版
(普通话版)=普通话版
 (普通话版)=普通话版
（普通话版）=普通话版
[普通话版]=普通话版
 [普通话版]=普通话版
(普通话版）=普通话版
（普通话）=普通话版
[日语版]=日语版
 [日语版]=日语版
（中配）=中配版
 DVD版=DVD版
 dvd版=DVD版
dvd版=DVD版
 国语版=国语版
 国语=国语版
（国语）=国语版
（日语）=日语版
（国语版）=国语版
（日语版）=日语版
 日语版=日语版
 日语=日语版
 完=完
 动态漫画=动态漫画
TV版= TV版
 英文版=英文版
:=：
 中配版=中配版
 日配版=日语版
日配版=日语版
（日配版）=日语版
,=，
最游记RELOAD ZEROIN=最游记 RELOAD ZEROIN
最游记RELOADZEROIN=最游记 RELOAD ZEROIN
画江湖之不良人5=画江湖之不良人第五季
鬼灭之刃游郭篇=鬼灭之刃 游郭篇
平凡职业造就世界最强2=平凡职业造就世界最强第二季
魔法科高校的劣等生追忆篇=魔法科高校的劣等生 追忆篇
RWBY冰雪帝国=RWBY 冰雪帝国
斗破苍穹缘起=斗破苍穹 缘起
传颂之物二人的白皇=传颂之物 二人的白皇
传颂之物虚伪的假面=传颂之物 虚伪的假面
不死者之王第=不死者之王 OVERLORD第
零之使魔FINAL=零之使魔4 FINAL
 剧场版=剧场版
 特别篇=特别篇
 OVA=OVA
 OAD=OAD
Part 1=Part.1
Part 2=Part.2
Part 3=Part.3
Part 4=Part.4
Part 5=Part.5
Part 6=Part.6
Part 7=Part.7
Part 8=Part.8
Part 9=Part.9
PART1=Part.1
PART2=Part.2
PART3=Part.3
PART4=Part.4
PART5=Part.5
PART6=Part.6
PART7=Part.7
PART8=Part.8
PART9=Part.9
part1=Part.1
part2=Part.2
part3=Part.3
part4=Part.4
part5=Part.5
part6=Part.6
part7=Part.7
part8=Part.8
part9=Part.9
Part1=Part.1
Part2=Part.2
Part3=Part.3
Part4=Part.4
Part5=Part.5
Part6=Part.6
Part7=Part.7
Part8=Part.8
Part9=Part.9
 PartIII=Part.3
 Part.1=Part.1
 Part.2=Part.2
 Part.3=Part.3
 Part.4=Part.4
 Part.5=Part.5
 Part.6=Part.6
 Part.7=Part.7
 Part.8=Part.8
 Part.9=Part.9
 年番=年番
 剧场=剧场版
 剧场版=剧场版
 大电影=大电影
 电影版=电影版
(第一季)=第一季
(第二季)=第二季
(第三季)=第三季
(第四季)=第四季
(第五季)=第五季
(第六季)=第六季
斗罗大陆2：绝世唐门2023=斗罗大陆Ⅱ绝世唐门
斗罗大陆2：绝世唐门=斗罗大陆Ⅱ绝世唐门
 劇場版=剧场版
劇場版=剧场版
（动画版）=\n动画版=\n动画=
TEXT);
}

function loadSettings(): array
{
    if (!file_exists(SETTINGS_PATH)) {
        $defaults = [
            'dbs' => [],
            'cleanup_keywords' => ['全', '完'],
            'replacements_text' => defaultReplacementsText(),
        ];
        file_put_contents(SETTINGS_PATH, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $defaults;
    }

    $raw = file_get_contents(SETTINGS_PATH);
    $settings = json_decode($raw ?: '[]', true);
    if (!is_array($settings)) {
        $settings = [];
    }

    $settings['dbs'] = $settings['dbs'] ?? [];
    $settings['cleanup_keywords'] = $settings['cleanup_keywords'] ?? ['全', '完'];
    $settings['replacements_text'] = $settings['replacements_text'] ?? defaultReplacementsText();

    return $settings;
}

function saveSettings(array $settings): void
{
    file_put_contents(SETTINGS_PATH, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function parseReplacements(string $text): array
{
    $lines = preg_split('/\r?\n/', $text);
    $replacements = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = explode('=', $line, 2);
        $from = $parts[0] ?? '';
        $to = $parts[1] ?? '';
        if ($to === '\\n') {
            $to = '';
        }
        if ($from === '') {
            continue;
        }
        $replacements[] = [$from, $to];
    }

    return $replacements;
}

function applyReplacements(string $name, array $replacements): string
{
    $result = $name;
    foreach ($replacements as [$from, $to]) {
        $result = str_replace($from, $to, $result);
    }

    return trim($result);
}

function scrapeSchedule(array $replacements): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0\r\n",
            'timeout' => 15,
        ],
    ]);

    $html = file_get_contents('https://www.comicat.org/', false, $context);
    if ($html === false) {
        throw new RuntimeException('无法获取页面内容。');
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $rows = $xpath->query("//table[contains(@class,'list_style')]/tbody/tr");

    $weekdayMap = [
        '星期一' => '一',
        '星期二' => '二',
        '星期三' => '三',
        '星期四' => '四',
        '星期五' => '五',
        '星期六' => '六',
        '星期日' => '日',
        '星期天' => '日',
    ];

    $results = [];
    foreach ($rows as $row) {
        $cells = $row->getElementsByTagName('td');
        if ($cells->length < 2) {
            continue;
        }
        $weekdayLabel = trim(preg_replace('/\s+/', '', $cells->item(0)->textContent));
        if (!isset($weekdayMap[$weekdayLabel])) {
            continue;
        }
        $weekday = $weekdayMap[$weekdayLabel];
        $links = $cells->item(1)->getElementsByTagName('a');
        foreach ($links as $link) {
            $name = trim($link->textContent);
            if ($name === '' || str_contains($name, '新番')) {
                continue;
            }
            $normalized = applyReplacements($name, $replacements);
            $results[] = [
                'weekday' => $weekday,
                'name' => $normalized,
                'original_name' => $name,
            ];
        }
    }

    return $results;
}

function saveSchedule(array $schedule): void
{
    $payload = [
        'updated_at' => date('c'),
        'items' => $schedule,
    ];
    file_put_contents(SCHEDULE_PATH, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function loadSchedule(): array
{
    if (!file_exists(SCHEDULE_PATH)) {
        return ['updated_at' => null, 'items' => []];
    }
    $raw = file_get_contents(SCHEDULE_PATH);
    $decoded = json_decode($raw ?: '[]', true);
    if (!is_array($decoded)) {
        return ['updated_at' => null, 'items' => []];
    }
    $decoded['items'] = $decoded['items'] ?? [];

    return $decoded;
}

function buildDsn(array $db): string
{
    $host = $db['host'] ?? 'localhost';
    $port = $db['port'] ?? '3306';
    $name = $db['name'] ?? '';

    return "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
}

function connectDb(array $db): PDO
{
    $dsn = buildDsn($db);
    $user = $db['user'] ?? '';
    $pass = $db['pass'] ?? '';

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function updateWeekday(array $dbs, array $schedule): array
{
    $summary = [
        'success' => [],
        'failed' => [],
        'errors' => [],
    ];

    foreach ($dbs as $index => $db) {
        $label = $db['label'] ?? "数据库 #" . ($index + 1);
        try {
            $pdo = connectDb($db);
            foreach ($schedule as $item) {
                $name = $item['name'];
                $weekday = $item['weekday'];
                $stmt = $pdo->prepare('SELECT vod_id FROM mac_vod WHERE vod_name = ? LIMIT 1');
                $stmt->execute([$name]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $update = $pdo->prepare('UPDATE mac_vod SET vod_weekday = ? WHERE vod_id = ?');
                    $update->execute([$weekday, $row['vod_id']]);
                    $summary['success'][$name] = true;
                } else {
                    $summary['failed'][$name] = true;
                }
            }
        } catch (Throwable $e) {
            $summary['errors'][] = $label . '：' . $e->getMessage();
        }
    }

    return $summary;
}

function cleanupWeekday(array $dbs, array $keywords): array
{
    $summary = [
        'affected' => [],
        'errors' => [],
    ];

    foreach ($dbs as $index => $db) {
        $label = $db['label'] ?? "数据库 #" . ($index + 1);
        try {
            $pdo = connectDb($db);
            if ($keywords === []) {
                $summary['affected'][$label] = 0;
                continue;
            }

            $conditions = [];
            $params = [];
            foreach ($keywords as $keyword) {
                $conditions[] = 'vod_remarks LIKE ?';
                $params[] = '%' . $keyword . '%';
            }
            $where = implode(' OR ', $conditions);
            $sql = "UPDATE mac_vod SET vod_weekday = '' WHERE vod_weekday <> '' AND ({$where})";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $summary['affected'][$label] = $stmt->rowCount();
        } catch (Throwable $e) {
            $summary['errors'][] = $label . '：' . $e->getMessage();
        }
    }

    return $summary;
}

$settings = loadSettings();
$message = null;
$updateSummary = null;
$cleanupSummary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_settings') {
        $settings['cleanup_keywords'] = array_values(array_filter(array_map('trim', explode(',', $_POST['cleanup_keywords'] ?? ''))));
        $settings['replacements_text'] = $_POST['replacements_text'] ?? '';

        $dbs = [];
        $labels = $_POST['db_label'] ?? [];
        $hosts = $_POST['db_host'] ?? [];
        $ports = $_POST['db_port'] ?? [];
        $names = $_POST['db_name'] ?? [];
        $users = $_POST['db_user'] ?? [];
        $passes = $_POST['db_pass'] ?? [];
        $count = max(count($labels), count($hosts), count($names));
        for ($i = 0; $i < $count; $i++) {
            if (trim($names[$i] ?? '') === '') {
                continue;
            }
            $dbs[] = [
                'label' => trim($labels[$i] ?? ''),
                'host' => trim($hosts[$i] ?? 'localhost'),
                'port' => trim($ports[$i] ?? '3306'),
                'name' => trim($names[$i] ?? ''),
                'user' => trim($users[$i] ?? ''),
                'pass' => $passes[$i] ?? '',
            ];
        }
        $settings['dbs'] = $dbs;
        saveSettings($settings);
        $message = '设置已保存。';
    }

    if ($action === 'fetch_schedule') {
        try {
            $replacements = parseReplacements($settings['replacements_text']);
            $schedule = scrapeSchedule($replacements);
            saveSchedule($schedule);
            $message = '更番表已更新。';
        } catch (Throwable $e) {
            $message = '抓取失败：' . $e->getMessage();
        }
    }

    if ($action === 'update_weekday') {
        $scheduleData = loadSchedule();
        $scheduleItems = $scheduleData['items'] ?? [];
        $updateSummary = updateWeekday($settings['dbs'], $scheduleItems);
    }

    if ($action === 'cleanup_weekday') {
        $cleanupSummary = cleanupWeekday($settings['dbs'], $settings['cleanup_keywords']);
    }

    if ($action === 'add_replacement') {
        $from = trim($_POST['replacement_from'] ?? '');
        $to = trim($_POST['replacement_to'] ?? '');
        if ($from !== '') {
            $settings['replacements_text'] = trim($settings['replacements_text'] . "\n{$from}={$to}");
            saveSettings($settings);
            $message = '已新增同名替换。';
        }
    }

    $settings = loadSettings();
}

$scheduleData = loadSchedule();
$replacementsCount = count(parseReplacements($settings['replacements_text']));
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>更番表同步工具</title>
    <style>
        body { font-family: "Noto Sans SC", "Microsoft YaHei", sans-serif; background: #f5f7fb; margin: 0; color: #1f2937; }
        header { background: #111827; color: #fff; padding: 20px 30px; }
        main { max-width: 1100px; margin: 0 auto; padding: 20px 30px 60px; }
        .card { background: #fff; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08); }
        h2 { margin-top: 0; font-size: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; }
        input[type="text"], input[type="password"], textarea { width: 100%; border-radius: 8px; border: 1px solid #cbd5f5; padding: 10px 12px; box-sizing: border-box; }
        textarea { min-height: 160px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
        .grid { display: grid; gap: 16px; }
        .grid-2 { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; }
        .btn { background: #2563eb; color: #fff; border: none; padding: 10px 16px; border-radius: 8px; cursor: pointer; }
        .btn-secondary { background: #64748b; }
        .btn + .btn { margin-left: 8px; }
        .tag { display: inline-block; padding: 4px 10px; border-radius: 999px; background: #e0f2fe; color: #0369a1; font-size: 12px; margin-right: 6px; }
        .message { background: #ecfeff; border: 1px solid #a5f3fc; color: #0e7490; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
        .warning { background: #fef3c7; border-color: #fde68a; color: #92400e; }
        .error { background: #fee2e2; border-color: #fecaca; color: #991b1b; }
        .muted { color: #64748b; font-size: 13px; }
    </style>
</head>
<body>
<header>
    <h1>更番表同步工具</h1>
    <p class="muted">抓取 https://www.comicat.org/ 的更番表并同步到苹果CMS。</p>
</header>
<main>
    <?php if ($message): ?>
        <div class="message"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>更番表抓取</h2>
        <p>当前更新：<?php echo $scheduleData['updated_at'] ? htmlspecialchars($scheduleData['updated_at'], ENT_QUOTES, 'UTF-8') : '尚未更新'; ?></p>
        <form method="post">
            <input type="hidden" name="action" value="fetch_schedule">
            <button class="btn" type="submit">抓取并保存更番表</button>
        </form>
        <?php if (!empty($scheduleData['items'])): ?>
            <p class="muted">已抓取 <?php echo count($scheduleData['items']); ?> 条番剧信息。</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>同步 mac_vod.vod_weekday</h2>
        <form method="post">
            <input type="hidden" name="action" value="update_weekday">
            <button class="btn" type="submit">更新更番表</button>
        </form>
        <?php if ($updateSummary): ?>
            <?php if ($updateSummary['errors']): ?>
                <div class="message error">
                    <?php foreach ($updateSummary['errors'] as $error): ?>
                        <div><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="grid grid-2">
                <div>
                    <h3>更新成功</h3>
                    <?php if ($updateSummary['success']): ?>
                        <?php foreach (array_keys($updateSummary['success']) as $name): ?>
                            <div class="tag"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="muted">暂无成功记录。</p>
                    <?php endif; ?>
                </div>
                <div>
                    <h3>更新失败</h3>
                    <?php if ($updateSummary['failed']): ?>
                        <?php foreach (array_keys($updateSummary['failed']) as $name): ?>
                            <div class="tag"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="muted">暂无失败记录。</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>快速新增同名替换</h2>
        <p class="muted">更新失败后，可在这里添加同名替换，然后再点击“更新更番表”。</p>
        <form method="post" class="grid grid-2">
            <input type="hidden" name="action" value="add_replacement">
            <div>
                <label for="replacement_from">原名称</label>
                <input type="text" id="replacement_from" name="replacement_from" placeholder="抓取到的名称">
            </div>
            <div>
                <label for="replacement_to">替换为</label>
                <input type="text" id="replacement_to" name="replacement_to" placeholder="数据库中的名称">
            </div>
            <div>
                <button class="btn" type="submit">新增替换</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>去除完结条目的 vod_weekday</h2>
        <form method="post">
            <input type="hidden" name="action" value="cleanup_weekday">
            <button class="btn btn-secondary" type="submit">执行清理</button>
        </form>
        <?php if ($cleanupSummary): ?>
            <?php if ($cleanupSummary['errors']): ?>
                <div class="message error">
                    <?php foreach ($cleanupSummary['errors'] as $error): ?>
                        <div><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php foreach ($cleanupSummary['affected'] as $label => $count): ?>
                <p><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>：已清理 <?php echo $count; ?> 条记录。</p>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>后台设置</h2>
        <form method="post">
            <input type="hidden" name="action" value="save_settings">
            <h3>数据库配置</h3>
            <table>
                <thead>
                    <tr>
                        <th>名称</th>
                        <th>Host</th>
                        <th>Port</th>
                        <th>数据库</th>
                        <th>用户名</th>
                        <th>密码</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $dbs = $settings['dbs']; ?>
                    <?php $rows = max(1, count($dbs) + 1); ?>
                    <?php for ($i = 0; $i < $rows; $i++): ?>
                        <?php $db = $dbs[$i] ?? []; ?>
                        <tr>
                            <td><input type="text" name="db_label[]" value="<?php echo htmlspecialchars($db['label'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                            <td><input type="text" name="db_host[]" value="<?php echo htmlspecialchars($db['host'] ?? 'localhost', ENT_QUOTES, 'UTF-8'); ?>"></td>
                            <td><input type="text" name="db_port[]" value="<?php echo htmlspecialchars($db['port'] ?? '3306', ENT_QUOTES, 'UTF-8'); ?>"></td>
                            <td><input type="text" name="db_name[]" value="<?php echo htmlspecialchars($db['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                            <td><input type="text" name="db_user[]" value="<?php echo htmlspecialchars($db['user'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                            <td><input type="password" name="db_pass[]" value="<?php echo htmlspecialchars($db['pass'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
            <p class="muted">留空可删除数据库配置。支持同时配置多个苹果CMS数据库。</p>

            <h3>完结关键字</h3>
            <label for="cleanup_keywords">使用英文逗号分隔</label>
            <input type="text" id="cleanup_keywords" name="cleanup_keywords" value="<?php echo htmlspecialchars(implode(',', $settings['cleanup_keywords']), ENT_QUOTES, 'UTF-8'); ?>">

            <h3>同义词替换（<?php echo $replacementsCount; ?> 条）</h3>
            <label for="replacements_text">格式：原文=替换为（每行一条）</label>
            <textarea id="replacements_text" name="replacements_text"><?php echo htmlspecialchars($settings['replacements_text'], ENT_QUOTES, 'UTF-8'); ?></textarea>

            <button class="btn" type="submit">保存设置</button>
        </form>
    </div>
</main>
</body>
</html>
