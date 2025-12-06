<?php

namespace Datlechin\LinkPreview\Api\Controllers;

use Datlechin\LinkPreview\Services\LinkPreviewService;
use GuzzleHttp\Promise\Utils;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

class BatchLinkPreviewController implements RequestHandlerInterface
{
    public function __construct(protected LinkPreviewService $service) {}

    public function handle(Request $request): Response
    {
        $ipAddress = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        
        // Check rate limit
        if ($this->service->isRateLimited($ipAddress)) {
            return new JsonResponse([
                'error' => 'Rate limit exceeded. Please try again later.',
            ], 429);
        }
        
        // Increment rate limit counter
        $this->service->incrementRateLimit($ipAddress, true);
        
        try {
            $urls = $request->getParsedBody()['urls'] ?? [];
            $result = $this->processUrls($urls);

            return new JsonResponse($result);
        } catch (Throwable $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function processUrls(array $urls): array
    {
        $result = [];
        $urlsToFetch = [];

        foreach ($urls as $url) {
            $processResult = $this->preProcessUrl($url);
            if (isset($processResult['data'])) {
                $result[$url] = $processResult['data'];
            } elseif (isset($processResult['fetch'])) {
                $urlsToFetch[$url] = $processResult['fetch'];
            } else {
                $result[$url] = $processResult['error'];
            }
        }

        if (empty($urlsToFetch)) {
            return $result;
        }

        $fetchResults = $this->fetchUrls($urlsToFetch);

        foreach ($urlsToFetch as $originalUrl => $normalizedUrl) {
            $result[$originalUrl] = $fetchResults[$originalUrl] ?? [
                'error' => 'Failed to fetch preview',
            ];
        }

        return $result;
    }

    protected function preProcessUrl(string $url): array
    {
        if (!$this->service->isValidUrl($url)) {
            return [
                'error' => $this->service->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached'),
            ];
        }

        $normalizedUrl = $this->service->normalizeUrl($url);

        if (!$this->service->isUrlAllowed($normalizedUrl)) {
            return [
                'error' => $this->service->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached'),
            ];
        }

        $cachedData = $this->service->getCachedData($normalizedUrl);
        if ($cachedData) {
            return ['data' => $cachedData];
        }

        return ['fetch' => $normalizedUrl];
    }

    protected function fetchUrls(array $urlsToFetch): array
    {
        $results = [];
        $normalizedUrls = [];
        $originalUrls = array_keys($urlsToFetch);
        
        // Concurrent request limit (prevent server overload)
        $concurrentLimit = 5;
        
        // Process URLs in batches with concurrent limit
        $urlBatches = array_chunk($originalUrls, $concurrentLimit);
        
        foreach ($urlBatches as $urlBatch) {
            $promises = [];
            
            // Create promises for current batch
            foreach ($urlBatch as $originalUrl) {
                $normalizedUrl = $urlsToFetch[$originalUrl];
                $normalizedUrls[$originalUrl] = $normalizedUrl;
                
                try {
                    $promises[$originalUrl] = $this->service->getClient()->getAsync($originalUrl);
                } catch (Throwable $e) {
                    $this->service->logger->error('Failed to create async request for URL: ' . $originalUrl, ['exception' => $e]);
                    $results[$originalUrl] = [
                        'error' => $this->service->translator->trans('datlechin-link-preview.forum.failed_to_create_request'),
                    ];
                }
            }
            
            if (empty($promises)) {
                continue;
            }
            
            // Wait for current batch to complete
            $responses = Utils::settle($promises)->wait();
            
            // Process responses for current batch
            foreach ($promises as $originalUrl => $promise) {
                try {
                    $response = $responses[$originalUrl] ?? null;

                    if (!$response || $response['state'] !== 'fulfilled') {
                        $errorReason = $response['reason'] ?? 'Unknown error';
                        $errorMessage = 'Failed to fetch preview';
                        
                        if ($errorReason instanceof ConnectException) {
                            $errorMessage = $this->service->translator->trans('datlechin-link-preview.forum.site_cannot_be_reached');
                        } elseif ($errorReason instanceof TimeoutException) {
                            $errorMessage = $this->service->translator->trans('datlechin-link-preview.forum.request_timed_out');
                        } elseif ($errorReason instanceof ClientException) {
                            $errorMessage = $this->service->translator->trans('datlechin-link-preview.forum.client_error');
                        } elseif ($errorReason instanceof ServerException) {
                            $errorMessage = $this->service->translator->trans('datlechin-link-preview.forum.server_error');
                        } elseif ($errorReason instanceof Throwable) {
                            $errorMessage = $this->service->translator->trans('datlechin-link-preview.forum.unknown_error');
                        }
                        
                        $this->service->logger->error('Async request failed for URL: ' . $originalUrl, ['reason' => $errorReason]);
                        $results[$originalUrl] = [
                            'error' => $errorMessage,
                        ];
                        continue;
                    }

                    $html = $response['value']->getBody()->getContents();
                    $data = $this->service->parseHtml($html, $originalUrl);

                    if (isset($normalizedUrls[$originalUrl])) {
                        $this->service->cacheData($normalizedUrls[$originalUrl], $data);
                    }

                    $results[$originalUrl] = $data;
                } catch (Throwable $e) {
                    $this->service->logger->error('Error processing response for URL: ' . $originalUrl, ['exception' => $e]);
                    $results[$originalUrl] = [
                        'error' => $this->service->translator->trans('datlechin-link-preview.forum.unknown_error'),
                    ];
                }
            }
        }

        return $results;
    }
}
