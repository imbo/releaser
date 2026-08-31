<?php declare(strict_types=1);

namespace ImboReleaser;

use DateTimeImmutable;
use ImboReleaser\GitHub\PullRequest;
use ImboReleaser\GitHub\Repository;
use ImboReleaser\GitHub\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemplateData::class)]
class TemplateDataTest extends TestCase
{
    public function testToContext(): void
    {
        $nextVersion = Version::fromString('1.2.3');
        $repository = Repository::fromString('owner/repo');
        $pr1 = new PullRequest(1, new User('alice'), new DateTimeImmutable(), 'feat: foo', 'main');
        $pr2 = new PullRequest(2, new User('bob'), new DateTimeImmutable(), 'fix: bar', 'main');
        $pullRequests = [$pr1, $pr2];
        $groupedPullRequests = ['New Features 🚀' => [$pr1], 'Bug Fixes 🐛' => [$pr2]];
        $newContributors = ['alice' => $pr1];

        $data = new TemplateData($nextVersion, $repository, $pullRequests, $groupedPullRequests, $newContributors);
        $context = $data->toContext();

        $this->assertSame($nextVersion, $context['nextVersion']);
        $this->assertSame($repository, $context['repository']);
        $this->assertSame($pullRequests, $context['pullRequests']);
        $this->assertSame($groupedPullRequests, $context['groupedPullRequests']);
        $this->assertSame($newContributors, $context['newContributors']);
    }
}
