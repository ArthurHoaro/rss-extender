<?php

namespace ArthurHoaro\RssExtender\Http;

use Psr\Http\Message\UriInterface;

/**
 * Decides which URLs the server may fetch: http(s) only, never a non-public address,
 * and nothing matching the blocklist (host names, including their subdomains, or IPv4 ranges).
 */
class UrlGuard
{
    /** @var string[] */
    protected array $hosts = [];

    /** @var string[] */
    protected array $ranges = [];

    /** @var callable(string): (string[]|false) */
    protected $resolver;

    /**
     * @param string[] $blocklist
     */
    public function __construct(array $blocklist = [], ?callable $resolver = null)
    {
        foreach ($blocklist as $entry) {
            $entry = rtrim(strtolower(trim($entry)), '.');
            if (preg_match('@^[0-9.]+(/[0-9]{1,2})?$@', $entry)) {
                $this->ranges[] = $entry;
            } elseif ($entry !== '') {
                $this->hosts[] = $entry;
            }
        }
        $this->resolver = $resolver ?? gethostbynamel(...);
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            return new self();
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

        return new self(array_map(fn (string $line) => preg_replace('/#.*/', '', $line), $lines));
    }

    /**
     * @return string|null IPv4 address to connect to, or null when the host doesn't resolve.
     *
     * @throws BlockedUrlException
     */
    public function resolve(UriInterface $uri): ?string
    {
        if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true)) {
            throw new BlockedUrlException('Only http and https URLs can be fetched');
        }

        $host = rtrim(strtolower($uri->getHost()), '.');
        foreach ($this->hosts as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
                throw new BlockedUrlException($host . ' is blocked');
            }
        }

        $ips = ($this->resolver)($host) ?: [];
        if ($ips === []) {
            return null;
        }

        $publicIpv4 = FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE;
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, $publicIpv4) === false) {
                throw new BlockedUrlException($host . ' resolves to a non-public address');
            }
            foreach ($this->ranges as $range) {
                if (static::inRange($ip, $range)) {
                    throw new BlockedUrlException($host . ' resolves to a blocked address');
                }
            }
        }

        return $ips[0];
    }

    protected static function inRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $range, 2), 2, '32');
        $mask = -1 << (32 - (int) $bits);

        return (ip2long($ip) & $mask) === ((int) ip2long($subnet) & $mask);
    }
}
