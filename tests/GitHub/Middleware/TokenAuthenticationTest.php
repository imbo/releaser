<?php declare(strict_types=1);

namespace ImboReleaser\GitHub\Middleware;

use GuzzleHttp\Psr7\Request;
use ImboReleaser\Exception\RuntimeException;
use ImboReleaser\GitHub\TokenResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenAuthentication::class)]
class TokenAuthenticationTest extends TestCase
{
    public function testAddsAuthorizationHeader(): void
    {
        $middleware = new TokenAuthentication($this->tokenResolver('token'));
        $request = new Request('GET', '/repos/owner/repo', ['Accept' => 'application/json']);

        $request = $middleware($request);

        $this->assertSame('Bearer token', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testThrowsWhenTokenCannotBeResolved(): void
    {
        $middleware = new TokenAuthentication($this->tokenResolver(null));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to resolve the required GitHub API token.');
        $middleware(new Request('GET', '/repos/owner/repo'));
    }

    private function tokenResolver(?string $token): TokenResolver
    {
        return new class($token) extends TokenResolver {
            public function __construct(private ?string $token)
            {
            }

            public function getGitHubToken(): ?string
            {
                return $this->token;
            }
        };
    }
}
