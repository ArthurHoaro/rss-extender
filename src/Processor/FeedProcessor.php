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
use PHPHtmlParser\Dom;
use Psr\Http\Message\ResponseInterface;
use Symfony\Contracts\Cache\CacheInterface;

class FeedProcessor
{
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
        $article = $client->request('GET', $itemLink);

        $dom = new Dom();
        $dom->loadStr((string) $article->getBody());
        $content = $dom->find($this->selector)[0];
        unset($dom);
        $content = FeedUtils::replaceRelativeUrls($content, $this->rootUrl);
        $content = FeedUtils::replaceHttpAssetProtocol($content);
        $newItem = new CachedItem();
        $newItem->setDescription($content);
        $newItem->setPublicId($item->getLink());
        $newItem->setLastModified($item->getLastModified());
        $newItem->setLink($itemLink);
        $newItem->setTitle($item->getTitle());
        $newItem->setAuthor($item->getAuthor());
        $newItem->setCachedAt(new DateTime());

        return $newItem;
    }
}
