<?php

namespace ArthurHoaro\RssExtender\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;

class HttpClientFactory
{
    public static function create(UrlGuard $guard, ?callable $handler = null): Client
    {
        $stack = HandlerStack::create($handler);
        // Pushed last, so it runs closest to the transport and sees every redirect hop.
        $stack->push(static::guardMiddleware($guard), 'url_guard');

        return new Client([
            'handler' => $stack,
            'connect_timeout' => 10,
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'RSS-Extender (+https://github.com/ArthurHoaro/rss-extender)',
            ],
        ]);
    }

    protected static function guardMiddleware(UrlGuard $guard): callable
    {
        return function (callable $handler) use ($guard): callable {
            return function (RequestInterface $request, array $options) use ($handler, $guard) {
                $uri = $request->getUri();
                try {
                    $ip = $guard->resolve($uri);
                } catch (BlockedUrlException $e) {
                    return Create::rejectionFor($e);
                }
                if ($ip === null) {
                    $error = new ConnectException('Could not resolve ' . $uri->getHost(), $request);

                    return Create::rejectionFor($error);
                }

                // Pin curl to the address that was checked, so a second DNS answer can't swap it.
                $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
                $pin = sprintf('%s:%d:%s', $uri->getHost(), $port, $ip);
                $options['curl'][CURLOPT_RESOLVE] = [$pin];

                return $handler($request, $options);
            };
        };
    }
}
