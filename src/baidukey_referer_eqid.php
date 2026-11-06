<?php
/**
 * 百度 eqid 获取关键词（支持直接被 PHP 调用）
 * 基于原逻辑改写 + Redis 长连接缓存（48小时）+ cURL Keep-Alive 句柄复用
 */
require_once __DIR__ . '/RedisClient.php';

function getBaiduKeywordByEqid(string $eqid): string {
    if (empty($eqid)) return '';

    $keyword_utf8 = '';
    $cacheKey = 'eqid:' . $eqid;

    // ==================== 1. 复用 RedisClient 长连接缓存 ====================
    $redis = null;
    try {
        $config = require __DIR__ . '/../config/config.php';
        $redis = RedisClient::connection($config['redis']);
        $cached = $redis->get($cacheKey);
        if ($cached !== false && $cached !== null) {
            $data = json_decode($cached, true);
            if (is_array($data) && array_key_exists('keyword', $data)) {
                return (string) $data['keyword'];
            }
        }
    } catch (Throwable $e) {
        $redis = null;
    }

    // ==================== 2. 请求百度（复用静态 cURL 句柄保持 Keep-Alive） ====================
    $header = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3',
        'Accept-Encoding: gzip, deflate, br',
        'Accept-Language: zh-CN,zh;q=0.9',
        'Cache-Control: max-age=0',
        'Connection: keep-alive',
        'Host: zhidao.baidu.com',
        'Referer: https://www.baidu.com/link?url=&wd=&eqid=' . $eqid,
        'Upgrade-Insecure-Requests: 1',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    ];

    static $curl = null;
    if ($curl === null) {
        $curl = curl_init();
    }
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://zhidao.baidu.com/question/52319878.html',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $header,
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);

    $content = curl_exec($curl);

    // ==================== 3. 提取关键词 ====================
    if ($content !== false && preg_match('/<input class="hdi" id="kw"[^>]*name="word" value="(.*?)"/i', $content, $matches)) {
        $keyword = $matches[1];
        $encoding = mb_detect_encoding($keyword, ['GBK', 'GB2312', 'UTF-8'], true);
        $keyword_utf8 = ($encoding !== 'UTF-8')
            ? @iconv($encoding, 'UTF-8//IGNORE', $keyword)
            : $keyword;
    }

    // ==================== 4. 写入缓存（正常词缓存48小时，空结果缓存1小时防穿透） ====================
    if ($redis) {
        try {
            $ttl = ($keyword_utf8 !== '' && $keyword_utf8 !== false) ? 172800 : 3600;
            $redis->setex($cacheKey, $ttl, json_encode(['keyword' => $keyword_utf8 ?: ''], JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
        }
    }

    return $keyword_utf8 ?: '';
}
