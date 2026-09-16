<?php declare(strict_types=1);

namespace ImboReleaser\GitHub\Middleware;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Retry::class)]
class RetryTest extends TestCase
{
    private Retry $retry;
    private Request $request;

    protected function setUp(): void
    {
        $this->retry = new Retry();
        $this->request = new Request('GET', '/repos/owner/repo');
    }

    public function testDoesNotRetryWhenMaxRetriesExhausted(): void
    {
        $response = new Response(429);
        $this->assertFalse($this->retry->decide(3, $this->request, $response, null));
    }

    public function testRetriesOnConnectException(): void
    {
        $exception = new ConnectException('Connection refused', $this->request);
        $this->assertTrue($this->retry->decide(0, $this->request, null, $exception));
    }

    public function testDoesNotRetryOnNonConnectException(): void
    {
        $exception = new RuntimeException('Something else');
        $this->assertFalse($this->retry->decide(0, $this->request, null, $exception));
    }

    /**
     * @return iterable<string,array{method:string,response:?Response,exception:?ConnectException}>
     */
    public static function unsafeRequestProvider(): iterable
    {
        yield 'POST with connection error' => ['method' => 'POST', 'response' => null, 'exception' => new ConnectException('Connection refused', new Request('POST', '/repos/owner/repo/releases'))];
        yield 'POST with rate limit response' => ['method' => 'POST', 'response' => new Response(429), 'exception' => null];
        yield 'DELETE with server error response' => ['method' => 'DELETE', 'response' => new Response(503), 'exception' => null];
    }

    #[DataProvider('unsafeRequestProvider')]
    public function testDoesNotRetryUnsafeRequest(string $method, ?Response $response, ?ConnectException $exception): void
    {
        $request = new Request($method, '/repos/owner/repo');

        $this->assertFalse($this->retry->decide(0, $request, $response, $exception));
    }

    /**
     * @return iterable<string,array{response:Response,shouldRetry:bool}>
     */
    public static function rateLimitProvider(): iterable
    {
        yield '429 Too Many Requests' => ['response' => new Response(429), 'shouldRetry' => true];
        yield '403 with Retry-After header' => ['response' => new Response(403, ['Retry-After' => '30']), 'shouldRetry' => true];
        yield '403 with rate limit exhausted' => ['response' => new Response(403, ['X-RateLimit-Remaining' => '0']), 'shouldRetry' => true];
        yield '403 without rate limit indicators' => ['response' => new Response(403), 'shouldRetry' => false];
        yield '403 with remaining requests' => ['response' => new Response(403, ['X-RateLimit-Remaining' => '50']), 'shouldRetry' => false];
    }

    #[DataProvider('rateLimitProvider')]
    public function testRateLimitHandling(Response $response, bool $shouldRetry): void
    {
        $this->assertSame($shouldRetry, $this->retry->decide(0, $this->request, $response, null));
    }

    /**
     * @return iterable<string,array{status:int}>
     */
    public static function retryableServerErrorProvider(): iterable
    {
        yield '500 Internal Server Error' => ['status' => 500];
        yield '502 Bad Gateway' => ['status' => 502];
        yield '503 Service Unavailable' => ['status' => 503];
        yield '504 Gateway Timeout' => ['status' => 504];
    }

    #[DataProvider('retryableServerErrorProvider')]
    public function testRetriesOnTransientServerError(int $status): void
    {
        $response = new Response($status);
        $this->assertTrue($this->retry->decide(0, $this->request, $response, null));
    }

    /**
     * @return iterable<string,array{status:int}>
     */
    public static function nonRetryableStatusProvider(): iterable
    {
        yield '200 OK' => ['status' => 200];
        yield '404 Not Found' => ['status' => 404];
        yield '422 Unprocessable Entity' => ['status' => 422];
        yield '505 HTTP Version Not Supported' => ['status' => 505];
    }

    #[DataProvider('nonRetryableStatusProvider')]
    public function testDoesNotRetryOnNonRetryableStatus(int $status): void
    {
        $response = new Response($status);
        $this->assertFalse($this->retry->decide(0, $this->request, $response, null));
    }

    public function testRespectsCustomMaxRetries(): void
    {
        $retry = new Retry(maxRetries: 5);
        $response = new Response(500);
        $this->assertTrue($retry->decide(4, $this->request, $response, null));
        $this->assertFalse($retry->decide(5, $this->request, $response, null));
    }

