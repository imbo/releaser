<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Repository::class)]
class RepositoryTest extends TestCase
{
    public function testToString(): void
    {
        $repository = new Repository('imbo', 'releaser');
        $this->assertSame('imbo/releaser', (string) $repository);
    }

    public function testFromString(): void
    {
        $repository = Repository::fromString('imbo/releaser');
        $this->assertSame('imbo', $repository->owner);
        $this->assertSame('releaser', $repository->repo);
    }

    public function testFromStringWithInvalidString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Repository::fromString('invalid-repo-string');
    }

    public function testUrl(): void
    {
        $repository = new Repository('imbo', 'releaser');
        $this->assertSame('https://github.com/imbo/releaser', $repository->url());
    }
}
