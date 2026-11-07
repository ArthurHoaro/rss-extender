<?php

namespace Tests\Unit;

use ArthurHoaro\RssExtender\Utils\FeedUtils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FeedUtilsTest extends TestCase
{
    public static function itemUrls(): array
    {
        return [
            ['https://blog.example/feed.php?rss', 'post/42', 'https://blog.example/post/42'],
            ['https://blog.example/feed.php?rss', '/post/42', 'https://blog.example/post/42'],
            ['http://blog.example/rss', '//cdn.example/post', 'http://cdn.example/post'],
            ['https://blog.example/rss', 'https://other.example/a', 'https://other.example/a'],
        ];
    }

    #[DataProvider('itemUrls')]
    public function testGetFullItemUrl(string $feedUrl, string $itemUrl, string $expected): void
    {
        $this->assertSame($expected, FeedUtils::getFullItemUrl($feedUrl, $itemUrl));
    }

    public static function rootUrls(): array
    {
        return [
            ['https://blog.example/rss', 'https://blog.example/', 'https://blog.example/'],
            ['https://blog.example/rss', null, 'https://blog.example'],
            ['https://blog.example/rss', '//blog.example', 'https://blog.example'],
            ['https://blog.example/rss', 'https://blog.example/feed/', 'https://blog.example'],
            ['https://blog.example/rss', 'https://blog.example/index.xml', 'https://blog.example'],
        ];
    }

    #[DataProvider('rootUrls')]
    public function testExtractRootUrl(string $feedUrl, ?string $link, string $expected): void
    {
        $this->assertSame($expected, FeedUtils::extractRootUrl($feedUrl, $link));
    }

    public function testReplaceRelativeUrls(): void
    {
        $this->assertSame(
            '<a href="https://blog.example/a">x</a><img src="https://blog.example/i.png">',
            FeedUtils::replaceRelativeUrls('<a href="/a">x</a><img src="/i.png">', 'https://blog.example/')
        );
    }

    public static function feedUrls(): array
    {
        return [
            ['https://blog.example/feed', true],
            ['http://jorislisas.example:4000/?action=display&bridge=XPathBridge', true],
            ['https://ng.indeed.example/rss?q=jobs in nigeria', true],
            ['https://www.bing.example/news/search?q=site:a.example OR site:b.example', true],
            ['RSS feed URL UNION ALL SELECT NULL,NULL-- abcd', false],
            ["(SELECT CHAR(113)+CHAR(118))", false],
            ['https://exa mple.com/feed', false],
            ["https://blog.example/feed\nX-Injected: 1", false],
            ['file:///etc/passwd', false],
            ['gopher://example.com/', false],
            ['//blog.example/feed', false],
            ['https://' . str_repeat('a', 2050) . '.example/', false],
        ];
    }

    #[DataProvider('feedUrls')]
    public function testIsValidFeedUrl(string $url, bool $valid): void
    {
        $this->assertSame($valid, FeedUtils::isValidFeedUrl($url));
    }
}
