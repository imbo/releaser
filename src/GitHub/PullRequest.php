<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use DateMalformedStringException;
use DateTimeImmutable;
use InvalidArgumentException;
use Ramsey\ConventionalCommits\Exception\InvalidCommitMessage;
use Ramsey\ConventionalCommits\Message;
use Ramsey\ConventionalCommits\Parser;

use function is_array;
use function is_int;
use function is_string;
use function sprintf;

final class PullRequest
{
    public readonly ?Message $title;

    public function __construct(
        public readonly int $number,
        public readonly User $user,
        public readonly DateTimeImmutable $mergedAt,
        public readonly string $rawTitle,
        public readonly string $baseRef,
    ) {
        try {
            $this->title = (new Parser())->parse($rawTitle);
        } catch (InvalidCommitMessage) {
            $this->title = null;
        }
    }

    /**
     * Create a PullRequest instance from GitHub API data.
     *
     * @param array<mixed> $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromAPI(array $data): self
    {
        $number = $data['number'] ?? null;
        if (!is_int($number)) {
            throw new InvalidArgumentException(sprintf('Missing required "number" key: %s', var_export($data, true)));
        }

        $user = $data['user'] ?? null;
        if (!is_array($user)) {
            throw new InvalidArgumentException(sprintf('Missing required "user" key: %s', var_export($data, true)));
        }

        $login = $user['login'] ?? null;
        if (!is_string($login)) {
            throw new InvalidArgumentException(sprintf('Missing required "user.login" key: %s', var_export($data, true)));
        }

        $title = $data['title'] ?? null;
        if (!is_string($title)) {
            throw new InvalidArgumentException(sprintf('Missing required "title" key: %s', var_export($data, true)));
        }

        $mergedAt = $data['merged_at'] ?? null;
        if (!is_string($mergedAt)) {
            throw new InvalidArgumentException(sprintf('Missing required "merged_at" key: %s', var_export($data, true)));
        }

        $base = $data['base'] ?? null;
        if (!is_array($base)) {
            throw new InvalidArgumentException(sprintf('Missing required "base" key: %s', var_export($data, true)));
        }

        $ref = $base['ref'] ?? null;
        if (!is_string($ref)) {
            throw new InvalidArgumentException(sprintf('Missing required "base.ref" key: %s', var_export($data, true)));
        }

        try {
            $mergedAtDateTime = new DateTimeImmutable($mergedAt);
        } catch (DateMalformedStringException $e) {
            throw new InvalidArgumentException(sprintf('Invalid "merged_at" value: %s', $mergedAt), previous: $e);
        }

        return new self($number, new User($login), $mergedAtDateTime, $title, $ref);
    }
}
