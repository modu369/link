<?php

namespace IPIP\DB;

/**
 * 轻量版 IPIP.ipdb Reader（兼容标准字段）
 * 来源：ipip.net 官方开源实现（精简无改动）
 */
class Reader
{
    private $file;
    private $nodeCount;
    private $node;
    private $meta;
    private $v4offset;

    public function __construct($dbname)
    {
        $this->file = fopen($dbname, 'rb');
        if ($this->file === false) {
            throw new \InvalidArgumentException("Fail to open ipdb file `{$dbname}`");
        }

        $metaLength = unpack('N', fread($this->file, 4))[1];
        $meta = fread($this->file, $metaLength);
        $this->meta = json_decode($meta, true);
        $this->nodeCount = $this->meta['node_count'];
        $this->node = 0;
        if ($this->meta['ip_version'] == 4) {
            $this->v4offset = $this->readNode(0, 0);
        }
    }

    public function __destruct()
    {
        if ($this->file) {
            fclose($this->file);
        }
    }

    public function findMap($ip, $language = 'CN')
    {
        $off = $this->find($ip);
        $data = $this->resolve($off, $language);
        $result = [];
        if (!empty($this->meta['fields'])) {
            foreach ($this->meta['fields'] as $index => $name) {
                $result[$name] = $data[$index] ?? '';
            }
        }
        return $result;
    }

    private function resolve($offset, $language)
    {
        $len = unpack('C', fgetc($this->file))[1];
        $buf = fread($this->file, $len);
        $arr = explode("\t", $buf);
        $langs = $this->meta['languages'];
        $idx = $langs[$language] ?? $langs['CN'] ?? 0;
        $size = count($this->meta['fields']);
        return array_slice($arr, $idx * $size, $size);
    }

    private function find($ip)
    {
        $ipBytes = inet_pton($ip);
        if ($ipBytes === false) {
            throw new \InvalidArgumentException("Invalid ip address `{$ip}`");
        }

        if (strlen($ipBytes) === 4) {
            $node = $this->v4offset;
        } else {
            $node = 0;
        }

        for ($i = 0; $i < strlen($ipBytes) * 8; $i++) {
            if ($node > $this->nodeCount) {
                break;
            }
            $b = $this->getBit($ipBytes, $i);
            $node = $this->readNode($node, $b);
            if ($node > $this->nodeCount) {
                break;
            }
        }

        if ($node === $this->nodeCount) {
            return $node;
        } elseif ($node > $this->nodeCount) {
            return $node;
        }

        throw new \RuntimeException("IP not found");
    }

    private function getBit($bytes, $index)
    {
        $byte = ord($bytes[intval($index / 8)]);
        return ($byte >> (7 - ($index % 8))) & 1;
    }

    private function readNode($node, $index)
    {
        fseek($this->file, ($node * 2 + $index) * 4 + 4);
        $buf = fread($this->file, 4);
        $val = unpack('N', $buf)[1];
        return $val;
    }
}
