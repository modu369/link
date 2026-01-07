<?php
declare(strict_types=1);

const SETTINGS_PATH = __DIR__ . '/data/settings.json';
const SCHEDULE_PATH = __DIR__ . '/data/schedule.json';

function loadSettings(): array
{
    if (!file_exists(SETTINGS_PATH)) {
        $defaults = [
            'dbs' => [],
            'cleanup_keywords' => ['全', '完'],
            'replacements_text' => '',
            'admin_password' => 'admin123',
            'proxy_host' => '',
            'proxy_port' => '',
            'proxy_user' => '',
            'proxy_pass' => '',
            'manual_schedule_html' => '',
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
    $settings['replacements_text'] = $settings['replacements_text'] ?? '';
    $settings['admin_password'] = $settings['admin_password'] ?? 'admin123';
    $settings['proxy_host'] = $settings['proxy_host'] ?? '';
    $settings['proxy_port'] = $settings['proxy_port'] ?? '';
    $settings['proxy_user'] = $settings['proxy_user'] ?? '';
    $settings['proxy_pass'] = $settings['proxy_pass'] ?? '';
    $settings['manual_schedule_html'] = $settings['manual_schedule_html'] ?? '';

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

function scrapeSchedule(array $replacements, array $settings): array
{
    $manualHtml = trim((string)($settings['manual_schedule_html'] ?? ''));
    if ($manualHtml !== '') {
        $html = $manualHtml;
    } else {
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
        $proxyHost = trim((string)($settings['proxy_host'] ?? ''));
        if ($proxyHost !== '') {
            $proxyPort = trim((string)($settings['proxy_port'] ?? ''));
            $proxyAddress = $proxyHost . ($proxyPort !== '' ? ':' . $proxyPort : '');
            curl_setopt($ch, CURLOPT_PROXY, $proxyAddress);
            $proxyUser = (string)($settings['proxy_user'] ?? '');
            $proxyPass = (string)($settings['proxy_pass'] ?? '');
            if ($proxyUser !== '' || $proxyPass !== '') {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyUser . ':' . $proxyPass);
            }
        }
        $html = curl_exec($ch);
        if ($html === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('抓取失败：' . $error);
        }
        curl_close($ch);
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
