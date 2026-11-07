<?php

namespace Tests\Unit;

use ArthurHoaro\RssExtender\Storage\SelectorStore;
use PHPUnit\Framework\TestCase;

class SelectorStoreTest extends TestCase
{
    protected string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/selectors-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testMissingFileHasNoSelectors(): void
    {
        $this->assertNull((new SelectorStore($this->path))->get('https://blog.example/feed'));
    }

    public function testSetGetDelete(): void
    {
        $store = new SelectorStore($this->path);
        $store->set('https://a.example/feed', '.entry-content');
        $store->set('https://b.example/feed', 'article');
        $store->set('https://a.example/feed', 'main');

        $this->assertSame('main', $store->get('https://a.example/feed'));
        $this->assertSame('article', $store->get('https://b.example/feed'));

        $this->assertTrue($store->delete('https://a.example/feed'));
        $this->assertFalse($store->delete('https://a.example/feed'));
        $this->assertNull($store->get('https://a.example/feed'));
        $this->assertSame(['https://b.example/feed' => 'article'], json_decode(file_get_contents($this->path), true));
    }

    public function testIgnoresNonStringSelectors(): void
    {
        file_put_contents($this->path, json_encode(['https://a.example/feed' => null]));

        $this->assertNull((new SelectorStore($this->path))->get('https://a.example/feed'));
    }
}
