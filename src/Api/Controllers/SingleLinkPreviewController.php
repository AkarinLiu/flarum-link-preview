<?php

namespace Datlechin\LinkPreview\Api\Controllers;

use Datlechin\LinkPreview\Services\LinkPreviewService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

class SingleLinkPreviewController implements RequestHandlerInterface
{
    public function __construct(protected LinkPreviewService $service) {}

    public function handle(Request $request): Response
    {
        $url = $request->getQueryParams()['url'] ?? '';
        $ipAddress = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        
        // Check rate limit
        if ($this->service->isRateLimited($ipAddress)) {
            return new JsonResponse([
                'error' => 'Rate limit exceeded. Please try again later.',
            ], 429);
        }

        if (!$this->service->isValidUrl($url)) {
            return new JsonResponse(
                $this->service->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached'),
            );
        }

        $normalizedUrl = $this->service->normalizeUrl($url);

        if (!$this->service->isUrlAllowed($normalizedUrl)) {
            return new JsonResponse(
                $this->service->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached'),
            );
        }

        $cachedData = $this->service->getCachedData($normalizedUrl);
        if ($cachedData) {
            return new JsonResponse($cachedData);
        }

        // Increment rate limit counter
        $this->service->incrementRateLimit($ipAddress, false);

        try {
            $response = $this->service->getClient()->get($url);
            $html = $response->getBody()->getContents();
            $data = $this->service->parseHtml($html, $url);

            $this->service->cacheData($normalizedUrl, $data);

            return new JsonResponse($data);
        } catch (ConnectException $e) {
            $this->service->logger->error('Failed to connect to URL: ' . $url, ['exception' => $e]);
            return new JsonResponse(
                $this->service->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached'),
            );
        } catch (TimeoutException $e) {
            $this->service->logger->error('Request timed out for URL: ' . $url, ['exception' => $e]);
            return new JsonResponse(
                $this->service->getErrorResponse('datlechin-link-preview.forum.request_timed_out'),
            );
        } catch (ClientException $e) {
            $this->service->logger->error('Client error when fetching URL: ' . $url, ['exception' => $e]);
            return new JsonResponse(
                $this->service->getErrorResponse('datlechin-link-preview.forum.client_error'),
            );
        } catch (ServerException $e) {
            $this->service->logger->error('Server error when fetching URL: ' . $url, ['exception' => $e]);
            return new JsonResponse(
                $this->service->getErrorResponse('datlechin-link-preview.forum.server_error'),
            );
        } catch (Throwable $e) {
            $this->service->logger->error('Unknown error when fetching URL: ' . $url, ['exception' => $e]);
            return new JsonResponse([
                'error' => $this->service->translator->trans('datlechin-link-preview.forum.unknown_error'),
            ]);
        }
    }
}
