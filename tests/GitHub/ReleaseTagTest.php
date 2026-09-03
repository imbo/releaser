<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use ImboReleaser\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseTag::class)]
class ReleaseTagTest extends TestCase
{
    public function testCreatesReleaseTagFromVersionedTag(): void
    {
        $releaseTag = ReleaseTag::fromTag(new Tag('v1.2.3', 'abc123'));

        $this->assertSame('v1.2.3', $releaseTag->name);
        $this->assertSame('abc123', $releaseTag->sha);
        $this->assertSame('v1.2.3', (string) $releaseTag->version);
    }

    public function testIgnoresTagWithoutVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReleaseTag::fromTag(new Tag('nightly', 'abc123'));
    }

    public function testRejectsInvalidReleaseTagName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReleaseTag('nightly', 'abc123');
    }
}
