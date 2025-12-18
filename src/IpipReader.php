<?php
declare(strict_types=1);

namespace IPIP\DB;

class Reader
{
    private $fh;

    private array $meta = [];
    private int $nodeCount = 0;
    private int $baseOffset = 0;     // = 4 + metaLength
    private int $dataBase = 0;       // = baseOffset + nodeCount*8
    private int $totalSize = 0;

    private int $v4startNode = 0;    // ip_version=3 用 meta[v4node]

    public function __construct(string $dbPath)
    {
        $this->fh = fopen($dbPath, 'rb');
        if (!$this->fh) {
            throw new \RuntimeException("Cannot open ipdb file: {$dbPath}");
        }

        $metaLenRaw = $this->readBytes(4, 'meta length');
        $metaLen = unpack('N', $metaLenRaw)[1];
        if ($metaLen <= 0 || $metaLen > 10_000_000) {
            throw new \RuntimeException("Invalid meta length: {$metaLen}");
        }

        $metaRaw = $this->readBytes($metaLen, 'meta json');
        $this->meta = json_decode($metaRaw, true, 512, JSON_THROW_ON_ERROR);

        $this->nodeCount  = (int)($this->meta['node_count'] ?? 0);
        $this->totalSize  = (int)($this->meta['total_size'] ?? 0);
        $this->baseOffset = 4 + $metaLen;
        $this->dataBase   = $this->baseOffset + ($this->nodeCount * 8);

        if ($this->nodeCount <= 0) {
            throw new \RuntimeException("Invalid node_count");
        }

        // total_size 有的库会给 0；如果给了就拿来做强校验
        if ($this->totalSize > 0) {
            $stat = fstat($this->fh);
            $realSize = (int)($stat['size'] ?? 0);
            // 有些库 total_size == 实际文件大小；不一致也不致命，但用于边界判断时取更小的
            if ($realSize > 0) {
                $this->totalSize = ($this->totalSize > 0) ? min($this->totalSize, $realSize) : $realSize;
            }
        } else {
            $stat = fstat($this->fh);
            $this->totalSize = (int)($stat['size'] ?? 0);
        }

        // ✅ 关键：ip_version=3 (v4+v6) IPv4 必须从 v4node 起步
        $ipVer = (int)($this->meta['ip_version'] ?? 0);
        if ($ipVer === 3) {
            $this->v4startNode = (int)($this->meta['v4node'] ?? 0);
        } elseif ($ipVer === 4) {
            // 兼容某些库的 v4 起点写法
            $this->v4startNode = $this->readNode(0, 0);
        } else {
            $this->v4startNode = 0;
        }

        // 基础结构校验：数据区起点必须在文件范围内
        if ($this->dataBase <= 0 || $this->dataBase >= $this->totalSize) {
            throw new \RuntimeException("Invalid data base offset: {$this->dataBase} / total {$this->totalSize}");
        }
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    public function findMap(string $ip, string $lang = 'CN'): array
    {
        $node = $this->findNode($ip);
        return $this->readDataByNode($node, $lang);
    }

    private function findNode(string $ip): int
    {
        $ipBytes = inet_pton($ip);
        if ($ipBytes === false) {
            throw new \InvalidArgumentException("Invalid IP: {$ip}");
        }

        $isV4 = (strlen($ipBytes) === 4);
        $node = $isV4 ? $this->v4startNode : 0;

        $bits = strlen($ipBytes) * 8;
        for ($i = 0; $i < $bits; $i++) {
            if ($node >= $this->nodeCount) {
                break;
            }
            $bit = (ord($ipBytes[$i >> 3]) >> (7 - ($i & 7))) & 1;
            $node = $this->readNode($node, $bit);
        }

        if ($node < $this->nodeCount) {
            throw new \RuntimeException("IP not found in trie");
        }
        return $node;
    }

    private function readDataByNode(int $node, string $lang): array
    {
        $rel = $node - $this->nodeCount;
        if ($rel < 0) {
            throw new \RuntimeException("Invalid node (rel<0): {$node}");
        }

        $offset = $this->dataBase + $rel;

        // ✅ 强校验：offset 必须落在数据区且在文件内
        if ($offset < $this->dataBase || $offset + 2 > $this->totalSize) {
            throw new \RuntimeException("Data offset out of range: {$offset}");
        }

        fseek($this->fh, $offset, SEEK_SET);

        // 数据长度 uint16 BE
        $lenRaw = $this->readBytes(2, 'data length');
        $len = unpack('n', $lenRaw)[1];

        if ($len < 0 || $offset + 2 + $len > $this->totalSize) {
            throw new \RuntimeException("Data length out of range: len={$len}, offset={$offset}, total={$this->totalSize}");
        }

        $buf = ($len > 0) ? $this->readBytes($len, 'data block') : '';
        $items = ($buf === '') ? [] : explode("\t", $buf);

        $fields = $this->meta['fields'] ?? [];
        $langs  = $this->meta['languages'] ?? [];
        $idx    = $langs[$lang] ?? ($langs['CN'] ?? 0);
        $size   = count($fields);

        $slice = ($size > 0) ? array_slice($items, $idx * $size, $size) : [];

        $ret = [];
        foreach ($fields as $i => $name) {
            $ret[$name] = $slice[$i] ?? '';
        }
        return $ret;
    }

    private function readNode(int $node, int $idx): int
    {
        // node 区：baseOffset + (node*2+idx)*4
        $pos = $this->baseOffset + (($node * 2 + $idx) * 4);

        // ✅ 强校验：node 读取位置不能跑到 meta 区或文件外
        if ($pos < $this->baseOffset || $pos + 4 > $this->dataBase) {
            throw new \RuntimeException("Node offset out of range: pos={$pos}, node={$node}, idx={$idx}");
        }

        fseek($this->fh, $pos, SEEK_SET);
        $buf = $this->readBytes(4, 'node');
        return unpack('N', $buf)[1];
    }

    private function readBytes(int $n, string $what): string
    {
        $buf = fread($this->fh, $n);
        if ($buf === false || strlen($buf) !== $n) {
            $got = ($buf === false) ? 0 : strlen($buf);
            throw new \RuntimeException("Failed to read {$what}: need {$n}, got {$got}");
        }
        return $buf;
    }
}
