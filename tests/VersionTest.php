<?php declare(strict_types=1);

namespace ImboReleaser;

use InvalidArgumentException;
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

    /**
     * @return array<string,array{input:string,expected:string}>
     */
    public static function fromStringProvider(): array
    {
        return [
            'no prefix' => ['input' => '1.2.3', 'expected' => '1.2.3'],
            'v prefix' => ['input' => 'v1.2.3', 'expected' => 'v1.2.3'],
            'custom prefix' => ['input' => 'release-1.2.3', 'expected' => 'release-1.2.3'],
            'multi-digit parts' => ['input' => 'v10.20.30', 'expected' => 'v10.20.30'],
            'zero version' => ['input' => '0.0.0', 'expected' => '0.0.0'],
        ];
    }

    #[DataProvider('fromStringProvider')]
    public function testFromString(string $input, string $expected): void
    {
        $this->assertSame($expected, (string) Version::fromString($input));
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidVersionStringProvider(): array
    {
        return [
            ['1.2'],
            ['1'],
            ['foo'],
            [''],
            ['v1.2.x'],
        ];
    }

    #[DataProvider('invalidVersionStringProvider')]
    public function testFromStringThrowsOnInvalidVersion(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Invalid version string: "%s"', $input));
        Version::fromString($input);
    }
}
