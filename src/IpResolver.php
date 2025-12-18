<?php
declare(strict_types=1);

use IPIP\DB\Reader;

class IpResolver
{
    private ?Reader $reader = null;

    /** 这些值在 qqwry/ipdb 中表示“无有效地理意义” */
    private const INVALID_COUNTRIES = [
        '',
        '未知',
        '保留地址',
    ];

    private const INVALID_ISP = [
        '',
        'IETF',
    ];

    public function __construct(?string $dbPath = null)
    {
        if ($dbPath && is_file($dbPath)) {
            $this->bootReader($dbPath);
        }
    }

    private function bootReader(string $dbPath): void
    {
        if (!class_exists(Reader::class)) {
            require_once __DIR__ . '/IpipReader.php';
        }

        try {
            $this->reader = new Reader($dbPath);
        } catch (\Throwable $e) {
            $this->reader = null;
        }
    }

    /**
     * 解析 IP
     * - 成功：返回字段数组
     * - 无效 / 保留段：返回 []
     */
    public function resolve(?string $ip): array
    {
        if (!$ip || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [];
        }

        if (!$this->reader) {
            return [];
        }

        try {
            $data = $this->reader->findMap($ip, 'CN');
        } catch (\Throwable $e) {
            return [];
        }

        if (empty($data)) {
            return [];
        }

        // ===== 业务级校验 =====
        $country = trim($data['country_name'] ?? '');
        $isp     = trim($data['isp_domain'] ?? '');

        // 国家无意义
        if (in_array($country, self::INVALID_COUNTRIES, true)) {
            return [];
        }

        // ISP 明确是 IETF（保留/协议段）
        if ($isp !== '' && in_array($isp, self::INVALID_ISP, true)) {
            return [];
        }

        return $data;
    }
}

