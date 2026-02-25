<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use InvalidArgumentException;
use PHLAK\SemVer\Exceptions\InvalidVersionException;
use PHLAK\SemVer\Version;
use Stringable;

use function is_array;
use function is_string;
use function sprintf;

final class Tag implements Stringable
{
    public readonly ?Version $version;

    public function __construct(public readonly string $name, public readonly string $sha)
    {
        try {
            $this->version = new Version($this->name);
        } catch (InvalidVersionException) {
            $this->version = null;
        }
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * Create a Tag instance from GitHub API data.
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

        $commit = $data['commit'] ?? null;
        if (!is_array($commit)) {
            throw new InvalidArgumentException(sprintf('Missing required "commit" key: %s', var_export($data, true)));
        }

        $sha = $commit['sha'] ?? null;
        if (!is_string($sha)) {
            throw new InvalidArgumentException(sprintf('Missing required "commit.sha" key: %s', var_export($data, true)));
        }

        return new self($name, $sha);
    }
}
