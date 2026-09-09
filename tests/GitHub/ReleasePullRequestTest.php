<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use DateTimeImmutable;
use ImboReleaser\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleasePullRequest::class)]
class ReleasePullRequestTest extends TestCase
{
    public function testCreatesReleasePullRequest(): void
    {
        $pullRequest = new PullRequest(123, new User('johndoe'), new DateTimeImmutable('2024-01-01'), 'feat: add new feature', 'main', ['enhancement']);
        $releasePullRequest = ReleasePullRequest::fromPullRequest($pullRequest);

        $this->assertSame($pullRequest->number, $releasePullRequest->number);
        $this->assertSame($pullRequest->user, $releasePullRequest->user);
        $this->assertSame($pullRequest->mergedAt, $releasePullRequest->mergedAt);
        $this->assertStringStartsWith('feat: add new feature', (string) $releasePullRequest->message);
        $this->assertSame($pullRequest->baseRef, $releasePullRequest->baseRef);
        $this->assertSame($pullRequest->labels, $releasePullRequest->labels);
    }

    public function testRejectsPullRequestWithoutConventionalCommitMessage(): void
    {
        $pullRequest = new PullRequest(123, new User('johndoe'), new DateTimeImmutable('2024-01-01'), 'Some commit message', 'main');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pull request #123 does not have a valid Conventional Commit message.');
        ReleasePullRequest::fromPullRequest($pullRequest);
    }
}
