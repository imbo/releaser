<?php declare(strict_types=1);

namespace ImboReleaser\Config;

use ImboReleaser\Config;
use ImboReleaser\ConfigInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function dirname;

#[CoversClass(Resolver::class)]
class ResolverTest extends TestCase
{
    public function testLoadInvalidConfigFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not return a valid configuration');
        new Resolver(new Config(), getcwd() ?: null, dirname(__DIR__).'/fixtures/invalid-custom-config.php');
    }

    public function testLoadMissingConfigFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not return a valid configuration');
        new Resolver(new Config(), getcwd() ?: null, dirname(__DIR__).'/fixtures/missing-config.php');
    }

    /**
     * @return array<string,array{default:ConfigInterface,expectedVersion:string,expectedConfigFilePath:?string,cwd:?string,configFile:?string}>
     */
    public static function getConfigCandidates(): array
    {
        /** @var ConfigInterface $custom */
        $custom = require dirname(__DIR__).'/fixtures/valid-custom-config.php';

        return [
            'config in cwd' => [
                'default' => new Config(),
                'expectedVersion' => '0.0.0',
                'expectedConfigFilePath' => 'fixtures/.imbo-releaser.php',
                'cwd' => dirname(__DIR__).'/fixtures',
                'configFile' => null,
            ],
            'default config' => [
                'default' => new Config(),
                'expectedVersion' => '0.1.0',
                'expectedConfigFilePath' => null,
                'cwd' => __DIR__,
                'configFile' => null,
            ],
            'custom config file' => [
                'default' => new Config(),
                'expectedVersion' => '1.0.0',
                'expectedConfigFilePath' => 'fixtures/valid-custom-config.php',
                'cwd' => getcwd() ?: null,
                'configFile' => dirname(__DIR__).'/fixtures/valid-custom-config.php',
            ],
            'custom default config' => [
                'default' => $custom,
                'expectedVersion' => '1.0.0',
                'expectedConfigFilePath' => null,
                'cwd' => null,
                'configFile' => null,
            ],
        ];
    }

    #[DataProvider('getConfigCandidates')]
    public function testGetConfig(ConfigInterface $default, string $expectedVersion, ?string $expectedConfigFilePath, ?string $cwd, ?string $configFile): void
    {
        $resolver = new Resolver($default, $cwd, $configFile);
        $this->assertSame($expectedVersion, (string) $resolver->getConfig()->initialVersion());

        if (null === $expectedConfigFilePath || '' === $expectedConfigFilePath) {
            $this->assertNull($resolver->configFilePath());
        } else {
            $this->assertStringEndsWith($expectedConfigFilePath, $resolver->configFilePath() ?: '');
        }
    }
}
