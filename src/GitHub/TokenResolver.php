<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

use function is_string;

final class TokenResolver
{
    private readonly ?string $cwd;

    public function __construct(?string $cwd = null)
    {
        $this->cwd = $cwd ?? (getcwd() ?: null);
    }

    /**
     * Resolves a GitHub token.
     *
     * Returns a GitHub token from the GITHUB_TOKEN environment variable or by running
     * `gh auth token`. If a .env file is present in the current working directory it will be
     * loaded.
     */
    public function getGitHubToken(): ?string
    {
        if (null !== $this->cwd) {
            $envFile = $this->cwd.'/.env';
            if (is_file($envFile)) {
                (new Dotenv())->load($envFile);
            }
        }

        $token = $_SERVER['GITHUB_TOKEN'] ?? $_ENV['GITHUB_TOKEN'] ?? null;
        if (is_string($token) && '' !== $token) {
            return $token;
        }

        // @codeCoverageIgnoreStart
        $process = new Process(['gh', 'auth', 'token']);
        $process->run();
        if ($process->isSuccessful()) {
            $token = trim($process->getOutput());
            if ('' !== $token) {
                return $token;
            }
        }

        return null;
        // @codeCoverageIgnoreEnd
    }
}
