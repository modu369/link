<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!($_SESSION['authenticated'] ?? false) || !($_SESSION['entry_valid'] ?? false)) {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$settings = loadSettings();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'state':
            $schedule = loadSchedule();
            echo json_encode([
                'schedule' => $schedule,
                'settings' => [
                    'dbs' => $settings['dbs'],
                    'cleanup_keywords' => $settings['cleanup_keywords'],
                    'replacements_text' => $settings['replacements_text'],
                    'proxy_host' => $settings['proxy_host'],
                    'proxy_port' => $settings['proxy_port'],
                    'proxy_user' => $settings['proxy_user'],
                    'proxy_pass' => $settings['proxy_pass'],
                    'manual_schedule_html' => $settings['manual_schedule_html'],
                ],
                'replacements_count' => count(parseReplacements($settings['replacements_text'])),
            ], JSON_UNESCAPED_UNICODE);
            break;
        case 'save_settings':
            $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (!is_array($payload)) {
                throw new RuntimeException('参数格式错误');
            }
            $settings['cleanup_keywords'] = array_values(array_filter(array_map('trim', $payload['cleanup_keywords'] ?? [])));
            $settings['replacements_text'] = $payload['replacements_text'] ?? '';
            $settings['proxy_host'] = trim((string)($payload['proxy_host'] ?? ''));
            $settings['proxy_port'] = trim((string)($payload['proxy_port'] ?? ''));
            $settings['proxy_user'] = (string)($payload['proxy_user'] ?? '');
            $settings['proxy_pass'] = (string)($payload['proxy_pass'] ?? '');
            $settings['manual_schedule_html'] = (string)($payload['manual_schedule_html'] ?? '');
            if (!empty($payload['admin_password'])) {
                $settings['admin_password'] = (string)$payload['admin_password'];
            }

            $dbs = [];
            foreach ($payload['dbs'] ?? [] as $db) {
                if (trim((string)($db['name'] ?? '')) === '') {
                    continue;
                }
                $dbs[] = [
                    'label' => trim((string)($db['label'] ?? '')),
                    'host' => trim((string)($db['host'] ?? 'localhost')),
                    'port' => trim((string)($db['port'] ?? '3306')),
                    'name' => trim((string)($db['name'] ?? '')),
                    'user' => trim((string)($db['user'] ?? '')),
                    'pass' => (string)($db['pass'] ?? ''),
                ];
            }
            $settings['dbs'] = $dbs;
            saveSettings($settings);
            echo json_encode(['message' => '设置已保存。'], JSON_UNESCAPED_UNICODE);
            break;
        case 'fetch_schedule':
            $replacements = parseReplacements($settings['replacements_text']);
            $manualHtml = trim((string)($settings['manual_schedule_html'] ?? ''));
            $statusCode = null;
            if ($manualHtml !== '') {
                $schedule = parseScheduleFromHtml($manualHtml, $replacements);
            } else {
                $fetchResult = fetchScheduleHtml($settings);
                $statusCode = $fetchResult['status_code'];
                $schedule = parseScheduleFromHtml($fetchResult['html'], $replacements);
            }
            if ($schedule === []) {
                throw new RuntimeException('未识别到有效的更番表内容，请检查抓取或手动HTML。');
            }
            $scheduleData = loadSchedule();
            saveSchedule($schedule, $scheduleData['manual_items'] ?? []);
            echo json_encode([
                'message' => '更番表已更新。',
                'count' => count($schedule),
                'status_code' => $statusCode,
            ], JSON_UNESCAPED_UNICODE);
            break;
        case 'parse_manual_html':
            $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (!is_array($payload)) {
                throw new RuntimeException('参数格式错误');
            }
            $manualHtml = trim((string)($payload['manual_schedule_html'] ?? ''));
            if ($manualHtml === '') {
                throw new RuntimeException('手动HTML不能为空');
            }
            $settings['manual_schedule_html'] = $manualHtml;
            saveSettings($settings);
            $replacements = parseReplacements($settings['replacements_text']);
            $schedule = parseScheduleFromHtml($manualHtml, $replacements);
            if ($schedule === []) {
                throw new RuntimeException('未识别到有效的更番表内容，请确认粘贴的是更番表 HTML。');
            }
            $scheduleData = loadSchedule();
            saveSchedule($schedule, $scheduleData['manual_items'] ?? []);
            echo json_encode(['message' => '已从手动HTML提取更番信息。', 'count' => count($schedule)], JSON_UNESCAPED_UNICODE);
            break;
        case 'update_weekday':
            $scheduleData = loadSchedule();
            $replacements = parseReplacements($settings['replacements_text']);
            $items = array_merge($scheduleData['items'] ?? [], $scheduleData['manual_items'] ?? []);
            $summary = updateWeekday($settings['dbs'], $items, $replacements);
            echo json_encode(['summary' => $summary], JSON_UNESCAPED_UNICODE);
            break;
        case 'update_weekday_fetched':
            $scheduleData = loadSchedule();
            $replacements = parseReplacements($settings['replacements_text']);
            $summary = updateWeekday($settings['dbs'], $scheduleData['items'] ?? [], $replacements);
            echo json_encode(['summary' => $summary], JSON_UNESCAPED_UNICODE);
            break;
        case 'update_weekday_manual':
            $scheduleData = loadSchedule();
            $replacements = parseReplacements($settings['replacements_text']);
            $summary = updateWeekday($settings['dbs'], $scheduleData['manual_items'] ?? [], $replacements);
            echo json_encode(['summary' => $summary], JSON_UNESCAPED_UNICODE);
            break;
        case 'add_schedule_item':
            $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (!is_array($payload)) {
                throw new RuntimeException('参数格式错误');
            }
            $weekday = trim((string)($payload['weekday'] ?? ''));
            $name = trim((string)($payload['name'] ?? ''));
            if ($weekday === '' || $name === '') {
                throw new RuntimeException('星期与名称不能为空');
            }
            $scheduleData = loadSchedule();
            $manualItems = $scheduleData['manual_items'] ?? [];
            $manualItems[] = [
                'weekday' => $weekday,
                'name' => $name,
                'original_name' => $name,
            ];
            saveSchedule($scheduleData['items'] ?? [], $manualItems);
            echo json_encode(['message' => '已新增更番信息。'], JSON_UNESCAPED_UNICODE);
            break;
        case 'update_schedule_item':
            $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (!is_array($payload)) {
                throw new RuntimeException('参数格式错误');
            }
            $index = (int)($payload['index'] ?? -1);
            $weekday = trim((string)($payload['weekday'] ?? ''));
            $name = trim((string)($payload['name'] ?? ''));
            if ($index < 0 || $weekday === '' || $name === '') {
                throw new RuntimeException('索引、星期与名称不能为空');
            }
            $scheduleData = loadSchedule();
            $manualItems = $scheduleData['manual_items'] ?? [];
            if (!array_key_exists($index, $manualItems)) {
                throw new RuntimeException('未找到该更番信息');
            }
            $manualItems[$index]['weekday'] = $weekday;
            $manualItems[$index]['name'] = $name;
            $manualItems[$index]['original_name'] = $name;
            saveSchedule($scheduleData['items'] ?? [], $manualItems);
            echo json_encode(['message' => '更番信息已更新。'], JSON_UNESCAPED_UNICODE);
            break;
        case 'delete_schedule_item':
            $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (!is_array($payload)) {
                throw new RuntimeException('参数格式错误');
            }
            $index = (int)($payload['index'] ?? -1);
            if ($index < 0) {
                throw new RuntimeException('索引不能为空');
            }
            $scheduleData = loadSchedule();
            $manualItems = $scheduleData['manual_items'] ?? [];
            if (!array_key_exists($index, $manualItems)) {
                throw new RuntimeException('未找到该更番信息');
            }
            array_splice($manualItems, $index, 1);
            saveSchedule($scheduleData['items'] ?? [], $manualItems);
            echo json_encode(['message' => '更番信息已删除。'], JSON_UNESCAPED_UNICODE);
            break;
        case 'clear_manual_schedule':
            $scheduleData = loadSchedule();
            saveSchedule($scheduleData['items'] ?? [], []);
            echo json_encode(['message' => '已清空手动更番信息。'], JSON_UNESCAPED_UNICODE);
            break;
        case 'update_schedule_entry':
            $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (!is_array($payload)) {
                throw new RuntimeException('参数格式错误');
            }
            $index = (int)($payload['index'] ?? -1);
            $name = trim((string)($payload['name'] ?? ''));
            $source = (string)($payload['source'] ?? '');
            if ($index < 0 || $name === '' || ($source !== 'fetched' && $source !== 'manual')) {
                throw new RuntimeException('参数错误');
            }
            $scheduleData = loadSchedule();
            if ($source === 'fetched') {
                $items = $scheduleData['items'] ?? [];
                if (!array_key_exists($index, $items)) {
                    throw new RuntimeException('未找到该更番信息');
                }
                $items[$index]['name'] = $name;
                $items[$index]['original_name'] = $items[$index]['original_name'] ?? $name;
                saveSchedule($items, $scheduleData['manual_items'] ?? []);
            } else {
                $manualItems = $scheduleData['manual_items'] ?? [];
                if (!array_key_exists($index, $manualItems)) {
                    throw new RuntimeException('未找到该更番信息');
                }
                $manualItems[$index]['name'] = $name;
                $manualItems[$index]['original_name'] = $name;
                saveSchedule($scheduleData['items'] ?? [], $manualItems);
            }
            echo json_encode(['message' => '更番信息已更新。'], JSON_UNESCAPED_UNICODE);
            break;
        case 'delete_schedule_entry':
            $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (!is_array($payload)) {
                throw new RuntimeException('参数格式错误');
            }
            $index = (int)($payload['index'] ?? -1);
            $source = (string)($payload['source'] ?? '');
            if ($index < 0 || ($source !== 'fetched' && $source !== 'manual')) {
                throw new RuntimeException('参数错误');
            }
            $scheduleData = loadSchedule();
            if ($source === 'fetched') {
                $items = $scheduleData['items'] ?? [];
                if (!array_key_exists($index, $items)) {
                    throw new RuntimeException('未找到该更番信息');
                }
                array_splice($items, $index, 1);
                saveSchedule($items, $scheduleData['manual_items'] ?? []);
            } else {
                $manualItems = $scheduleData['manual_items'] ?? [];
                if (!array_key_exists($index, $manualItems)) {
                    throw new RuntimeException('未找到该更番信息');
                }
                array_splice($manualItems, $index, 1);
                saveSchedule($scheduleData['items'] ?? [], $manualItems);
            }
            echo json_encode(['message' => '更番信息已删除。'], JSON_UNESCAPED_UNICODE);
            break;
        case 'cleanup_weekday':
            $summary = cleanupWeekday($settings['dbs'], $settings['cleanup_keywords']);
            echo json_encode(['summary' => $summary], JSON_UNESCAPED_UNICODE);
            break;
        case 'add_replacement':
            $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (!is_array($payload)) {
                throw new RuntimeException('参数格式错误');
            }
            $from = trim((string)($payload['from'] ?? ''));
            $to = trim((string)($payload['to'] ?? ''));
            if ($from === '') {
                throw new RuntimeException('原名称不能为空');
            }
            $settings['replacements_text'] = trim($settings['replacements_text'] . "\n{$from}={$to}");
            saveSettings($settings);
            echo json_encode(['message' => '已新增同名替换。'], JSON_UNESCAPED_UNICODE);
            break;
        default:
            throw new RuntimeException('未知操作');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
