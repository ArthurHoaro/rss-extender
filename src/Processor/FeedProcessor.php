<?php

namespace ArthurHoaro\RssExtender\Processor;

use ArthurHoaro\RssExtender\Bean\CachedItem;
use ArthurHoaro\RssExtender\Utils\FeedUtils;
use DateTime;
use FeedIo\Factory;
use FeedIo\Feed;
use FeedIo\Feed\ItemInterface;
use FeedIo\FeedIo;
use FeedIo\Reader\Result;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use stringEncode\Exception;
use Symfony\Contracts\Cache\CacheInterface;

class FeedProcessor
{
    protected const ERROR_CONTENT = '<span style="color:red;">
        No content could be retrieved. There might be an issue with your CSS selector.
    </span>';

    protected Feed $feed;

    protected FeedIo $feedIo;

    protected CacheInterface $cache;

    protected string $feedUrl;

    protected string $rootUrl;

    protected string $selector;

    protected string $hash;

    public function __construct(string $feedUrl, string $selector, CacheInterface $cache)
    {
        $this->feedUrl = $feedUrl;
        $this->selector = $selector;
        $this->cache = $cache;

        $this->feedIo = Factory::create()->getFeedIo();
    }

    public function read($cachedEnabled = true)
    {
        $feedData = $this->feedIo->read($this->feedUrl);

        $this->feed = new Feed();
        $this->feed->setTitle($feedData->getFeed()->getTitle());
        $this->rootUrl = $feedData->getFeed()->getLink() ?? '';
        if (empty($this->rootUrl)) {
            $this->rootUrl = 'https://'. FeedUtils::getDomain($this->feedUrl);
        }
        // Sometimes we may encounter relative protocol URL
        if (FeedUtils::isRelativeProtocol($this->rootUrl)) {
            $this->rootUrl = FeedUtils::getProtocol($this->feedUrl) . ':' . $this->rootUrl;
        }
        $this->feed->setLink($this->rootUrl);
        $this->feed->setDescription($feedData->getFeed()->getDescription());
        $this->feed->setLanguage($feedData->getFeed()->getLanguage());
        $this->feed->setLastModified($feedData->getFeed()->getLastModified());
        $this->feed->setPublicId($feedData->getFeed()->getPublicId());
        $this->feed->setUrl($feedData->getFeed()->getUrl());

        $this->hash = md5($this->feed->getLink());
        $this->processItems($feedData, $cachedEnabled);
    }

    public function getResponse(): ResponseInterface
    {
        $response = $this->feedIo->getPsrResponse($this->feed, 'atom');
        $response = $response->withHeader('Content-type', 'application/atom+xml');
        return $response;
    }

    protected function processItems(Result $feedData, bool $cachedEnabled)
    {
        $client = new Client();
        /** @var ItemInterface $item */
        foreach ($feedData->getFeed() as $item) {
            $id = ! empty($item->getPublicId()) ? md5($item->getPublicId()) : md5($item->getLink());
            $cacheKey = $this->hash .'.'. $id;
            $retrieve = fn () => $this->retrieveItem($client, $item);

            /** @var CachedItem $newItem */
            $newItem = $this->cache->get($cacheKey, fn () => $retrieve());

            if (! $cachedEnabled || $newItem->getCachedAt() < $item->getLastModified()) {
                $this->cache->delete($cacheKey);
                /** @var CachedItem $newItem */
                $newItem = $this->cache->get($cacheKey, fn () => $retrieve());
            }

            $this->feed->add($newItem);
        }
    }

    protected function retrieveItem(Client $client, ItemInterface $item): CachedItem
    {
        $itemLink = FeedUtils::getFullItemUrl($this->feedUrl, $item->getLink());

        $newItem = new CachedItem();
        $newItem->setDescription($this->retrieveDescription($client, $itemLink));
        $newItem->setPublicId(escape($item->getLink()));
        $newItem->setLastModified($item->getLastModified());
        $newItem->setLink($itemLink);
        $newItem->setTitle($item->getTitle());
        $newItem->setAuthor($item->getAuthor());
        $newItem->setCachedAt(new DateTime());

        return $newItem;
    }

    protected function retrieveDescription(Client $client, string $url): string
    {
        try {
            $article = $client->request('GET', $url);
        } catch (ClientException $e) {
            return static::ERROR_CONTENT;
        }

        $dom = new Dom();
        $dom->loadStr((string) $article->getBody());

        $pseudo = $this->extractPseudoClass($this->selector);
        $results = $dom->find($this->selector);
        unset($dom);
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

    protected function applyPseudoClass(?Dom\Node\Collection $results, array $pseudo)
    {
        if ($results === null || (!empty($pseudo['nth']) && !isset($results[$pseudo['nth']]))) {
            return static::ERROR_CONTENT;
        }

        if (empty($pseudo) || $pseudo['pseudoClass'] === 'first-child') {
            return (string) $results[0];
        }

        if ($pseudo['pseudoClass'] === 'last-child') {
            return (string) $results[count($results) - 1];
        }

        return (string) $results[$pseudo['nth'] ?? 0];
    }
}
