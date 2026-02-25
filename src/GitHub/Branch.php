<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use InvalidArgumentException;
use Stringable;

use function is_string;
use function sprintf;

final class Branch implements Stringable
{
    public function __construct(public readonly string $name)
    {
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * Create a Branch instance from GitHub API data.
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

        return new self($name);
    }
}
