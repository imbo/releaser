<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use DateTimeImmutable;
use ImboReleaser\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleasePullRequest::class)]
class ReleasePullRequestTest extends TestCase
{
    public function testCreatesReleasePullRequest(): void
    {
        $pullRequest = new PullRequest(123, new User('johndoe'), new DateTimeImmutable('2024-01-01'), 'feat: add new feature', 'main', ['enhancement'], 'mergeSha');
        $releasePullRequest = ReleasePullRequest::fromPullRequest($pullRequest);

        $this->assertSame($pullRequest->number, $releasePullRequest->number);
        $this->assertSame($pullRequest->user, $releasePullRequest->user);
        $this->assertSame($pullRequest->mergedAt, $releasePullRequest->mergedAt);
        $this->assertStringStartsWith('feat: add new feature', (string) $releasePullRequest->message);
        $this->assertSame($pullRequest->baseRef, $releasePullRequest->baseRef);
        $this->assertSame($pullRequest->labels, $releasePullRequest->labels);
        $this->assertSame('mergeSha', $releasePullRequest->mergeCommitSha);
    }

    public function testRejectsPullRequestWithoutConventionalCommitMessage(): void
    {
        $pullRequest = new PullRequest(123, new User('johndoe'), new DateTimeImmutable('2024-01-01'), 'Some commit message', 'main');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pull request #123 does not have a valid Conventional Commit message.');
        ReleasePullRequest::fromPullRequest($pullRequest);
    }

    /**
     * @return iterable<string,array{title:string}>
     */
    public static function uppercaseTypeProvider(): iterable
    {
        yield 'uppercase' => ['title' => 'FEAT: add a feature'];
        yield 'mixed case' => ['title' => 'Feat: add a feature'];
        yield 'scoped breaking change' => ['title' => 'FIX(API)!: change behavior'];
        yield 'custom type' => ['title' => 'Custom: change behavior'];
    }

    #[DataProvider('uppercaseTypeProvider')]
    public function testAcceptsUppercaseTypes(string $title): void
    {
        $pr = ReleasePullRequest::fromPullRequest(new PullRequest(123, new User('alice'), new DateTimeImmutable(), $title, 'main'));

        $this->assertSame($title, trim((string) $pr->message));
    }

    public function testAcceptsUppercaseScopeDescriptionAndBreakingFooter(): void
    {
        $pr = ReleasePullRequest::fromPullRequest(new PullRequest(123, new User('alice'), new DateTimeImmutable(), "feat(API): Add a feature\n\nBREAKING CHANGE: Replace the API", 'main'));

        $this->assertSame('feat', $pr->message->getType()->toString());
        $this->assertTrue($pr->message->hasBreakingChanges());
    }
}
