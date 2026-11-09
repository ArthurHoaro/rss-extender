<?php

namespace ArthurHoaro\RssExtender\Processor;

use ArthurHoaro\RssExtender\Feed\HtmlAtom;
use ArthurHoaro\RssExtender\Http\BlockedUrlException;
use ArthurHoaro\RssExtender\Http\RedirectingClient;
use ArthurHoaro\RssExtender\Utils\FeedUtils;
use FeedIo\Adapter\Http\Client as FeedIoClient;
use FeedIo\Feed;
use FeedIo\Feed\Item;
use FeedIo\Feed\ItemInterface;
use FeedIo\FeedInterface;
use FeedIo\FeedIo;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\CssSelector\Exception\ExceptionInterface as CssSelectorException;
use Symfony\Component\DomCrawler\Crawler;

class FeedProcessor
{
    protected const ERROR_CONTENT = '<span style="color:red;">
        No content could be retrieved. There might be an issue with your CSS selector.
    </span>';

    protected const FETCH_ERROR_CONTENT = '<span style="color:red;">
        The article could not be downloaded.
    </span>';

    protected const CONCURRENCY = 5;

    protected Feed $feed;

    protected FeedIo $feedIo;

    protected string $rootUrl;

    protected string $hash;

    public function __construct(
        protected string $feedUrl,
        protected string $selector,
        protected CacheItemPoolInterface $cache,
        protected Client $client,
        protected LoggerInterface $logger = new NullLogger(),
    ) {
        $this->feedIo = new FeedIo(new FeedIoClient(new RedirectingClient($client)));
        $this->feedIo->addStandard('atom', new HtmlAtom($this->feedIo->getDateTimeBuilder()));
    }

    public function read(bool $cacheEnabled = true): void
    {
        $source = $this->feedIo->read($this->feedUrl)->getFeed();

        $this->feed = new Feed();
        $this->feed->setTitle($source->getTitle());
        $this->rootUrl = FeedUtils::extractRootUrl($this->feedUrl, $source->getLink());
        $this->feed->setLink($this->rootUrl);
        $this->feed->setDescription($source->getDescription());
        $this->feed->setLanguage($source->getLanguage());
        $this->feed->setLastModified($source->getLastModified());
        $this->feed->setPublicId($source->getPublicId());
        $this->feed->setUrl($source->getUrl());

        $this->hash = md5($this->feed->getLink() . "\n" . $this->selector);
        $this->processItems($source, $cacheEnabled);
    }

    public function getResponse(): ResponseInterface
    {
        return $this->feedIo->getPsrResponse($this->feed, 'atom')
            ->withHeader('Content-Type', 'application/atom+xml');
    }

    protected function processItems(FeedInterface $source, bool $cacheEnabled): void
    {
        $items = [];
        $contents = [];
        $toFetch = [];
        /** @var ItemInterface $item */
        foreach ($source as $item) {
            if (empty($item->getLink())) {
                continue;
            }

            $id = ! empty($item->getPublicId()) ? md5($item->getPublicId()) : md5($item->getLink());
            $key = $this->hash . '.' . $id;
            $items[$key] = $item;

            $cached = $this->cache->getItem($key)->get();
            $modifiedAt = $item->getLastModified()?->getTimestamp() ?? 0;
            if ($cacheEnabled && is_array($cached) && $cached['cachedAt'] >= $modifiedAt) {
                $contents[$key] = $cached['content'];
            } else {
                $toFetch[$key] = FeedUtils::getFullItemUrl($this->feedUrl, $item->getLink());
            }
        }

        $contents += $this->fetchContents($toFetch);

        foreach ($items as $key => $item) {
            $this->feed->add($this->buildItem($item, $contents[$key]));
        }
    }

    /**
     * @param array<string, string> $urls Article URLs by cache key.
     *
     * @return array<string, string> Extracted content by cache key.
     */
    protected function fetchContents(array $urls): array
    {
        $contents = [];
        $requests = function () use ($urls) {
            foreach ($urls as $key => $url) {
                yield $key => fn () => $this->client->getAsync($url);
            }
        };

        $pool = new Pool($this->client, $requests(), [
            'concurrency' => static::CONCURRENCY,
            'fulfilled' => function (ResponseInterface $response, $key) use ($urls, &$contents) {
                $content = $this->extractContent((string) $response->getBody(), $urls[$key]);
                $contents[$key] = $content;
                $cacheItem = $this->cache->getItem($key);
                $this->cache->save($cacheItem->set(['content' => $content, 'cachedAt' => time()]));
            },
            'rejected' => function ($reason, $key) use ($urls, &$contents) {
                $blocked = $reason instanceof \Throwable
                    ? BlockedUrlException::findIn($reason)
                    : null;
                if ($blocked !== null) {
                    $this->logger->warning('Blocked article fetch', [
                        'feed' => $this->feedUrl,
                        'url' => $urls[$key],
                        'reason' => $blocked->getMessage(),
                    ]);
                }
                $contents[$key] = static::FETCH_ERROR_CONTENT;
            },
        ]);
        $pool->promise()->wait();

        return $contents;
    }

    protected function buildItem(ItemInterface $item, string $content): Item
    {
        $newItem = new Item();
        $newItem->setContent($content);
        $newItem->setPublicId(escape($item->getLink()));
        $newItem->setLastModified($item->getLastModified());
        $newItem->setLink(FeedUtils::getFullItemUrl($this->feedUrl, $item->getLink()));
        $newItem->setTitle($item->getTitle());
        $newItem->setAuthor($item->getAuthor());

        return $newItem;
    }

    protected function extractContent(string $html, string $url): string
    {
        $pseudo = $this->extractPseudoClass($this->selector);
        try {
            $results = (new Crawler($html, $url))->filter($pseudo['selector']);
        } catch (CssSelectorException $e) {
            return static::ERROR_CONTENT;
        }
        $content = $this->applyPseudoClass($results, $pseudo);
        $content = FeedUtils::replaceRelativeUrls($content, $this->rootUrl);
        $content = FeedUtils::replaceHttpAssetProtocol($content);

        return $content;
    }

    protected function extractPseudoClass(string $selector): array
    {
        $supported = [
            'first\-child',
            'last\-child',
            'nth\-child',
        ];
        if (
            preg_match(
                '/(.*):(' . implode('|', $supported) . ')(?:\((\d+)\))?$/',
                $selector,
                $matches
            ) === 0
        ) {
            return ['selector' => $selector];
        }

        return [
            'selector' => $matches[1],
            'pseudoClass' => $matches[2],
            'nth' => (int) ($matches[3] ?? 1) - 1,
        ];
    }

    protected function applyPseudoClass(Crawler $results, array $pseudo): string
    {
        $index = match ($pseudo['pseudoClass'] ?? null) {
            'last-child' => $results->count() - 1,
            'nth-child' => $pseudo['nth'],
            default => 0,
        };

        if ($index < 0 || $index >= $results->count()) {
            return static::ERROR_CONTENT;
        }

        return $results->eq($index)->outerHtml();
    }
}