    public function testRetriesUpToMaxOnConsecutiveFailures(): void
    {
        $response = new Response(502);
        $this->assertTrue($this->retry->decide(0, $this->request, $response, null));
        $this->assertTrue($this->retry->decide(1, $this->request, $response, null));
        $this->assertTrue($this->retry->decide(2, $this->request, $response, null));
        $this->assertFalse($this->retry->decide(3, $this->request, $response, null));
    }

    /**
     * @return iterable<string,array{headerValue:string,expectedMs:int}>
     */
    public static function retryAfterProvider(): iterable
    {
        yield '5 seconds' => ['headerValue' => '5', 'expectedMs' => 5_000];
        yield '30 seconds' => ['headerValue' => '30', 'expectedMs' => 30_000];
        yield 'zero seconds' => ['headerValue' => '0', 'expectedMs' => 0];
        yield 'one minute' => ['headerValue' => '60', 'expectedMs' => 60_000];
        yield 'invalid' => ['headerValue' => 'invalid', 'expectedMs' => 60_000];
        yield 'negative' => ['headerValue' => '-5', 'expectedMs' => 60_000];
        yield 'fractional' => ['headerValue' => '0.5', 'expectedMs' => 60_000];
        yield 'scientific notation' => ['headerValue' => '1e1', 'expectedMs' => 60_000];
        yield 'relative date' => ['headerValue' => 'tomorrow', 'expectedMs' => 60_000];
        yield 'invalid calendar date' => ['headerValue' => 'Mon, 31 Feb 2031 00:00:00 GMT', 'expectedMs' => 60_000];
        yield 'past HTTP date' => ['headerValue' => 'Wed, 21 Oct 2015 07:28:00 GMT', 'expectedMs' => 0];
    }

    #[DataProvider('retryAfterProvider')]
    public function testRetryAfterHeader(string $headerValue, int $expectedMs): void
    {
        $response = new Response(429, ['Retry-After' => $headerValue]);
        $this->assertTrue($this->retry->decide(0, $this->request, $response, null));
        $this->assertSame($expectedMs, $this->retry->delay(0, $response));
    }

