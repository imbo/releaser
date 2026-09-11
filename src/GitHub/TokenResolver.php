<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use Closure;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

use function is_string;

class TokenResolver
{
    private readonly ?string $cwd;
    /** @var Closure():?string */
    private readonly Closure $gitHubCliOutput;

    /**
     * @param ?Closure():?string $gitHubCliOutput
     */
    public function __construct(?string $cwd = null, ?Closure $gitHubCliOutput = null)
    {
        $this->cwd = $cwd ?? (getcwd() ?: null);
        $this->gitHubCliOutput = $gitHubCliOutput ?? static function (): ?string {
            // @codeCoverageIgnoreStart
            $process = new Process(['gh', 'auth', 'token']);
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : null;
            // @codeCoverageIgnoreEnd
        };
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

        $token = ($this->gitHubCliOutput)();
        if (null === $token) {
            return null;
        }

        $token = trim($token);

        return '' !== $token ? $token : null;
    }
}
