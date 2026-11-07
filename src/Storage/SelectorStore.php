<?php

namespace ArthurHoaro\RssExtender\Storage;

class SelectorStore
{
    public function __construct(protected string $path)
    {
    }

    public function get(string $feed): ?string
    {
        if (! is_file($this->path)) {
            return null;
        }

        $handle = fopen($this->path, 'r');
        flock($handle, LOCK_SH);
        $selectors = json_decode(stream_get_contents($handle), true) ?: [];
        fclose($handle);

        return is_string($selectors[$feed] ?? null) ? $selectors[$feed] : null;
    }

    public function set(string $feed, string $selector): void
    {
        $this->update(function (array $selectors) use ($feed, $selector) {
            $selectors[$feed] = $selector;

            return $selectors;
        });
    }

    public function delete(string $feed): bool
    {
        $deleted = false;
        $this->update(function (array $selectors) use ($feed, &$deleted) {
            $deleted = array_key_exists($feed, $selectors);
            unset($selectors[$feed]);

            return $selectors;
        });

        return $deleted;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    protected function update(callable $change): void
    {
        $handle = fopen($this->path, 'c+');
        flock($handle, LOCK_EX);
        $selectors = $change(json_decode(stream_get_contents($handle), true) ?: []);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($selectors));
        fflush($handle);
        fclose($handle);
    }
}
