<?php

namespace Tests\Unit;

use ArthurHoaro\RssExtender\Http\BlockedUrlException;
use ArthurHoaro\RssExtender\Http\UrlGuard;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlGuardTest extends TestCase
{
    public static function nonPublicAddresses(): array
    {
        return [
            ['127.0.0.1'],
            ['10.1.2.3'],
            ['172.17.0.4'],
            ['192.168.0.194'],
            ['169.254.169.254'],
            ['100.64.0.1'],
            ['0.0.0.0'],
        ];
    }

    #[DataProvider('nonPublicAddresses')]
    public function testBlocksNonPublicAddresses(string $ip): void
    {
        $guard = new UrlGuard([], fn () => [$ip]);

        $this->expectException(BlockedUrlException::class);
        $guard->resolve(new Uri('http://example.com/feed'));
    }

    public function testBlocksWhenAnyResolvedAddressIsPrivate(): void
    {
        $guard = new UrlGuard([], fn () => ['93.184.215.14', '127.0.0.1']);

        $this->expectException(BlockedUrlException::class);
        $guard->resolve(new Uri('https://example.com/'));
    }

    public function testReturnsTheAddressToPinForPublicHosts(): void
    {
        $guard = new UrlGuard([], fn () => ['93.184.215.14']);

        $this->assertSame('93.184.215.14', $guard->resolve(new Uri('https://example.com/feed')));
    }

    public function testRejectsOtherSchemes(): void
    {
        $guard = new UrlGuard([], fn () => ['93.184.215.14']);

        $this->expectException(BlockedUrlException::class);
        $guard->resolve(new Uri('file:///etc/passwd'));
    }

    public function testReturnsNullForUnresolvableHosts(): void
    {
        $guard = new UrlGuard([], fn () => false);

        $this->assertNull($guard->resolve(new Uri('https://nowhere.invalid/')));
    }

    public function testBlocksListedHostsAndTheirSubdomains(): void
    {
        $guard = new UrlGuard(['Bad.example.'], fn () => ['93.184.215.14']);

        foreach (['https://bad.example/', 'https://www.bad.example./x'] as $url) {
            try {
                $guard->resolve(new Uri($url));
                $this->fail($url . ' should be blocked');
            } catch (BlockedUrlException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('93.184.215.14', $guard->resolve(new Uri('https://notbad.example/')));
    }

    public function testBlocksListedRanges(): void
    {
        $guard = new UrlGuard(['93.184.0.0/16', '1.1.1.1'], fn (string $host) => [
            'a.example' => ['93.184.215.14'],
            'b.example' => ['1.1.1.1'],
            'c.example' => ['93.185.0.1'],
        ][$host]);

        foreach (['https://a.example/', 'https://b.example/'] as $url) {
            try {
                $guard->resolve(new Uri($url));
                $this->fail($url . ' should be blocked');
            } catch (BlockedUrlException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('93.185.0.1', $guard->resolve(new Uri('https://c.example/')));
    }

    public function testReadsTheBlocklistFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'blocklist');
        file_put_contents($path, "# comment\n\nbad.example  # trailing comment\n93.184.0.0/16\n");

        $guard = UrlGuard::fromFile($path);
        unlink($path);

        $this->expectException(BlockedUrlException::class);
        $guard->resolve(new Uri('https://bad.example/'));
    }
}
