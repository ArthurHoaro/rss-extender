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
    /** @var Feed */
    protected $feed;

    /** @var FeedIo */
    protected $feedIo;

    /** @var CacheInterface */
    protected $cache;

    /** @var string */
    protected $feedUrl;

    /** @var string */
    protected $selector;

    /** @var string */
    protected $hash;

    /**
     * FeedProcessor constructor.
     *
     * @param string         $feedUrl
     * @param string         $selector
     * @param CacheInterface $cache
     */
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
        $this->feed->setLink($feedData->getFeed()->getLink());
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
            $retrieve = function () use ($client, $item) {
                return $this->retrieveItem($client, $item);
            };

            /** @var CachedItem $newItem */
            $newItem = $this->cache->get($cacheKey, function () use ($retrieve) { return $retrieve(); });

            if ($cachedEnabled || $newItem->getCachedAt() < $item->getLastModified()) {
                $this->cache->delete($cacheKey);
                /** @var CachedItem $newItem */
                $newItem = $this->cache->get($cacheKey, function () use ($retrieve) { return $retrieve(); });
            }

            $this->feed->add($newItem);
        }
    }

    protected function retrieveItem(Client $client, ItemInterface $item): CachedItem
    {
        $itemLink = FeedUtils::getFullItemUrl($this->feedUrl, $item->getLink());
        $article = $client->request('GET', $itemLink);

        $dom = new Dom();
        $dom->load((string) $article->getBody());
        $content = $dom->find($this->selector)[0];
        $newItem = new CachedItem();
        $newItem->setDescription($content);
        unset($dom);
        $newItem->setPublicId($item->getLink());
        $newItem->setLastModified($item->getLastModified());
        $newItem->setLink($itemLink);
        $newItem->setTitle($item->getTitle());
        $newItem->setAuthor($item->getAuthor());
        $newItem->setCachedAt(new DateTime());

        return $newItem;
    }
}
