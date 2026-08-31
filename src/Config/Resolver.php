<?php declare(strict_types=1);

namespace ImboReleaser\Config;

use ImboReleaser\Config;
use ImboReleaser\ConfigInterface;
use ImboReleaser\Exception\InvalidArgumentException;
use Throwable;

use function getenv;
use function is_string;
use function rtrim;
use function sprintf;

use const DIRECTORY_SEPARATOR;

final class Resolver
{
    /**
     * Name of the directory used to store the configuration file in the user's config directory.
     */
    private const string CONFIG_DIR_NAME = 'imbo-releaser';

    /**
     * Name of the configuration file looked for in the user's config directory.
     */
    private const string CONFIG_DIR_FILE = 'config.php';

    private ?ConfigInterface $config = null;
    private ?string $configFilePath = null;
    private string $cwd;
    private ?string $configHome;

    /**
     * @param ?string $cwd        The current working directory used to resolve relative config files. Defaults to the process working directory.
     * @param ?string $configHome The user's configuration directory used to locate a global config file. Defaults to $XDG_CONFIG_HOME or ~/.config.
     */
    public function __construct(private ConfigInterface $defaultConfig = new Config(), ?string $cwd = null, ?string $configHome = null)
    {
        $this->cwd = $cwd ?? getcwd() ?: '';
        $this->configHome = $configHome ?? $this->defaultConfigHome();
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

        foreach ($this->candidateFiles() as $candidate) {
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
     * Get the list of candidate configuration files to look for, in order of precedence.
     *
     * @return list<string>
     */
    private function candidateFiles(): array
    {
        $candidates = [
            '.imbo-releaser.php',
            '.imbo-releaser.dist.php',
        ];

        if (null !== $this->configHome) {
            $candidates[] = rtrim($this->configHome, DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR.self::CONFIG_DIR_NAME
                .DIRECTORY_SEPARATOR.self::CONFIG_DIR_FILE;
        }

        return $candidates;
    }

    /**
     * Resolve the user's configuration directory.
     *
     * Uses $XDG_CONFIG_HOME if set, otherwise falls back to $HOME/.config.
     */
    private function defaultConfigHome(): ?string
    {
        $xdgConfigHome = getenv('XDG_CONFIG_HOME');
        if (is_string($xdgConfigHome) && '' !== $xdgConfigHome) {
            return $xdgConfigHome;
        }

        $home = getenv('HOME');
        if (is_string($home) && '' !== $home) {
            return $home.DIRECTORY_SEPARATOR.'.config';
        }

        return null;
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

        try {
            $config = require $file;
        } catch (Throwable $e) {
            throw new InvalidArgumentException(sprintf('Config file "%s" could not be loaded: %s', $file, $e->getMessage()), previous: $e);
        }

        return $config instanceof ConfigInterface
            ? [$config, $file]
            : null;
    }
}
