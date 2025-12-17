<?php
/**
 * 百度 eqid 获取关键词（支持直接被 PHP 调用）
 * 基于原逻辑改写 + Redis 缓存（48小时）
 */

function getBaiduKeywordByEqid(string $eqid): string {
    if (empty($eqid)) return '';

    $keyword_utf8 = '';

    // ==================== Redis 缓存 ====================
    $redis = null;
    if (class_exists('Redis')) {
        try {
            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379);
            $cacheKey = 'eqid:' . $eqid;
            if ($cached = $redis->get($cacheKey)) {
                $data = json_decode($cached, true);
                if (!empty($data['keyword'])) {
                    return $data['keyword'];
                }
            }
        } catch (Exception $e) {
            $redis = null;
        }
    }

    // ==================== 请求百度 ====================
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

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://zhidao.baidu.com/question/52319878.html',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $header,
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_TIMEOUT => 10,
    ]);

    $content = curl_exec($curl);
    curl_close($curl);

    // ==================== 提取关键词 ====================
    if ($content !== false && preg_match('/<input class="hdi" id="kw"[^>]*name="word" value="(.*?)"/i', $content, $matches)) {
        $keyword = $matches[1];
        $encoding = mb_detect_encoding($keyword, ['GBK', 'GB2312', 'UTF-8'], true);
        $keyword_utf8 = ($encoding !== 'UTF-8')
            ? @iconv($encoding, 'UTF-8//IGNORE', $keyword)
            : $keyword;
    }

    // ==================== 写入缓存（48小时） ====================
    if ($redis) {
        $redis->setex($cacheKey, 172800, json_encode(['keyword' => $keyword_utf8], JSON_UNESCAPED_UNICODE));
    }

    return $keyword_utf8 ?: '';
}
