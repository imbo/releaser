<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use DateTimeImmutable;
use ImboReleaser\Exception\InvalidArgumentException;
use Ramsey\ConventionalCommits\Exception\InvalidCommitMessage;
use Ramsey\ConventionalCommits\Message;
use Ramsey\ConventionalCommits\Parser;

use function sprintf;

final class ReleasePullRequest
{
    /**
     * @param list<string> $labels
     */
    public function __construct(
        public readonly int $number,
        public readonly User $user,
        public readonly DateTimeImmutable $mergedAt,
        public readonly Message $message,
        public readonly string $baseRef,
        public readonly array $labels = [],
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromPullRequest(PullRequest $pullRequest): self
    {
        try {
            $message = (new Parser())->parse($pullRequest->rawMessage);
        } catch (InvalidCommitMessage $e) {
            throw new InvalidArgumentException(sprintf('Pull request #%d does not have a valid Conventional Commit message.', $pullRequest->number), previous: $e);
        }

        return new self(
            $pullRequest->number,
            $pullRequest->user,
            $pullRequest->mergedAt,
            $message,
            $pullRequest->baseRef,
            $pullRequest->labels,
        );
    }
}
