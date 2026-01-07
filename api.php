<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!($_SESSION['authenticated'] ?? false)) {
    http_response_code(401);
    echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
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
            $schedule = scrapeSchedule($replacements, $settings);
            saveSchedule($schedule);
            echo json_encode(['message' => '更番表已更新。', 'count' => count($schedule)], JSON_UNESCAPED_UNICODE);
            break;
        case 'update_weekday':
            $scheduleData = loadSchedule();
            $summary = updateWeekday($settings['dbs'], $scheduleData['items'] ?? []);
            echo json_encode(['summary' => $summary], JSON_UNESCAPED_UNICODE);
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
