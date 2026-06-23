<?php declare(strict_types=1);

namespace ImboReleaser\Console\Application;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version::class)]
class VersionTest extends TestCase
{
    private const string PACKAGE = 'imbo/releaser-test-fixture';

    /** @var callable():void */
    private $restoreCallback;

    protected function setUp(): void
    {
        $this->restoreCallback = static fn () => InstalledVersions::reload(InstalledVersions::getAllRawData()[0]);
    }

    protected function tearDown(): void
    {
        ($this->restoreCallback)();
    }

    public function testReturnsTaggedVersionAsIs(): void
    {
        $this->registerPackage([
            'pretty_version' => '1.2.3',
            'version' => '1.2.3.0',
            'reference' => 'abc1234abc1234abc1234abc1234abc1234abc12',
        ]);

        $this->assertSame('1.2.3', (new Version(self::PACKAGE))->getVersion());
    }

    public function testAppendsShortReferenceForDevVersion(): void
    {
        $this->registerPackage([
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => 'abc1234abc1234abc1234abc1234abc1234abc12',
        ]);

        $this->assertSame('dev-main@abc1234', (new Version(self::PACKAGE))->getVersion());
    }

    public function testReturnsDevVersionWithoutReferenceWhenReferenceMissing(): void
    {
        $this->registerPackage([
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
        ]);

        $this->assertSame('dev-main', (new Version(self::PACKAGE))->getVersion());
    }

    public function testReturnsUnknownForUninstalledPackage(): void
    {
        $this->assertSame(
            Version::UNKNOWN_VERSION,
            (new Version('does/not-exist'))->getVersion(),
        );
    }

    public function testReturnsUnknownWhenPrettyVersionIsMissing(): void
    {
        InstalledVersions::reload([
            'root' => [
                'name' => 'root/package',
                'pretty_version' => '1.0.0',
                'version' => '1.0.0.0',
                'reference' => null,
                'type' => 'project',
                'install_path' => __DIR__,
                'aliases' => [],
                'dev' => true,
            ],
            'versions' => [
                self::PACKAGE => [
                    'dev_requirement' => false,
                ],
            ],
        ]);

        $this->assertSame(
            Version::UNKNOWN_VERSION,
            (new Version(self::PACKAGE))->getVersion(),
        );
    }

    public function testIsStringable(): void
    {
        $this->registerPackage([
            'pretty_version' => '4.5.6',
            'version' => '4.5.6.0',
        ]);

        $this->assertSame('4.5.6', (string) new Version(self::PACKAGE));
    }

    /**
     * @param array{pretty_version:string,version:string,reference?:string} $package
     */
    private function registerPackage(array $package): void
    {
        InstalledVersions::reload([
            'root' => [
                'name' => 'root/package',
                'pretty_version' => '1.0.0',
                'version' => '1.0.0.0',
                'reference' => null,
                'type' => 'project',
                'install_path' => __DIR__,
                'aliases' => [],
                'dev' => true,
            ],
            'versions' => [
                self::PACKAGE => array_merge($package, [
                    'dev_requirement' => false,
                ]),
            ],
        ]);
    }
}
