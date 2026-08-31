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
        (new Resolver())->getConfig(dirname(__DIR__).'/fixtures/invalid-custom-config.php');
    }

    public function testLoadMalformedConfigFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be loaded');
        (new Resolver())->getConfig(dirname(__DIR__).'/fixtures/malformed-config.php');
    }

    public function testLoadMissingConfigFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not return a valid configuration');
        (new Resolver())->getConfig(dirname(__DIR__).'/fixtures/missing-config.php');
    }

    /**
     * @return iterable<string,array{default:ConfigInterface,expectedVersion:string,expectedConfigFilePath:?string,cwd:?string,configFile:?string,configHome:?string}>
     */
    public static function configResolverProvider(): iterable
    {
        /** @var ConfigInterface $custom */
        $custom = require dirname(__DIR__).'/fixtures/valid-custom-config-1.php';

        yield 'config in cwd' => [
            'default' => new Config(),
            'cwd' => dirname(__DIR__).'/fixtures',
            'configFile' => null,
            'expectedVersion' => '0.0.0',
            'expectedConfigFilePath' => 'fixtures/.imbo-releaser.php',
            'configHome' => null,
        ];
        yield 'default config' => [
            'default' => new Config(),
            'cwd' => __DIR__,
            'configFile' => null,
            'expectedVersion' => 'v0.1.0',
            'expectedConfigFilePath' => null,
            'configHome' => null,
        ];
        yield 'custom config file' => [
            'default' => new Config(),
            'cwd' => getcwd() ?: '',
            'configFile' => dirname(__DIR__).'/fixtures/valid-custom-config-1.php',
            'expectedVersion' => '1.0.0',
            'expectedConfigFilePath' => 'fixtures/valid-custom-config-1.php',
            'configHome' => null,
        ];
        yield 'custom default config' => [
            'default' => $custom,
            'cwd' => __DIR__,
            'configFile' => null,
            'expectedVersion' => '1.0.0',
            'expectedConfigFilePath' => null,
            'configHome' => null,
        ];
        yield 'config in config home' => [
            'default' => new Config(),
            'cwd' => __DIR__,
            'configFile' => null,
            'expectedVersion' => '9.9.9',
            'expectedConfigFilePath' => 'fixtures/config-home/imbo-releaser/config.php',
            'configHome' => dirname(__DIR__).'/fixtures/config-home',
        ];
        yield 'cwd config takes precedence over config home' => [
            'default' => new Config(),
            'cwd' => dirname(__DIR__).'/fixtures',
            'configFile' => null,
            'expectedVersion' => '0.0.0',
            'expectedConfigFilePath' => 'fixtures/.imbo-releaser.php',
            'configHome' => dirname(__DIR__).'/fixtures/config-home',
        ];
        yield 'falls back to default when neither cwd nor config home has config' => [
            'default' => new Config(),
            'cwd' => __DIR__,
            'configFile' => null,
            'expectedVersion' => 'v0.1.0',
            'expectedConfigFilePath' => null,
            'configHome' => __DIR__,
        ];
    }

    #[DataProvider('configResolverProvider')]
    public function testGetConfig(ConfigInterface $default, ?string $cwd, ?string $configFile, string $expectedVersion, ?string $expectedConfigFilePath, ?string $configHome): void
    {
        $resolver = new Resolver($default, $cwd, $configHome);
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

    public function testResolvesConfigHomeFromXdgConfigHomeEnvironmentVariable(): void
    {
        $originalXdg = getenv('XDG_CONFIG_HOME');
        putenv('XDG_CONFIG_HOME='.dirname(__DIR__).'/fixtures/config-home');

        try {
            $resolver = new Resolver(new Config(), __DIR__);
            $config = $resolver->getConfig();

            $this->assertSame('9.9.9', (string) $config->initialVersion());
            $path = $resolver->configFilePath();
            $this->assertNotNull($path);
            $this->assertStringEndsWith('fixtures/config-home/imbo-releaser/config.php', $path);
        } finally {
            if (false === $originalXdg) {
                putenv('XDG_CONFIG_HOME');
            } else {
                putenv('XDG_CONFIG_HOME='.$originalXdg);
            }
        }
    }

    public function testResolvesConfigHomeFromHomeEnvironmentVariable(): void
    {
        $originalXdg = getenv('XDG_CONFIG_HOME');
        $originalHome = getenv('HOME');

        // The config home fixture mimics "$HOME/.config".
        putenv('XDG_CONFIG_HOME');
        putenv('HOME='.dirname(__DIR__).'/fixtures/home');

        try {
            $resolver = new Resolver(new Config(), __DIR__);
            $config = $resolver->getConfig();

            $this->assertSame('8.8.8', (string) $config->initialVersion());
            $path = $resolver->configFilePath();
            $this->assertNotNull($path);
            $this->assertStringEndsWith('fixtures/home/.config/imbo-releaser/config.php', $path);
        } finally {
            if (false === $originalXdg) {
                putenv('XDG_CONFIG_HOME');
            } else {
                putenv('XDG_CONFIG_HOME='.$originalXdg);
            }

            if (false === $originalHome) {
                putenv('HOME');
            } else {
                putenv('HOME='.$originalHome);
            }
        }
    }

    public function testFallsBackToDefaultWhenConfigHomeCannotBeDetermined(): void
    {
        $originalXdg = getenv('XDG_CONFIG_HOME');
        $originalHome = getenv('HOME');

        putenv('XDG_CONFIG_HOME');
        putenv('HOME');

        try {
            $resolver = new Resolver(new Config(), __DIR__);
            $config = $resolver->getConfig();

            $this->assertSame('v0.1.0', (string) $config->initialVersion());
            $this->assertNull($resolver->configFilePath());
        } finally {
            if (false === $originalXdg) {
                putenv('XDG_CONFIG_HOME');
            } else {
                putenv('XDG_CONFIG_HOME='.$originalXdg);
            }

            if (false === $originalHome) {
                putenv('HOME');
            } else {
                putenv('HOME='.$originalHome);
            }
        }
    }
}
