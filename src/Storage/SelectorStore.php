<?php

namespace ArthurHoaro\RssExtender\Storage;

class SelectorStore
{
    public function __construct(protected string $path)
    {
    }

    /**
     * @return array{selector: string, all: bool}|null
     */
    public function get(string $feed): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $handle = fopen($this->path, 'r');
        flock($handle, LOCK_SH);
        $selectors = json_decode(stream_get_contents($handle), true) ?: [];
        fclose($handle);

        $entry = $selectors[$feed] ?? null;
        if (is_string($entry)) {
            return ['selector' => $entry, 'all' => false];
        }
        if (is_array($entry) && is_string($entry['selector'] ?? null)) {
            return ['selector' => $entry['selector'], 'all' => ! empty($entry['all'])];
        }

        return null;
    }

    public function set(string $feed, string $selector, bool $all = false): void
    {
        $this->update(function (array $selectors) use ($feed, $selector, $all) {
            $selectors[$feed] = $all ? ['selector' => $selector, 'all' => true] : $selector;

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
