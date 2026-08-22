<?php

declare(strict_types=1);

namespace Switon\Search\Web;

use Switon\Core\Strings;
use Switon\Search\Exception;

use function array_keys;
use function html_entity_decode;
use function http_build_query;
use function is_int;
use function is_string;
use function ksort;
use function ltrim;
use function parse_str;
use function parse_url;
use function rawurldecode;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;

/** Normalize result URLs for cross-engine dedupe (no HTTP follow). */
class UrlNormalizer
{
    public static function key(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        $url = self::unwrapDuckDuckGo($url);

        $parts = parse_url($url);
        if ($parts === false) {
            return strtolower($url);
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '') {
            $path = '/';
        } elseif ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $params);
            foreach (array_keys($params) as $key) {
                $lk = strtolower((string) $key);
                if (str_starts_with($lk, 'utm_') || $lk === 'fbclid' || $lk === 'gclid') {
                    unset($params[$key]);
                }
            }
            if ($params !== []) {
                ksort($params);
                $query = '?' . http_build_query($params);
            }
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if ($scheme === '') {
            $scheme = 'https';
        }
        $port = '';
        if (isset($parts['port']) && !self::defaultPort($scheme, $parts['port'])) {
            $port = ':' . $parts['port'];
        }

        return $scheme . '://' . $host . $port . $path . $query;
    }

    /** Decode DDG `/l/?uddg=` redirect targets without following HTTP. */
    public static function unwrapDuckDuckGo(string $url): string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if ($host !== 'duckduckgo.com' && !str_ends_with($host, '.duckduckgo.com')) {
            return $url;
        }

        if ($path !== '/l/' && $path !== '/l') {
            return $url;
        }

        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        parse_str($query, $params);
        $uddg = $params['uddg'] ?? null;
        if (!is_string($uddg) || $uddg === '') {
            return $url;
        }

        return rawurldecode($uddg);
    }

    public static function collapseWhitespace(string $text): string
    {
        return Strings::squish($text);
    }

    /** `site` argument reduced to a host. A bad value raises. */
    public static function siteHost(string $site): string
    {
        $site = trim($site);
        if ($site === '' || str_contains($site, ' ')) {
            Exception::raise('site must be a domain like github.com.');
        }

        if (!str_contains($site, '://')) {
            $site = 'https://' . ltrim($site, '/');
        }

        $host = strtolower((string) (parse_url($site, PHP_URL_HOST) ?? ''));
        if ($host === '' || str_starts_with($host, '.') || !str_contains($host, '.')) {
            Exception::raise('site must be a domain like github.com.');
        }

        return $host;
    }

    /** Scheme and host of a URL, with a trailing slash. The URL itself when either is missing. */
    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        if (!is_string($scheme) || !is_string($host) || $host === '') {
            return $url;
        }

        $origin = $scheme . '://' . $host;
        $port = $parts['port'] ?? null;
        if (is_int($port) && !self::defaultPort($scheme, $port)) {
            $origin .= ':' . $port;
        }

        return $origin . '/';
    }

    protected static function defaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }
}
