<?php declare(strict_types=1);

namespace ImboReleaser;

use ImboReleaser\GitHub\PullRequest;
use ImboReleaser\GitHub\Repository;

class TemplateData
{
    public function __construct(
        private readonly Version $nextVersion,
        private readonly Repository $repository,
        /** @var list<PullRequest> */
        private readonly array $pullRequests,
        /** @var array<string,list<PullRequest>> */
        private readonly array $groupedPullRequests,
        /** @var array<string,PullRequest> */
        private readonly array $newContributors,
    ) {
    }

    /**
     * Return the template context as an associative array.
     *
     * @return array{nextVersion:Version,repository:Repository,pullRequests:list<PullRequest>,groupedPullRequests:array<string,list<PullRequest>>,newContributors:array<string,PullRequest>}
     */
    public function toContext(): array
    {
        return [
            'nextVersion' => $this->nextVersion,
            'repository' => $this->repository,
            'pullRequests' => $this->pullRequests,
            'groupedPullRequests' => $this->groupedPullRequests,
            'newContributors' => $this->newContributors,
        ];
    }
}
