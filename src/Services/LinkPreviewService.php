<?php

namespace Datlechin\LinkPreview\Services;

use Flarum\Log\Logger;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TimeoutException;
use Illuminate\Contracts\Cache\Store;
use Psr\Log\LogLevel;
use Spekulatius\PHPScraper\PHPScraper;
use Symfony\Contracts\Translation\TranslatorInterface;

class LinkPreviewService
{
    protected array $blacklist = [];

    protected array $whitelist = [];

    protected int $cacheTime;

    protected Client $httpClient;

    public function __construct(
        protected PHPScraper $web,
        protected TranslatorInterface $translator,
        SettingsRepositoryInterface $settings,
        protected Store $cache,
        protected Logger $logger,
    ) {
        $this->setupCacheTime($settings);
        $this->setupLists($settings);
        $this->setupHttpClient();
    }

    protected function setupCacheTime(SettingsRepositoryInterface $settings): void
    {
        $cacheTime = $settings->get('datlechin-link-preview.cache_time');
        $this->cacheTime = is_numeric($cacheTime) ? intval($cacheTime) : 60;
    }

    protected function setupLists(SettingsRepositoryInterface $settings): void
    {
        $this->blacklist = $this->getMultiDimensionalSetting($settings, 'datlechin-link-preview.blacklist');
        $this->whitelist = $this->getMultiDimensionalSetting($settings, 'datlechin-link-preview.whitelist');
    }

    protected function setupHttpClient(): void
    {
        $this->httpClient = new Client([
            'timeout' => 5,
            'connect_timeout' => 5,
        ]);
    }

    public function getClient(): Client
    {
        return $this->httpClient;
    }

    public function parseHtml(string $html, string $url): array
    {
        $web = clone $this->web;
        $web->setContent($url, $html);

        return [
            'site_name' => $web->openGraph['og:site_name'] ?? $web->twitterCard['twitter:site'] ?? null,
            'title' => $web->title ?? $web->openGraph['og:title'] ?? $web->twitterCard['twitter:title'] ?? null,
            'description' => $web->description ?? $web->openGraph['og:description'] ?? $web->twitterCard['twitter:description'] ?? null,
            'image' => $web->image ?? $web->openGraph['og:image'] ?? $web->twitterCard['twitter:image'] ?? null,
            'accessed' => time(),
        ];
    }

    public function isValidUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $domain = parse_url($url, PHP_URL_HOST);
        return gethostbyname($domain) !== $domain;
    }

    public function normalizeUrl(string $url): string
    {
        return preg_replace('/^https?:\/\/(.+?)\/?$/i', '$1', $url);
    }

    public function isUrlAllowed(string $normalizedUrl): bool
    {
        return (!$this->whitelist || $this->inList($normalizedUrl, $this->whitelist))
            && (!$this->blacklist || !$this->inList($normalizedUrl, $this->blacklist));
    }

    private const CACHE_VERSION = 'v1';

    public function getCacheKey(string $normalizedUrl): string
    {
        return sprintf('datlechin-link-preview:%s:%s', self::CACHE_VERSION, md5($normalizedUrl));
    }

    public function getCachedData(string $normalizedUrl)
    {
        if (!$this->cacheTime) {
            return null;
        }
        
        $cachedData = $this->cache->get($this->getCacheKey($normalizedUrl));
        
        // Validate cached data structure
        if (is_array($cachedData) && isset($cachedData['data'], $cachedData['timestamp'])) {
            return $cachedData['data'];
        }
        
        // Migrate old cache format or invalid data
        if (is_array($cachedData)) {
            $this->cacheData($normalizedUrl, $cachedData);
            return $cachedData;
        }
        
        return null;
    }

    public function cacheData(string $normalizedUrl, array $data): void
    {
        if (!$this->cacheTime) {
            return;
        }
        
        $cacheEntry = [
            'data' => $data,
            'timestamp' => time(),
            'version' => self::CACHE_VERSION,
        ];
        
        $this->cache->put($this->getCacheKey($normalizedUrl), $cacheEntry, $this->cacheTime * 60);
    }

    public function clearCache(string $normalizedUrl): bool
    {
        return $this->cache->forget($this->getCacheKey($normalizedUrl));
    }

    public function clearAllCache(): void
    {
        // For cache stores that support tags, we could use tags
        // For now, we use versioning to invalidate all cache
        // When we need to clear all cache, we can increment the version constant
        // This approach works with all cache drivers
        // Note: This method doesn't actually clear cache immediately, but makes old cache entries invalid
        // New requests will generate fresh cache entries with the new version
    }

    public function getErrorResponse(string $message): array
    {
        return [
            'error' => $this->translator->trans($message),
        ];
    }

    public function isRateLimited(string $ipAddress): bool
    {
        // Rate limiting settings
        $maxRequestsPerMinute = 60;
        $maxBatchRequestsPerMinute = 10;
        
        // Check single request rate limit
        $singleRequestKey = sprintf('datlechin-link-preview:rate-limit:single:%s', $ipAddress);
        $singleRequestCount = $this->cache->get($singleRequestKey, 0);
        
        if ($singleRequestCount >= $maxRequestsPerMinute) {
            return true;
        }
        
        // Check batch request rate limit
        $batchRequestKey = sprintf('datlechin-link-preview:rate-limit:batch:%s', $ipAddress);
        $batchRequestCount = $this->cache->get($batchRequestKey, 0);
        
        if ($batchRequestCount >= $maxBatchRequestsPerMinute) {
            return true;
        }
        
        return false;
    }

    public function incrementRateLimit(string $ipAddress, bool $isBatchRequest = false): void
    {
        if ($isBatchRequest) {
            $key = sprintf('datlechin-link-preview:rate-limit:batch:%s', $ipAddress);
        } else {
            $key = sprintf('datlechin-link-preview:rate-limit:single:%s', $ipAddress);
        }
        
        // Increment count with 1 minute expiration
        $currentCount = $this->cache->get($key, 0);
        $this->cache->put($key, $currentCount + 1, 60);
    }

    protected function getMultiDimensionalSetting(SettingsRepositoryInterface $settings, string $setting): array
    {
        $items = preg_split('/[,\\n]/', $settings->get($setting) ?? '') ?: [];
        return array_filter(array_map('trim', $items));
    }

    protected function inList(string $needle, array $haystack): bool
    {
        if (!$haystack) {
            return false;
        }

        if (in_array($needle, $haystack, true)) {
            return true;
        }

        foreach ($haystack as $item) {
            $quoted = strtr(preg_quote($item, '/'), [
                '\\*' => '.*',
                '\\?' => '.',
            ]);

            if (preg_match("/$quoted/i", $needle)) {
                return true;
            }
        }

        return false;
    }
}
