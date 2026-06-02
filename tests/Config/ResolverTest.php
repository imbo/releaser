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
        (new Resolver())->getConfig(dirname(__DIR__).'/fixtures/invalid-custom-config-1.php');
    }

    public function testLoadMissingConfigFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not return a valid configuration');
        (new Resolver())->getConfig(dirname(__DIR__).'/fixtures/missing-config.php');
    }

    /**
     * @return array<string,array{default:ConfigInterface,expectedVersion:string,expectedConfigFilePath:?string,cwd:?string,configFile:?string}>
     */
    public static function configResolverProvider(): array
    {
        /** @var ConfigInterface $custom */
        $custom = require dirname(__DIR__).'/fixtures/valid-custom-config-1.php';

        return [
            'config in cwd' => [
                'default' => new Config(),
                'cwd' => dirname(__DIR__).'/fixtures',
                'configFile' => null,
                'expectedVersion' => '0.0.0',
                'expectedConfigFilePath' => 'fixtures/.imbo-releaser.php',
            ],
            'default config' => [
                'default' => new Config(),
                'cwd' => __DIR__,
                'configFile' => null,
                'expectedVersion' => 'v0.1.0',
                'expectedConfigFilePath' => null,
            ],
            'custom config file' => [
                'default' => new Config(),
                'cwd' => getcwd() ?: '',
                'configFile' => dirname(__DIR__).'/fixtures/valid-custom-config-1.php',
                'expectedVersion' => '1.0.0',
                'expectedConfigFilePath' => 'fixtures/valid-custom-config-1.php',
            ],
            'custom default config' => [
                'default' => $custom,
                'cwd' => __DIR__,
                'configFile' => null,
                'expectedVersion' => '1.0.0',
                'expectedConfigFilePath' => null,
            ],
        ];
    }

    #[DataProvider('configResolverProvider')]
    public function testGetConfig(ConfigInterface $default, ?string $cwd, ?string $configFile, string $expectedVersion, ?string $expectedConfigFilePath): void
    {
        $resolver = new Resolver($default, $cwd);
        $config = $resolver->getConfig($configFile);
        $this->assertSame($expectedVersion, (string) $config->initialVersion());

        if (null === $expectedConfigFilePath || '' === $expectedConfigFilePath) {
            $this->assertNull($resolver->configFilePath());
        } else {
            $path = $resolver->configFilePath();
            if (null === $path) {
                $this->fail('Expected a config file path, got null');
            }

            $this->assertStringEndsWith($expectedConfigFilePath, $path);
        }

        $this->assertSame($config, $resolver->getConfig());
    }

    public function testGetConfigMultipleTimesWithDifferentFiles(): void
    {
        $resolver = new Resolver();
        $config1 = $resolver->getConfig(dirname(__DIR__).'/fixtures/valid-custom-config-1.php');
        $config2 = $resolver->getConfig();
        $this->assertSame($config1, $config2);

        $config3 = $resolver->getConfig(dirname(__DIR__).'/fixtures/valid-custom-config-2.php');
        $config4 = $resolver->getConfig();
        $this->assertSame($config3, $config4);

        $this->assertNotSame($config1, $config3);
    }
}
