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
            'admin_password' => 'admin123',
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
    $settings['admin_password'] = $settings['admin_password'] ?? 'admin123';

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
    $ch = curl_init('https://www.comicat.org/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Referer: https://www.comicat.org/',
        ],
    ]);
    $html = curl_exec($ch);
    if ($html === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('抓取失败：' . $error);
    }
    curl_close($ch);

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
