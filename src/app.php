<?php

use ArthurHoaro\RssExtender\Controller\FeedController;
use ArthurHoaro\RssExtender\Http\HttpClientFactory;
use ArthurHoaro\RssExtender\Http\UrlGuard;
use ArthurHoaro\RssExtender\Storage\SelectorStore;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

return function (array $settings): App {
    $logSettings = $settings['logger'];
    $logger = new Logger($logSettings['name']);
    $logger->pushProcessor(new UidProcessor());
    $logger->pushHandler(new StreamHandler($logSettings['path'], $logSettings['level']));

    $controller = new FeedController(
        new PhpRenderer($settings['template_path']),
        new SelectorStore($settings['data_path']),
        new FilesystemAdapter('', 0, $settings['cache_path']),
        HttpClientFactory::create(UrlGuard::fromFile($settings['blocklist_path'])),
        $logger,
    );

    $app = AppFactory::create();
    $app->addErrorMiddleware($settings['displayErrorDetails'], true, true, $logger);
    $app->get('/', [$controller, 'index']);
    $app->post('/', [$controller, 'save']);

    return $app;
};
