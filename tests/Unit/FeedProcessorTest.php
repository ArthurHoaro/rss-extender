<?php

namespace Tests\Unit;

use ArthurHoaro\RssExtender\Http\BlockedUrlException;
use ArthurHoaro\RssExtender\Http\HttpClientFactory;
use ArthurHoaro\RssExtender\Http\UrlGuard;
use ArthurHoaro\RssExtender\Processor\FeedProcessor;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class FeedProcessorTest extends TestCase
{
    protected const FEED = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Example blog</title>
    <link>https://blog.example/</link>
    <description>Excerpts only</description>
    <item>
      <title>First</title>
      <link>https://blog.example/first?a=1&amp;b=2</link>
      <pubDate>Sat, 07 Nov 2026 10:00:00 +0000</pubDate>
      <description>Excerpt</description>
    </item>
    <item>
      <title>Internal</title>
      <link>http://192.168.0.1/admin</link>
      <pubDate>Sat, 07 Nov 2026 09:00:00 +0000</pubDate>
    </item>
  </channel>
</rss>
XML;

    protected const ARTICLE = '<html><body><nav>menu</nav>'
        . '<div class="entry-content"><p>Full text</p><img src="/img.png"></div></body></html>';

    public function testExtractsArticlesAndBlocksInternalLinks(): void
    {
        $requested = [];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/rss+xml'], static::FEED),
            new Response(200, ['Content-Type' => 'text/html'], static::ARTICLE),
        ]);
        $handler = function (RequestInterface $request, array $options) use ($mock, &$requested) {
            $requested[] = (string) $request->getUri();

            return $mock($request, $options);
        };
        $resolver = fn (string $host) => $host === 'blog.example' ? ['93.184.215.14'] : [$host];
        $client = HttpClientFactory::create(new UrlGuard([], $resolver), $handler);

        $processor = new FeedProcessor('https://blog.example/rss', '.entry-content', new ArrayAdapter(), $client);
        $processor->read();
        $atom = (string) $processor->getResponse()->getBody();

        $this->assertSame(['https://blog.example/rss', 'https://blog.example/first?a=1&b=2'], $requested);
        $this->assertStringContainsString(
            '<content type="html">&lt;div class="entry-content"&gt;&lt;p&gt;Full text',
            $atom
        );
        $this->assertStringContainsString('https://blog.example/img.png', $atom);
        $this->assertStringNotContainsString('menu', $atom);
        $this->assertStringContainsString('could not be downloaded', $atom);
        $this->assertStringContainsString('<id>https://blog.example/first?a=1&amp;b=2</id>', $atom);
    }

    public function testFollowsFeedRedirectsWithTrailingSlashes(): void
    {
        $requested = [];
        $mock = new MockHandler([
            new Response(301, ['Location' => '/feed/']),
            new Response(301, ['Location' => '/rss/']),
            new Response(200, [], static::FEED),
            new Response(200, [], static::ARTICLE),
        ]);
        $handler = function (RequestInterface $request, array $options) use ($mock, &$requested) {
            $requested[] = (string) $request->getUri();

            return $mock($request, $options);
        };
        $resolver = fn (string $host) => $host === 'blog.example' ? ['93.184.215.14'] : [$host];
        $client = HttpClientFactory::create(new UrlGuard([], $resolver), $handler);

        $processor = new FeedProcessor('https://blog.example/feed', 'article', new ArrayAdapter(), $client);
        $processor->read();

        $this->assertSame(
            ['https://blog.example/feed', 'https://blog.example/feed/', 'https://blog.example/rss/'],
            array_slice($requested, 0, 3)
        );
    }

    public function testBlocksRedirectsToInternalAddresses(): void
    {
        $mock = new MockHandler([new Response(302, ['Location' => 'http://127.0.0.1/feed'])]);
        $resolver = fn (string $host) => $host === 'blog.example' ? ['93.184.215.14'] : [$host];
        $client = HttpClientFactory::create(new UrlGuard([], $resolver), $mock);

        $processor = new FeedProcessor('https://blog.example/feed', 'article', new ArrayAdapter(), $client);

        try {
            $processor->read();
            $this->fail('The redirect should have been blocked');
        } catch (\Throwable $e) {
            $this->assertNotNull(BlockedUrlException::findIn($e));
        }
    }

    public function testServesCachedArticlesWithoutRefetching(): void
    {
        $mock = new MockHandler([
            new Response(200, [], static::FEED),
            new Response(200, [], static::ARTICLE),
            new Response(200, [], static::FEED),
        ]);
        $resolver = fn (string $host) => $host === 'blog.example' ? ['93.184.215.14'] : [$host];
        $client = HttpClientFactory::create(new UrlGuard([], $resolver), $mock);
        $cache = new ArrayAdapter();

        (new FeedProcessor('https://blog.example/rss', '.entry-content', $cache, $client))->read();
        $processor = new FeedProcessor('https://blog.example/rss', '.entry-content', $cache, $client);
        $processor->read();

        $this->assertSame(0, $mock->count());
        $this->assertStringContainsString('Full text', (string) $processor->getResponse()->getBody());
    }

    public function testChangingTheSelectorRefetchesArticles(): void
    {
        $mock = new MockHandler([
            new Response(200, [], static::FEED),
            new Response(200, [], static::ARTICLE),
            new Response(200, [], static::FEED),
            new Response(200, [], static::ARTICLE),
        ]);
        $resolver = fn (string $host) => $host === 'blog.example' ? ['93.184.215.14'] : [$host];
        $client = HttpClientFactory::create(new UrlGuard([], $resolver), $mock);
        $cache = new ArrayAdapter();

        $stale = new FeedProcessor('https://blog.example/rss', '.gone', $cache, $client);
        $stale->read();
        $processor = new FeedProcessor('https://blog.example/rss', '.entry-content', $cache, $client);
        $processor->read();

        $this->assertStringContainsString('No content', (string) $stale->getResponse()->getBody());
        $this->assertStringContainsString('Full text', (string) $processor->getResponse()->getBody());
    }
}
