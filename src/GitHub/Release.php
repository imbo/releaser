<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use DateMalformedStringException;
use DateTimeImmutable;
use ImboReleaser\Exception\InvalidArgumentException;
use ImboReleaser\Version;
use Stringable;

use function is_string;
use function sprintf;
use function var_export;

final class Release implements Stringable
{
    public readonly ?Version $version;

    public function __construct(public readonly string $name, public readonly string $tagName, public readonly string $htmlUrl, public readonly DateTimeImmutable $createdAt)
    {
        try {
            $this->version = Version::fromString($this->tagName);
        } catch (InvalidArgumentException) {
            $this->version = null;
        }
    }

    /**
     * Return the version if it could be parsed, otherwise return the tag name.
     */
    public function __toString(): string
    {
        return null === $this->version ? $this->tagName : (string) $this->version;
    }

    /**
     * Create a Release instance from GitHub API data.
     *
     * @param array<mixed> $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromAPI(array $data): self
    {
        $name = $data['name'] ?? null;
        if (!is_string($name)) {
            throw new InvalidArgumentException(sprintf('Missing required "name" key: %s', var_export($data, true)));
        }

        $tagName = $data['tag_name'] ?? null;
        if (!is_string($tagName)) {
            throw new InvalidArgumentException(sprintf('Missing required "tag_name" key: %s', var_export($data, true)));
        }

        $htmlUrl = $data['html_url'] ?? null;
        if (!is_string($htmlUrl)) {
            throw new InvalidArgumentException(sprintf('Missing required "html_url" key: %s', var_export($data, true)));
        }

        $createdAt = $data['created_at'] ?? null;
        if (!is_string($createdAt)) {
            throw new InvalidArgumentException(sprintf('Missing required "created_at" key: %s', var_export($data, true)));
        }

        try {
            $createdAtDateTime = new DateTimeImmutable($createdAt);
        } catch (DateMalformedStringException $e) {
            throw new InvalidArgumentException(sprintf('Invalid "created_at" value: %s', $createdAt), previous: $e);
        }

        return new self($name, $tagName, $htmlUrl, $createdAtDateTime);
    }
}
