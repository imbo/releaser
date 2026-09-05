<?php declare(strict_types=1);

namespace ImboReleaser\GitHub\Middleware;

use ImboReleaser\Exception\RuntimeException;
use ImboReleaser\GitHub\TokenResolver;
use Psr\Http\Message\RequestInterface;

use function sprintf;

final class TokenAuthentication
{
    public function __construct(private TokenResolver $tokenResolver)
    {
    }

    public function __invoke(RequestInterface $request): RequestInterface
    {
        $token = $this->tokenResolver->getGitHubToken();
        if (null === $token) {
            throw new RuntimeException('Failed to resolve the required GitHub API token. Please make sure to set the "GITHUB_TOKEN" environment variable or authenticate using the GitHub CLI.');
        }

        return $request->withHeader('Authorization', sprintf('Bearer %s', $token));
    }
}
