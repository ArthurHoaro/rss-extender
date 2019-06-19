<?php

use ArthurHoaro\RssExtender\Processor\FeedProcessor;
use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

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
            $cacheEnabled = empty($request->getQueryParam('nocache'));
            $cache = new FilesystemAdapter('', 0, $container->get('settings')['cache_path']);

            $feedProcessor = new FeedProcessor($feed, $selectors[$feed], $cache);
            $feedProcessor->read($cacheEnabled);
            $response = $feedProcessor->getResponse();
            return $response;
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
