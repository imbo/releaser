<?php declare(strict_types=1);

namespace ImboReleaser;

use ImboReleaser\GitHub\PullRequest;
use ImboReleaser\GitHub\Repository;

class TemplateData
{
    public function __construct(
        public readonly string $nextVersion,
        public readonly Repository $repository,
        /** @var list<PullRequest> */
        public readonly array $pullRequests,
        /** @var array<string,list<PullRequest>> */
        public readonly array $groupedPullRequests,
        /** @var array<string,PullRequest> */
        public readonly array $newContributors,
    ) {
    }
}
