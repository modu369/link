<?php

/**
 * IP 解析器：优先使用 QQWry IPIP.ipdb，如果未提供则回退到简易网段推断。
 */
class IpResolver
{
    private ?object $reader = null;
    private ?string $dbPath = null;

    public function __construct(?string $dbPath = null)
    {
        $this->dbPath = $dbPath && is_file($dbPath) ? $dbPath : null;
        $this->bootReader();
    }

    private function bootReader(): void
    {
        if (!$this->dbPath) {
            return;
        }

        // 支持用户自行通过 Composer 安装 ipip/db 组件
        if (!class_exists('\\IPIP\\DB\\Reader')) {
            $local = __DIR__ . '/IpipReader.php';
            if (is_file($local)) {
                require_once $local;
            }
        }

        if (class_exists('\\IPIP\\DB\\Reader')) {
            try {
                $this->reader = new \IPIP\DB\Reader($this->dbPath);
            } catch (\Throwable $e) {
                $this->reader = null;
            }
        }
    }

    public function resolve(?string $ip): array
    {
        if (!$ip || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [];
        }

        // 优先走官方 Reader
        if ($this->reader) {
            try {
                $data = $this->reader->findMap($ip, 'CN');
                if (is_array($data)) {
                    return $data;
                }
            } catch (\Throwable $e) {
                // ignore and fallback
            }
        }

        // 简易回退：仅通过私网 / 本地网段推断
        if (str_starts_with($ip, '10.') || str_starts_with($ip, '192.168.') || str_starts_with($ip, '172.')) {
            return [
                'country_name' => '内网',
                'region_name' => '内网',
                'city_name' => '',
                'isp_domain' => '内网',
                'country_code' => '',
                'continent_code' => '',
            ];
        }

        if (str_starts_with($ip, '127.')) {
            return [
                'country_name' => '本地回环',
                'region_name' => '本地回环',
                'city_name' => '',
                'isp_domain' => '本地',
                'country_code' => '',
                'continent_code' => '',
            ];
        }

        return [];
    }
}
