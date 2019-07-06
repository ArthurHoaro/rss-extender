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

    public static function replaceRelativeUrls(string $content, string $rootUrl): string
    {
        $rootUrl = rtrim($rootUrl, '/');
        $replace = [
            'a' => 'href',
            'img' => 'src',
        ];
        foreach ($replace as $tag => $attribute) {
            $content = preg_replace(
                '@(<'. $tag .'\s+[^>]*'. $attribute .'=["\'])/([^>])+?@',
                '$1'. $rootUrl .'/$2',
                $content
            );
        }
        return $content;
    }

    /**
     * Replace asset called with HTTP by HTTPS URLs.
     * HTTP assets won't be displayed on an HTTPS web page,
     * so if they're not available, the result will be the same.
     *
     * @param string $content
     *
     * @return string
     */
    public static function replaceHttpAssetProtocol(string $content): string
    {
        $replace = [
            'img' => 'src',
        ];
        foreach ($replace as $tag => $attribute) {
            $content = preg_replace(
                '@(<'. $tag .'\s+[^>]*'. $attribute .'=["\'])http://([^>])+?@',
                '$1https://$2',
                $content
            );
        }
        return $content;
    }
}
