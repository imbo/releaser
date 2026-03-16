<?php declare(strict_types=1);

namespace ImboReleaser\Config;

use ImboReleaser\Config;
use ImboReleaser\ConfigInterface;
use InvalidArgumentException;

use function sprintf;

use const DIRECTORY_SEPARATOR;

final class Resolver
{
    private ?ConfigInterface $config = null;
    private ?string $configFilePath = null;
    private string $cwd;

    public function __construct(private ConfigInterface $defaultConfig = new Config(), ?string $cwd = null)
    {
        $this->cwd = $cwd ?? getcwd() ?: '';
    }

    /**
     * Get the configuration to use for the release process.
     *
     * Repeated calls to this method will return the same configuration instance, unless a new
     * configuration file path is provided.
     *
     * @throws InvalidArgumentException if an invalid configuration file path is provided
     */
    public function getConfig(?string $configFilePath = null): ConfigInterface
    {
        if (null !== $configFilePath && '' !== $configFilePath) {
            $file = $this->loadConfigFile($configFilePath);
            if (null === $file) {
                throw new InvalidArgumentException(sprintf('Config file "%s" is not readable, or does not return a valid configuration', $configFilePath));
            }

            [$this->config, $this->configFilePath] = $file;

            return $this->config;
        }

        if (null !== $this->config) {
            return $this->config;
        }

        $candidates = [
            '.imbo-releaser.php',
            '.imbo-releaser.dist.php',
        ];

        foreach ($candidates as $candidate) {
            $file = $this->loadConfigFile($candidate);
            if (null !== $file) {
                [$this->config, $this->configFilePath] = $file;
                break;
            }
        }

        if (null === $this->config) {
            $this->config = $this->defaultConfig;
        }

        return $this->config;
    }

    public function configFilePath(): ?string
    {
        return $this->configFilePath;
    }

    /**
     * @return ?array{0:ConfigInterface,1:string}
     */
    private function loadConfigFile(string $file): ?array
    {
        if (!str_starts_with($file, DIRECTORY_SEPARATOR)) {
            $file = $this->cwd.DIRECTORY_SEPARATOR.$file;
        }

        if (!file_exists($file) || !is_readable($file)) {
            return null;
        }

        $config = require $file;

        return $config instanceof ConfigInterface
            ? [$config, $file]
            : null;
    }
}
