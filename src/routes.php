<?php

use ArthurHoaro\RssExtender\Bean\CachedItem;
use FeedIo\Factory;
use FeedIo\Feed;
use FeedIo\Feed\Item;
use FeedIo\Feed\ItemInterface;
use GuzzleHttp\Client;
use PHPHtmlParser\Dom;
use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface as CacheItemInterface;

return function (App $app) {
    $container = $app->getContainer();

    $app->get('/', function (Request $request, Response $response, array $args) use ($container) {
        $selectors = [];
        $feed = $request->getQueryParam('feed');
        if (file_exists($container->get('settings')['data_path'])) {
            $selectors = json_decode(file_get_contents($container->get('settings')['data_path']), true);
        }

        if (! empty($feed) && ! empty($request->getQueryParam('delete'))) {
            if (isset($selectors[$feed])) {
                unset($selectors[$feed]);
                file_put_contents($container->get('settings')['data_path'], json_encode($selectors));

                $response = $response->withRedirect('/?nocache=1feed='. $feed);
                return $response;
            }
        }

        if (! empty($feed) && isset($selectors[$feed])) {
            $noCache = $request->getQueryParam('nocache');
            $cache = new FilesystemAdapter('', 0, $container->get('settings')['cache_path']);
            $client = new Client();

            // retrieve shortened feed
            $feedIo = Factory::create()->getFeedIo();
            $feedData = $feedIo->read($feed);

            $newFeed = new Feed();
            $newFeed->setTitle($feedData->getFeed()->getTitle());
            $newFeed->setLink($feedData->getFeed()->getLink());
            $newFeed->setDescription($feedData->getFeed()->getDescription());
            $newFeed->setLanguage($feedData->getFeed()->getLanguage());
            $newFeed->setLastModified($feedData->getFeed()->getLastModified());
            $newFeed->setPublicId($feedData->getFeed()->getPublicId());
            $newFeed->setUrl($feedData->getFeed()->getUrl());

            $hash = md5($feedData->getFeed()->getLink());

            /** @var ItemInterface $item */
            foreach ($feedData->getFeed() as $item) {
                $id = ! empty($item->getPublicId()) ? md5($item->getPublicId()) : md5($item->getLink());
                $cacheKey = $hash .'.'. $id;
                $retrieve = function () use ($client, $item, $selectors, $feed) {
                    $article = $client->request('GET', $item->getLink());

                    $dom = new Dom();
                    $dom->load((string) $article->getBody());
                    $content = $dom->find($selectors[$feed])[0];
                    $newItem = new CachedItem();
                    $newItem->setDescription($content);
                    unset($dom);
                    $newItem->setPublicId($item->getPublicId());
                    $newItem->setLastModified($item->getLastModified());
                    $newItem->setLink($item->getLink());
                    $newItem->setTitle($item->getTitle());
                    $newItem->setAuthor($item->getAuthor());
                    $newItem->setCachedAt(new DateTime());

                    return $newItem;
                };

                /** @var CachedItem $newItem */
                $newItem = $cache->get($cacheKey, function () use ($retrieve) { return $retrieve(); });

                if ($noCache || $newItem->getCachedAt() < $item->getLastModified()) {
                    $cache->delete($cacheKey);
                    /** @var CachedItem $newItem */
                    $newItem = $cache->get($cacheKey, function () use ($retrieve) { return $retrieve(); });
                }

                $newFeed->add($newItem);
            }

            return $feedIo->getPsrResponse($newFeed, 'atom');
        }

        // Render index view
        return $container->get('renderer')->render($response, 'index.phtml', ['feed' => $feed]);
    });

    $app->post('/', function (Request $request, Response $response, array $args) use ($container) {
        $feed = $request->getParam('feed');
        $selector = $request->getParam('selector');
        $container->get('logger')->info('Saving '. $selector);

        if (file_exists($container->get('settings')['data_path'])) {
            $selectors = json_decode(file_get_contents($container->get('settings')['data_path']), true);
        }
        $selectors[$feed] = $selector;
        file_put_contents($container->get('settings')['data_path'], json_encode($selectors));

        $response = $response->withRedirect('/?feed='. $feed);
        return $response;
    });
};
