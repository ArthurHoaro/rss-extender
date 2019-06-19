<?php

namespace ArthurHoaro\RssExtender\Utils;

/**
 * Class UrlUtils
 *
 * Util class for various operations on Feeds.
 */
class FeedUtils
{
    /**
     * Extract the domains from an URL.
     *
     * @param string $url Given URL.
     *
     * @return string Extracted domains, lowercase.
     */
    public static function getDomain(string $url): string
    {
        if (! parse_url($url, PHP_URL_SCHEME)) {
            $url = 'http://' . $url;
        }
        return strtolower(parse_url($url, PHP_URL_HOST));
    }

    public static function isRelativeUrl(string $url): bool
    {
        return substr($url, 0, 4) !== 'http';
    }

    public static function getFullItemUrl($feedUrl, $itemUrl): string
    {
        if (self::isRelativeUrl($itemUrl)) {
            return rtrim(self::getDomain($feedUrl), '/') .'/'. ltrim($itemUrl);
        }
        return $itemUrl;
    }
}
