<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

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
}
