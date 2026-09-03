<?php declare(strict_types=1);

namespace ImboReleaser;

use ImboReleaser\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

#[CoversClass(Version::class)]
class VersionTest extends TestCase
{
    public function testDefaultVersion(): void
    {
        $this->assertSame('0.0.0', (string) new Version());
    }

    public function testCanConstructWithAllParts(): void
    {
        $this->assertSame('v1.2.3', (string) new Version('v', 1, 2, 3));
    }

    public function testIncrementMajorResetsMinorAndPatch(): void
    {
        $version = new Version('v', 1, 2, 3);
        $this->assertSame('v2.0.0', (string) $version->incrementMajor());
        $this->assertSame('v1.2.3', (string) $version, 'Original version should be immutable');
    }

    public function testIncrementMinorResetsPatch(): void
    {
        $version = new Version('v', 1, 2, 3);
        $this->assertSame('v1.3.0', (string) $version->incrementMinor());
        $this->assertSame('v1.2.3', (string) $version, 'Original version should be immutable');
    }

    public function testIncrementPatch(): void
    {
        $version = new Version('v', 1, 2, 3);
        $this->assertSame('v1.2.4', (string) $version->incrementPatch());
        $this->assertSame('v1.2.3', (string) $version, 'Original version should be immutable');
    }

    public function testIncrementPreservesPrefix(): void
    {
        $this->assertSame('release-2.0.0', (string) (new Version('release-', 1, 2, 3))->incrementMajor());
        $this->assertSame('1.3.0', (string) (new Version(null, 1, 2, 3))->incrementMinor());
    }

    public function testIncrementClearsPrerelease(): void
    {
        $version = Version::fromString('v1.2.3-rc.1');

        $this->assertSame('v2.0.0', (string) $version->incrementMajor());
        $this->assertSame('v1.3.0', (string) $version->incrementMinor());
        $this->assertSame('v1.2.4', (string) $version->incrementPatch());
    }

    public function testCanCreatePrerelease(): void
    {
        $version = (new Version('v', 1, 2, 3))->withPrerelease('rc', 1);

        $this->assertSame('v1.2.3-rc.1', (string) $version);
        $this->assertTrue($version->isPrerelease());
        $this->assertSame(1, $version->prereleaseNumber('rc'));
        $this->assertNull($version->prereleaseNumber('beta'));
    }

    /**
     * @return iterable<string,array{identifier:string,number:int}>
     */
    public static function invalidPrereleaseProvider(): iterable
    {
        yield 'empty identifier' => ['identifier' => '', 'number' => 1];
        yield 'invalid identifier character' => ['identifier' => 'rc_1', 'number' => 1];
        yield 'zero sequence number' => ['identifier' => 'rc', 'number' => 0];
    }

    #[DataProvider('invalidPrereleaseProvider')]
    public function testCannotCreatePrereleaseWithInvalidIdentifierOrNumber(string $identifier, int $number): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Invalid prerelease identifier or number: "%s.%d"', $identifier, $number));

        (new Version())->withPrerelease($identifier, $number);
    }

    /**
     * @return iterable<string,array{version:Version,other:Version,expected:int}>
     */
    public static function compareToProvider(): iterable
    {
        yield 'major version is lower' => [
            'version' => new Version(null, 1, 0, 0),
            'other' => new Version(null, 2, 0, 0),
            'expected' => -1,
        ];
        yield 'minor version is lower' => [
            'version' => new Version(null, 1, 1, 0),
            'other' => new Version(null, 1, 2, 0),
            'expected' => -1,
        ];
        yield 'patch version is lower' => [
            'version' => new Version(null, 1, 2, 3),
            'other' => new Version(null, 1, 2, 4),
            'expected' => -1,
        ];
        yield 'versions are equal despite different prefixes' => [
            'version' => new Version('v', 1, 2, 3),
            'other' => new Version('release-', 1, 2, 3),
            'expected' => 0,
        ];
        yield 'major version is higher' => [
            'version' => new Version(null, 2, 0, 0),
            'other' => new Version(null, 1, 0, 0),
            'expected' => 1,
        ];
    }

    #[DataProvider('compareToProvider')]
    public function testCompareTo(Version $version, Version $other, int $expected): void
    {
        $this->assertSame($expected, $version->compareTo($other));
    }

    /**
     * @return iterable<string,array{input:string,expected:string}>
     */
    public static function fromStringProvider(): iterable
    {
        yield 'no prefix' => [
            'input' => '1.2.3',
            'expected' => '1.2.3',
        ];
        yield 'v prefix' => [
            'input' => 'v1.2.3',
            'expected' => 'v1.2.3',
        ];
        yield 'custom prefix' => [
            'input' => 'release-1.2.3',
            'expected' => 'release-1.2.3',
        ];
        yield 'multi-digit parts' => [
            'input' => 'v10.20.30',
            'expected' => 'v10.20.30',
        ];
        yield 'zero version' => [
            'input' => '0.0.0',
            'expected' => '0.0.0',
        ];
        yield 'prerelease' => [
            'input' => 'v1.2.3-rc.1',
            'expected' => 'v1.2.3-rc.1',
        ];
    }

    #[DataProvider('fromStringProvider')]
    public function testFromString(string $input, string $expected): void
    {
        $this->assertSame($expected, (string) Version::fromString($input));
    }

    /**
     * @return iterable<string,array{input:string}>
     */
    public static function invalidVersionStringProvider(): iterable
    {
        yield 'missing patch' => ['input' => '1.2'];
        yield 'missing minor and patch' => ['input' => '1'];
        yield 'invalid string' => ['input' => 'foo'];
        yield 'empty string' => ['input' => ''];
        yield 'invalid patch' => ['input' => 'v1.2.x'];
        yield 'empty prerelease' => ['input' => 'v1.2.3-'];
        yield 'invalid prerelease' => ['input' => 'v1.2.3-rc_1'];
    }

    #[DataProvider('invalidVersionStringProvider')]
    public function testFromStringThrowsOnInvalidVersion(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Invalid version string: "%s"', $input));
        Version::fromString($input);
    }
}
