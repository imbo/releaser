<?php declare(strict_types=1);

namespace ImboReleaser\GitHub\Middleware;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class Retry
{
    private const int DEFAULT_MAX_RETRIES = 3;
    private const int DEFAULT_BASE_DELAY_MS = 1_000;
    private const int MAX_DELAY_MS = 60_000;
    private const string HTTP_DATE_FORMAT = 'D, d M Y H:i:s \G\M\T';

    public function __construct(private int $maxRetries = self::DEFAULT_MAX_RETRIES, private int $baseDelayMs = self::DEFAULT_BASE_DELAY_MS)
    {
    }

    public function decide(int $retries, RequestInterface $request, ?ResponseInterface $response, mixed $exception): bool
    {
        if ('GET' !== $request->getMethod() || $retries >= $this->maxRetries) {
            return false;
        }

        if ($exception instanceof ConnectException) {
            return true;
        }

        if (null === $response) {
            return false;
        }

        if ($this->responseDelay($retries + 1, $response) > self::MAX_DELAY_MS) {
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

        $delay = $this->responseDelay($retries, $response);
        if ($delay > self::MAX_DELAY_MS) {
            throw new RuntimeException('Required retry delay exceeds one minute. Try again later.');
        }

        return (int) $delay;
    }

    /**
     * @see https://docs.github.com/en/rest/using-the-rest-api/best-practices-for-using-the-rest-api#handle-rate-limit-errors-appropriately
     */
    private function responseDelay(int $retries, ResponseInterface $response): float
    {
        $retryAfter = $response->getHeaderLine('Retry-After');
        if (1 === preg_match('/^[0-9]+$/D', $retryAfter)) {
            return (float) $retryAfter * 1_000;
        }

        $date = DateTimeImmutable::createFromFormat(self::HTTP_DATE_FORMAT, $retryAfter, new DateTimeZone('UTC'));
        if (false !== $date && $date->format(self::HTTP_DATE_FORMAT) === $retryAfter) {
            return max(0, $date->getTimestamp() - time()) * 1_000;
        }

        $resetTimestamp = $response->getHeaderLine('X-RateLimit-Reset');
        if ('0' === $response->getHeaderLine('X-RateLimit-Remaining') && 1 === preg_match('/^[0-9]+$/D', $resetTimestamp)) {
            return max(0, (float) $resetTimestamp - time()) * 1_000;
        }

        if (429 === $response->getStatusCode() || (403 === $response->getStatusCode() && ($response->hasHeader('Retry-After') || '0' === $response->getHeaderLine('X-RateLimit-Remaining')))) {
            return self::MAX_DELAY_MS * (2 ** max(0, $retries - 1));
        }

        return $this->backoff($retries);
    }

    private function backoff(int $retries): int
    {
        return (int) min($this->baseDelayMs * (2 ** $retries), self::MAX_DELAY_MS);
    }
}
