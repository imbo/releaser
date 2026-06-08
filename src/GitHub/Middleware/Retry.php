<?php declare(strict_types=1);

namespace ImboReleaser\GitHub\Middleware;

use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class Retry
{
    private const int DEFAULT_MAX_RETRIES = 3;
    private const int DEFAULT_BASE_DELAY_MS = 1_000;
    private const int MAX_DELAY_MS = 60_000;

    public function __construct(private int $maxRetries = self::DEFAULT_MAX_RETRIES, private int $baseDelayMs = self::DEFAULT_BASE_DELAY_MS)
    {
    }

    public function decide(int $retries, RequestInterface $request, ?ResponseInterface $response, ?Throwable $exception): bool
    {
        if ($retries >= $this->maxRetries) {
            return false;
        }

        if ($exception instanceof ConnectException) {
            return true;
        }

        if (null === $response) {
            return false;
        }

        $status = $response->getStatusCode();
        if (429 === $status) {
            return true;
        }

        if (403 === $status) {
            if ($response->hasHeader('Retry-After')) {
                return true;
            }

            return '0' === $response->getHeaderLine('X-RateLimit-Remaining');
        }

        return $status >= 500 && $status <= 504;
    }

    public function delay(int $retries, ?ResponseInterface $response): int
    {
        if (null === $response) {
            return $this->backoff($retries);
        }

        $retryAfter = $response->getHeaderLine('Retry-After');
        if ('' !== $retryAfter && is_numeric($retryAfter)) {
            return (int) min((int) $retryAfter * 1_000, self::MAX_DELAY_MS);
        }

        $resetTimestamp = $response->getHeaderLine('X-RateLimit-Reset');
        if ('' !== $resetTimestamp && is_numeric($resetTimestamp)) {
            $waitMs = max(0, (int) $resetTimestamp - time()) * 1_000;

            return (int) min($waitMs, self::MAX_DELAY_MS);
        }

        return $this->backoff($retries);
    }

    private function backoff(int $retries): int
    {
        return (int) min($this->baseDelayMs * (2 ** $retries), self::MAX_DELAY_MS);
    }
}
