<?php
return [
    'displayErrorDetails' => false,

    'template_path' => __DIR__ . '/../templates/',

    'data_path' => __DIR__ . '/../data/selectors.json',
    'blocklist_path' => __DIR__ . '/../data/blocklist.txt',
    'cache_path' => __DIR__ . '/../cache/',

    'logger' => [
        'name' => 'rss-extender',
        'path' => getenv('RSSX_LOG') ?: __DIR__ . '/../logs/app.log',
        'level' => \Monolog\Level::Info,
    ],
];
