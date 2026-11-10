<?php

namespace Tests\Functional;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

class AppTest extends TestCase
{
    protected string $dataPath;

    protected App $app;

    protected function setUp(): void
    {
        $this->dataPath = sys_get_temp_dir() . '/selectors-' . uniqid() . '.json';
        $settings = require __DIR__ . '/../../src/settings.php';
        $settings['data_path'] = $this->dataPath;
        $settings['blocklist_path'] = '/nonexistent';
        $settings['cache_path'] = sys_get_temp_dir() . '/rssx-cache';
        $settings['logger']['path'] = 'php://memory';
        $this->app = (require __DIR__ . '/../../src/app.php')($settings);
    }

    protected function tearDown(): void
    {
        @unlink($this->dataPath);
    }

    protected function request(string $method, string $uri, ?array $body = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $this->app->handle($request);
    }

    public function testHomepage(): void
    {
        $response = $this->request('GET', '/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('What feed do you want to extend?', (string) $response->getBody());
    }

    public function testUnknownFeedShowsTheSelectorForm(): void
    {
        $response = $this->request('GET', '/?feed=' . urlencode('https://blog.example/feed'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('name="selector"', (string) $response->getBody());
    }

    public function testSaveRedirectsToTheExtendedFeed(): void
    {
        $response = $this->request('POST', '/', ['feed' => ' https://blog.example/feed?a=1&b=2 ', 'selector' => 'article']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/?feed=https%3A%2F%2Fblog.example%2Ffeed%3Fa%3D1%26b%3D2', $response->getHeaderLine('Location'));
        $this->assertSame(
            ['https://blog.example/feed?a=1&b=2' => 'article'],
            json_decode(file_get_contents($this->dataPath), true)
        );
    }

    public function testSaveStoresTheAllMatchesOption(): void
    {
        $this->request('POST', '/', ['feed' => 'https://blog.example/feed', 'selector' => 'article > p', 'all' => '1']);

        $this->assertSame(
            ['https://blog.example/feed' => ['selector' => 'article > p', 'all' => true]],
            json_decode(file_get_contents($this->dataPath), true)
        );
    }

    public function testSaveRejectsInvalidFeedUrls(): void
    {
        $response = $this->request('POST', '/', ["feed" => "RSS feed URL' AND SLEEP(5)-- x", 'selector' => 'article']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFileDoesNotExist($this->dataPath);
    }

    public function testSaveRejectsInvalidSelectors(): void
    {
        $response = $this->request('POST', '/', ['feed' => 'https://blog.example/feed', 'selector' => 'div[']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('not valid', (string) $response->getBody());
        $this->assertFileDoesNotExist($this->dataPath);
    }

    public function testDeleteRemovesTheSelector(): void
    {
        file_put_contents($this->dataPath, json_encode(['https://blog.example/feed' => 'article']));

        $response = $this->request('GET', '/?delete=1&feed=' . urlencode('https://blog.example/feed'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], json_decode(file_get_contents($this->dataPath), true));
    }

    public function testInternalFeedUrlsAreRefused(): void
    {
        file_put_contents($this->dataPath, json_encode(['http://127.0.0.1/feed' => 'article']));

        $response = $this->request('GET', '/?feed=' . urlencode('http://127.0.0.1/feed'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('non-public address', (string) $response->getBody());
    }
}