    public function testUsesRateLimitResetHeader(): void
    {
        $response = new Response(403, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) (time() + 10),
        ]);

        $delayMs = $this->retry->delay(0, $response);

        $this->assertGreaterThanOrEqual(9_000, $delayMs);
        $this->assertLessThanOrEqual(10_000, $delayMs);
    }

    public function testRateLimitResetInThePast(): void
    {
        $response = new Response(403, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) (time() - 5),
        ]);

        $this->assertSame(0, $this->retry->delay(0, $response));
    }

    /**
     * @return iterable<string,array{headerValue:string}>
     */
    public static function invalidRateLimitResetProvider(): iterable
    {
        yield 'empty' => ['headerValue' => ''];
        yield 'non-numeric' => ['headerValue' => 'invalid'];
        yield 'negative' => ['headerValue' => '-5'];
        yield 'fractional' => ['headerValue' => '123.5'];
        yield 'scientific notation' => ['headerValue' => '2e9'];
    }

    #[DataProvider('invalidRateLimitResetProvider')]
    public function testIgnoresInvalidRateLimitReset(string $headerValue): void
    {
        $response = new Response(403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => $headerValue]);
        $this->assertSame(60_000, $this->retry->delay(1, $response));
    }

    public function testRetryAfterTakesPrecedenceOverRateLimitReset(): void
    {
        $response = new Response(403, [
            'Retry-After' => '3',
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) (time() + 30),
        ]);

        $this->assertSame(3_000, $this->retry->delay(0, $response));
    }

    /**
     * @return iterable<string,array{timezone:string}>
     */
    public static function defaultTimezoneProvider(): iterable
    {
        yield 'UTC' => ['timezone' => 'UTC'];
        yield 'behind UTC' => ['timezone' => 'America/Los_Angeles'];
        yield 'ahead of UTC' => ['timezone' => 'Asia/Tokyo'];
    }

    #[DataProvider('defaultTimezoneProvider')]
    public function testUsesHttpDateRetryAfterRegardlessOfDefaultTimezone(string $timezone): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set($timezone);

        try {
            $timestamp = time() + 30;
            $response = new Response(503, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', $timestamp)]);

            $this->assertTrue($this->retry->decide(0, $this->request, $response, null));
            $before = time();
            $delay = $this->retry->delay(1, $response);
            $this->assertGreaterThanOrEqual(max(0, $timestamp - time()) * 1_000, $delay);
            $this->assertLessThanOrEqual(max(0, $timestamp - $before) * 1_000, $delay);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    /**
     * @return iterable<string,array{response:Response}>
     */
    public static function excessiveWaitProvider(): iterable
    {
        yield 'Retry-After exceeds budget' => ['response' => new Response(429, ['Retry-After' => '61'])];
        yield 'server unavailable with Retry-After' => ['response' => new Response(503, ['Retry-After' => '120'])];
        yield 'Retry-After overflows integer' => ['response' => new Response(429, ['Retry-After' => str_repeat('9', 400)])];
        yield 'HTTP date exceeds budget' => ['response' => new Response(503, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 300)])];
        yield 'exhausted quota resets later' => ['response' => new Response(403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 300)])];
        yield 'reset overflows integer' => ['response' => new Response(429, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => str_repeat('9', 400)])];
    }

    #[DataProvider('excessiveWaitProvider')]
    public function testDeclinesRetryRatherThanShorteningServerWait(Response $response): void
    {
        $this->assertFalse($this->retry->decide(0, $this->request, $response, null));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required retry delay exceeds one minute');
        $this->retry->delay(1, $response);
    }

    /**
     * @return iterable<string,array{remaining:?string}>
     */
    public static function availableQuotaProvider(): iterable
    {
        yield 'remaining quota' => ['remaining' => '50'];
        yield 'missing remaining header' => ['remaining' => null];
        yield 'malformed remaining header' => ['remaining' => 'invalid'];
    }

    #[DataProvider('availableQuotaProvider')]
    public function testServerErrorUsesBackoffUnlessQuotaIsExhausted(?string $remaining): void
    {
        $response = new Response(503, ['X-RateLimit-Reset' => (string) (time() + 3_600)]);
        if (null !== $remaining) {
            $response = $response->withHeader('X-RateLimit-Remaining', $remaining);
        }

        $this->assertTrue($this->retry->decide(0, $this->request, $response, null));
        $this->assertSame(2_000, $this->retry->delay(1, $response));
        $this->assertSame(4_000, $this->retry->delay(2, $response));
    }

    public function testMalformedRetryAfterFallsBackToResetForExhaustedQuota(): void
    {
        $response = new Response(429, ['Retry-After' => '-1', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '0']);

        $this->assertSame(0, $this->retry->delay(1, $response));
    }

    public function testMalformedRetryAfterFallsBackToBackoffForServerError(): void
    {
        $response = new Response(503, ['Retry-After' => '-1']);

        $this->assertSame(2_000, $this->retry->delay(1, $response));
    }

    public function testRateLimitWithoutWaitHeaderStopsWhenIncreasingWaitExceedsBudget(): void
    {
        $response = new Response(429);

        $this->assertTrue($this->retry->decide(0, $this->request, $response, null));
        $this->assertSame(60_000, $this->retry->delay(1, $response));
        $this->assertFalse($this->retry->decide(1, $this->request, $response, null));
    }

    public function testFallsBackToBackoffWhenNoRelevantHeaders(): void
    {
        $response = new Response(500);
        $this->assertSame(1_000, $this->retry->delay(0, $response));
        $this->assertSame(2_000, $this->retry->delay(1, $response));
    }

    /**
     * @return iterable<string,array{retries:int,expectedMs:int}>
     */
    public static function exponentialBackoffProvider(): iterable
    {
        yield 'retry 0' => ['retries' => 0, 'expectedMs' => 1_000];
        yield 'retry 1' => ['retries' => 1, 'expectedMs' => 2_000];
        yield 'retry 2' => ['retries' => 2, 'expectedMs' => 4_000];
        yield 'retry 3' => ['retries' => 3, 'expectedMs' => 8_000];
        yield 'retry 4' => ['retries' => 4, 'expectedMs' => 16_000];
        yield 'retry 5' => ['retries' => 5, 'expectedMs' => 32_000];
        yield 'retry 6 (capped)' => ['retries' => 6, 'expectedMs' => 60_000];
        yield 'retry 10 (capped)' => ['retries' => 10, 'expectedMs' => 60_000];
    }

    #[DataProvider('exponentialBackoffProvider')]
    public function testExponentialBackoff(int $retries, int $expectedMs): void
    {
        $this->assertSame($expectedMs, $this->retry->delay($retries, null));
    }

    public function testRespectsCustomBaseDelay(): void
    {
        $retry = new Retry(baseDelayMs: 500);

        $this->assertSame(500, $retry->delay(0, null));
        $this->assertSame(1_000, $retry->delay(1, null));
        $this->assertSame(2_000, $retry->delay(2, null));
    }
}
