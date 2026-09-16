<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use DateMalformedStringException;
use DateTimeImmutable;
use ImboReleaser\Exception\InvalidArgumentException;
use ImboReleaser\Version;
use Stringable;

use function array_key_exists;
use function is_bool;
use function is_int;
use function is_string;
use function sprintf;
use function trim;
use function var_export;

final class Release implements Stringable
{
    public readonly ?Version $version;

    public function __construct(
        public readonly string $name,
        public readonly string $tagName,
        public readonly string $htmlUrl,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $publishedAt = null,
        public readonly bool $draft = false,
        public readonly ?int $id = null,
    ) {
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
        if (!array_key_exists('name', $data)) {
            throw new InvalidArgumentException(sprintf('Missing required "name" key: %s', var_export($data, true)));
        }
        $name = $data['name'];
        if (null !== $name && !is_string($name)) {
            throw new InvalidArgumentException(sprintf('Invalid "name" value: %s', var_export($data, true)));
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

        $publishedAt = $data['published_at'] ?? null;
        $publishedAtDateTime = null;
        if (null !== $publishedAt) {
            if (!is_string($publishedAt) || '' === trim($publishedAt)) {
                throw new InvalidArgumentException(sprintf('Invalid "published_at" value: %s', var_export($publishedAt, true)));
            }

            try {
                $publishedAtDateTime = new DateTimeImmutable($publishedAt);
            } catch (DateMalformedStringException $e) {
                throw new InvalidArgumentException(sprintf('Invalid "published_at" value: %s', $publishedAt), previous: $e);
            }
        }

        $draft = array_key_exists('draft', $data) ? $data['draft'] : false;
        if (!is_bool($draft)) {
            throw new InvalidArgumentException(sprintf('Invalid "draft" value: %s', var_export($draft, true)));
        }

        $id = $data['id'] ?? null;
        if (null !== $id && (!is_int($id) || $id < 1)) {
            throw new InvalidArgumentException(sprintf('Invalid "id" value: %s', var_export($id, true)));
        }

        return new self($name ?? $tagName, $tagName, $htmlUrl, $createdAtDateTime, $publishedAtDateTime, $draft, $id);
    }
}
