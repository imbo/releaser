<?php declare(strict_types=1);

namespace ImboReleaser\Tests\GitHub;

use ImboReleaser\GitHub\TokenResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(TokenResolver::class)]
class TokenResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/tokenresolver_test';
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir);
        }
    }

    protected function tearDown(): void
    {
        $envFile = $this->tmpDir.'/.env';
        if (is_file($envFile)) {
            unlink($envFile);
        }

        unset($_SERVER['GITHUB_TOKEN'], $_ENV['GITHUB_TOKEN']);
    }

    public function testResolvesFromEnvFile(): void
    {
        $envFile = $this->tmpDir.'/.env';
        file_put_contents($envFile, "GITHUB_TOKEN=env-file-token\n");

        $resolver = new TokenResolver($this->tmpDir);
        $this->assertSame('env-file-token', $resolver->getGitHubToken());
    }

    public function testResolvesFromServer(): void
    {
        $_SERVER['GITHUB_TOKEN'] = 'server-token';
        $_ENV['GITHUB_TOKEN'] = 'env-token';

        $resolver = new TokenResolver($this->tmpDir);
        $this->assertSame('server-token', $resolver->getGitHubToken());
    }

    public function testResolvesFromEnv(): void
    {
        unset($_SERVER['GITHUB_TOKEN']);
        $_ENV['GITHUB_TOKEN'] = 'env-token';

        $resolver = new TokenResolver($this->tmpDir);
        $this->assertSame('env-token', $resolver->getGitHubToken());
    }
}
