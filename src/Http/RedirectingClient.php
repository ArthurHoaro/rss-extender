<?php

namespace ArthurHoaro\RssExtender\Http;

use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client for feed-io that lets Guzzle follow redirects: feed-io 6.5 drops trailing slashes
 * when it follows them itself, so /feed -> /feed/ loops until it gives up.
 */
class RedirectingClient implements ClientInterface
{
    public function __construct(protected Client $client)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->send($request, ['allow_redirects' => true, 'http_errors' => false]);
    }
}
