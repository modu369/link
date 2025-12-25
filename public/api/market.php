<?php

require_once __DIR__ . '/../../src/bootstrap.php';

function resolveEventSlug(array $config): string
{
    $slug = $_GET['slug'] ?? '';
    if ($slug !== '') {
        return $slug;
    }

    $url = $_GET['url'] ?? '';
    if ($url !== '') {
        $parts = parse_url($url);
        if (!empty($parts['path'])) {
            $segments = array_values(array_filter(explode('/', $parts['path'])));
            if (isset($segments[1])) {
                return $segments[1];
            }
        }
    }

    return $config['polymarket']['event_slug'];
}

$eventSlug = resolveEventSlug($config);

$tagSlug = $_GET['tag'] ?? $config['polymarket']['gamma_default_tag'];
$limit = (int) ($_GET['limit'] ?? $config['polymarket']['gamma_default_limit']);
$snapshot = $marketService->fetchMarketSnapshot($eventSlug !== '' ? $eventSlug : null, $tagSlug, $limit);
header('Content-Type: application/json; charset=utf-8');

echo json_encode($snapshot, JSON_UNESCAPED_SLASHES);
