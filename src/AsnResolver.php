<?php
declare(strict_types=1);

use IPIP\DB\Reader;

class AsnResolver
{
    private ?Reader $reader = null;
    private ?string $dbPath = null;
    private int $lastMtime = 0;
    private ?string $dbPathV4 = null;
    private ?string $dbPathV6 = null;
    private int $lastMtimeV4 = 0;
    private int $lastMtimeV6 = 0;
    private bool $tsvLoaded = false;
    private array $tsvRanges = [];
    private array $tsvAsn = [];
    private array $tsvName = [];
    private bool $tsvV6Loaded = false;
    private array $tsvRangesV6 = [];
    private array $tsvAsnV6 = [];
    private array $tsvNameV6 = [];

    public function __construct(?string $dbPath = null, ?string $dbPathV4 = null, ?string $dbPathV6 = null)
    {
        if ($dbPath && is_file($dbPath)) {
            $this->dbPath = $dbPath;
            $this->lastMtime = (int) filemtime($dbPath);
            $this->bootReader($dbPath);
        }

        if ($dbPathV4 && is_file($dbPathV4)) {
            $this->dbPathV4 = $dbPathV4;
            $this->lastMtimeV4 = (int) filemtime($dbPathV4);
        }

        if ($dbPathV6 && is_file($dbPathV6)) {
            $this->dbPathV6 = $dbPathV6;
            $this->lastMtimeV6 = (int) filemtime($dbPathV6);
        }
    }

    private function bootReader(string $dbPath): void
    {
        if (str_ends_with($dbPath, '.tsv') || str_ends_with($dbPath, '.tsv.gz')) {
            $this->reader = null;
            $this->tsvLoaded = false;
            $this->tsvRanges = [];
            $this->tsvAsn = [];
            $this->tsvName = [];
            $this->tsvV6Loaded = false;
            $this->tsvRangesV6 = [];
            $this->tsvAsnV6 = [];
            $this->tsvNameV6 = [];
            return;
        }

        if (!class_exists(Reader::class)) {
            require_once __DIR__ . '/IpipReader.php';
        }

        try {
            $this->reader = new Reader($dbPath);
        } catch (\Throwable $e) {
            $this->reader = null;
        }
    }

    public function refreshIfUpdated(): void
    {
        if (!$this->dbPath || !is_file($this->dbPath)) {
            // fall back to TSV modes only
        } else {
            $mtime = (int) filemtime($this->dbPath);
            if ($mtime > 0 && $mtime !== $this->lastMtime) {
                $this->lastMtime = $mtime;
                $this->bootReader($this->dbPath);
            }
        }

        if ($this->dbPathV4 && is_file($this->dbPathV4)) {
            $mtimeV4 = (int) filemtime($this->dbPathV4);
            if ($mtimeV4 > 0 && $mtimeV4 !== $this->lastMtimeV4) {
                $this->lastMtimeV4 = $mtimeV4;
                $this->tsvLoaded = false;
                $this->tsvRanges = [];
                $this->tsvAsn = [];
                $this->tsvName = [];
            }
        }

        if ($this->dbPathV6 && is_file($this->dbPathV6)) {
            $mtimeV6 = (int) filemtime($this->dbPathV6);
            if ($mtimeV6 > 0 && $mtimeV6 !== $this->lastMtimeV6) {
                $this->lastMtimeV6 = $mtimeV6;
                $this->tsvV6Loaded = false;
                $this->tsvRangesV6 = [];
                $this->tsvAsnV6 = [];
                $this->tsvNameV6 = [];
            }
        }
    }

    public function resolve(?string $ip): array
    {
        if (!$ip || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [];
        }

        if ($this->reader) {
            return $this->resolveWithReader($ip);
        }

        return $this->resolveWithTsv($ip);
    }

    private function resolveWithReader(string $ip): array
    {
        try {
            $data = $this->reader->findMap($ip, 'EN');
        } catch (\Throwable $e) {
            return [];
        }

        if (empty($data) || !is_array($data)) {
            return [];
        }

        $number = '';
        $name = '';

        foreach ($data as $key => $value) {
            $key = strtolower((string) $key);
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            if ($number === '' && (str_contains($key, 'asn') || str_contains($key, 'autonomous_system_number') || str_contains($key, 'as_number'))) {
                $number = preg_replace('/[^0-9]/', '', $value);
                if ($number !== '') {
                    continue;
                }
            }

            if ($name === '' && (str_contains($key, 'asn_name') || str_contains($key, 'organization') || str_contains($key, 'org') || str_contains($key, 'as_name'))) {
                $name = $value;
            }
        }

        if ($number === '' && isset($data['asn'])) {
            $number = preg_replace('/[^0-9]/', '', (string) $data['asn']);
        }

        if ($name === '' && isset($data['asn_name'])) {
            $name = trim((string) $data['asn_name']);
        }

        if ($number === '' && $name === '') {
            return [];
        }

        return [
            'number' => $number,
            'name' => $name,
        ];
    }

