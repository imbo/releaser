<?php declare(strict_types=1);

namespace ImboReleaser\Config;

use ImboReleaser\ConfigInterface;
use InvalidArgumentException;

use function sprintf;

final class Resolver
{
    private ?ConfigInterface $config = null;
    private ?string $configFilePath = null;

    /**
     * @throws InvalidArgumentException if an invalid configuration file path is provided
     */
    public function __construct(private ConfigInterface $defaultConfig, private ?string $cwd = null, ?string $configFilePath = null)
    {
        if (null !== $configFilePath) {
            $config = $this->loadConfigFile($configFilePath);
            if (null === $config) {
                throw new InvalidArgumentException(sprintf('Config file "%s" is not readable, or does not return a valid configuration', $configFilePath));
            }

            $this->config = $config;
            $this->configFilePath = $configFilePath;
        } elseif (null === $cwd) {
            $this->config = $this->defaultConfig;
        }
    }

    public function getConfig(): ConfigInterface
    {
        if (null !== $this->config) {
            return $this->config;
        }

        $candidates = [
            sprintf('%s/.imbo-releaser.php', $this->cwd),
            sprintf('%s/.imbo-releaser.dist.php', $this->cwd),
        ];

        foreach ($candidates as $file) {
            $config = $this->loadConfigFile($file);
            if (null !== $config) {
                $this->configFilePath = $file;
                break;
            }
        }

        if (null === $config) {
            $config = $this->defaultConfig;
        }

        $this->config = $config;

        return $this->config;
    }

    public function configFilePath(): ?string
    {
        return $this->configFilePath;
    }

    private function loadConfigFile(string $file): ?ConfigInterface
    {
        if (!file_exists($file) || !is_readable($file)) {
            return null;
        }

        $config = require $file;
        if ($config instanceof ConfigInterface) {
            return $config;
        }

        return null;
    }
}
