<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use Stringable;

final class User implements Stringable
{
    public function __construct(public readonly string $login)
    {
    }

    public function __toString(): string
    {
        return $this->login;
    }
}
