<?php

class StateStore
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function read(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        $contents = file_get_contents($this->path);
        if ($contents === false || $contents === '') {
            return [];
        }

        $payload = json_decode($contents, true);
        if (!is_array($payload)) {
            return [];
        }

        return $payload;
    }

    public function write(array $state): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $tmp = $this->path . '.tmp';
        file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($tmp, $this->path);
    }
}