    private function resolveWithTsv(string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->resolveWithTsvV6($ip);
        }

        if (!$this->dbPathV4 || !is_file($this->dbPathV4)) {
            return [];
        }

        if (!$this->tsvLoaded) {
            $this->loadTsvRanges($this->dbPathV4, false);
        }

        $ipLong = ip2long($ip);
        if ($ipLong === false || empty($this->tsvRanges)) {
            return [];
        }

        $low = 0;
        $high = count($this->tsvRanges) - 1;
        while ($low <= $high) {
            $mid = (int) floor(($low + $high) / 2);
            $range = $this->tsvRanges[$mid];
            if ($ipLong < $range[0]) {
                $high = $mid - 1;
                continue;
            }
            if ($ipLong > $range[1]) {
                $low = $mid + 1;
                continue;
            }

            return [
                'number' => $this->tsvAsn[$mid] ?? '',
                'name' => $this->tsvName[$mid] ?? '',
            ];
        }

        return [];
    }

    private function resolveWithTsvV6(string $ip): array
    {
        if (!$this->dbPathV6 || !is_file($this->dbPathV6)) {
            return [];
        }

        if (!$this->tsvV6Loaded) {
            $this->loadTsvRanges($this->dbPathV6, true);
        }

        $ipBin = inet_pton($ip);
        if ($ipBin === false || empty($this->tsvRangesV6)) {
            return [];
        }

        $low = 0;
        $high = count($this->tsvRangesV6) - 1;
        while ($low <= $high) {
            $mid = (int) floor(($low + $high) / 2);
            $range = $this->tsvRangesV6[$mid];
            if ($this->compareBin($ipBin, $range[0]) < 0) {
                $high = $mid - 1;
                continue;
            }
            if ($this->compareBin($ipBin, $range[1]) > 0) {
                $low = $mid + 1;
                continue;
            }

            return [
                'number' => $this->tsvAsnV6[$mid] ?? '',
                'name' => $this->tsvNameV6[$mid] ?? '',
            ];
        }

        return [];
    }

    private function compareBin(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }

        return strcmp($a, $b) < 0 ? -1 : 1;
    }

    private function loadTsvRanges(string $path, bool $isV6): void
    {
        if (!is_file($path)) {
            return;
        }

        $handle = null;
        if (str_ends_with($path, '.gz')) {
            $handle = @gzopen($path, 'rb');
        } else {
            $handle = @fopen($path, 'rb');
        }

        if (!$handle) {
            return;
        }

        $ranges = [];
        $asn = [];
        $name = [];

        while (true) {
            $line = str_ends_with($path, '.gz') ? @gzgets($handle) : @fgets($handle);
            if ($line === false) {
                break;
            }
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if (!$parts || count($parts) < 3) {
                continue;
            }

            if ($isV6) {
                $start = inet_pton($parts[0]);
                $end = inet_pton($parts[1]);
            } else {
                $start = ctype_digit($parts[0]) ? (int) $parts[0] : ip2long($parts[0]);
                $end = ctype_digit($parts[1]) ? (int) $parts[1] : ip2long($parts[1]);
            }
            if ($start === false || $end === false) {
                continue;
            }
            $asnNumber = preg_replace('/[^0-9]/', '', (string) $parts[2]);
            $asnName = '';
            if (count($parts) > 4) {
                $asnName = implode(' ', array_slice($parts, 4));
            } elseif (count($parts) === 4) {
                $asnName = (string) $parts[3];
            }

            $ranges[] = [$start, $end];
            $asn[] = $asnNumber;
            $name[] = $asnName;
        }

        if (str_ends_with($path, '.gz')) {
            @gzclose($handle);
        } else {
            @fclose($handle);
        }

        if ($isV6) {
            $this->tsvRangesV6 = $ranges;
            $this->tsvAsnV6 = $asn;
            $this->tsvNameV6 = $name;
            $this->tsvV6Loaded = true;
        } else {
            $this->tsvRanges = $ranges;
            $this->tsvAsn = $asn;
            $this->tsvName = $name;
            $this->tsvLoaded = true;
        }
    }
}
