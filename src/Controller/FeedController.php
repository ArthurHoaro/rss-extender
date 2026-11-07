<?php

namespace ArthurHoaro\RssExtender\Controller;

use ArthurHoaro\RssExtender\Http\BlockedUrlException;
use ArthurHoaro\RssExtender\Processor\FeedProcessor;
use ArthurHoaro\RssExtender\Storage\SelectorStore;
use ArthurHoaro\RssExtender\Utils\FeedUtils;
use GuzzleHttp\Client;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Views\PhpRenderer;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\CssSelector\Exception\ExceptionInterface as CssSelectorException;

class FeedController
{
    public function __construct(
        protected PhpRenderer $renderer,
        protected SelectorStore $selectors,
        protected CacheItemPoolInterface $cache,
        protected Client $client,
        protected LoggerInterface $logger,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $feed = is_string($query['feed'] ?? null) && $query['feed'] !== '' ? $query['feed'] : null;

        if ($feed !== null && ! empty($query['delete']) && $this->selectors->delete($feed)) {
            return $this->redirect($response, ['nocache' => 1, 'feed' => $feed]);
        }

        $selector = $feed !== null ? $this->selectors->get($feed) : null;
        if ($selector === null) {
            return $this->renderer->render($response, 'index.phtml', ['feed' => $feed]);
        }

        $processor = new FeedProcessor(
            $feed,
            $selector,
            $this->cache,
            $this->client,
            $this->logger,
        );
        try {
            $processor->read(empty($query['nocache']));
        } catch (\Throwable $e) {
            $blocked = BlockedUrlException::findIn($e);
            if ($blocked !== null) {
                $reason = $blocked->getMessage();
                $this->logger->warning('Blocked feed', ['feed' => $feed, 'reason' => $reason]);

                return $this->text($response, 403, 'This feed URL is not allowed: ' . $reason);
            }

            $error = $e->getMessage();
            $this->logger->notice('Feed read failed', ['feed' => $feed, 'error' => $error]);

            return $this->text($response, 502, 'The feed could not be read: ' . $error);
        }

        return $processor->getResponse();
    }

    public function save(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $feed = is_string($body['feed'] ?? null) ? trim($body['feed']) : '';
        $selector = is_string($body['selector'] ?? null) ? trim($body['selector']) : '';

        if (! FeedUtils::isValidFeedUrl($feed)) {
            return $this->renderer->render($response->withStatus(400), 'index.phtml', [
                'error' => 'The feed must be an http or https URL.',
            ]);
        }

        if (! $this->isValidSelector($selector)) {
            return $this->renderer->render($response->withStatus(400), 'index.phtml', [
                'feed' => $feed,
                'error' => 'This CSS selector is not valid.',
            ]);
        }

        $this->selectors->set($feed, $selector);
        $this->logger->info('Saved selector', ['feed' => $feed, 'selector' => $selector]);

        return $this->redirect($response, ['feed' => $feed]);
    }

    protected function isValidSelector(string $selector): bool
    {
        if ($selector === '' || strlen($selector) > 1000) {
            return false;
        }

        try {
            (new CssSelectorConverter())->toXPath($selector);
        } catch (CssSelectorException $e) {
            return false;
        }

        return true;
    }

    protected function redirect(Response $response, array $query): Response
    {
        return $response->withStatus(302)->withHeader('Location', '/?' . http_build_query($query));
    }

    protected function text(Response $response, int $status, string $message): Response
    {
        $response->getBody()->write($message);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
