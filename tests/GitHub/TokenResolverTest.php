<?php declare(strict_types=1);

namespace ImboReleaser\Tests\GitHub;

use ImboReleaser\GitHub\TokenResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function dirname;
use function file_put_contents;
use function unlink;

use const PHP_BINARY;

#[CoversClass(TokenResolver::class)]
class TokenResolverTest extends TestCase
{
    private string $tmpDir;
    /** @var array<mixed> */
    private array $originalServer;
    /** @var array<mixed> */
    private array $originalEnv;
    private string|false $originalToken;

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
        $this->originalEnv = $_ENV;
        $this->originalToken = getenv('GITHUB_TOKEN');
        unset($_SERVER['GITHUB_TOKEN'], $_ENV['GITHUB_TOKEN'], $_SERVER['SYMFONY_DOTENV_VARS'], $_ENV['SYMFONY_DOTENV_VARS']);
        putenv('GITHUB_TOKEN');

        $this->tmpDir = __DIR__.'/tokenresolver_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $envFile = $this->tmpDir.'/.env';
        if (is_file($envFile)) {
            unlink($envFile);
        }

        rmdir($this->tmpDir);

        $_SERVER = $this->originalServer;
        $_ENV = $this->originalEnv;
        putenv(false === $this->originalToken ? 'GITHUB_TOKEN' : 'GITHUB_TOKEN='.$this->originalToken);
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
        file_put_contents($this->tmpDir.'/.env', "GITHUB_TOKEN=env-file-token\n");
        putenv('GITHUB_TOKEN=process-token');
        $_SERVER['GITHUB_TOKEN'] = 'server-token';
        $_ENV['GITHUB_TOKEN'] = 'env-token';

        $resolver = new TokenResolver($this->tmpDir);
        $this->assertSame('server-token', $resolver->getGitHubToken());
    }

    public function testResolvesFromEnv(): void
    {
        file_put_contents($this->tmpDir.'/.env', "GITHUB_TOKEN=env-file-token\n");
        putenv('GITHUB_TOKEN=process-token');
        $_ENV['GITHUB_TOKEN'] = 'env-token';

        $resolver = new TokenResolver($this->tmpDir);
        $this->assertSame('env-token', $resolver->getGitHubToken());
    }

    public function testResolvesFromProcessEnvironmentBeforeEnvFile(): void
    {
        putenv('GITHUB_TOKEN=process-token');
        file_put_contents($this->tmpDir.'/.env', "GITHUB_TOKEN=env-file-token\n");

        $resolver = $this->tokenResolver('github-cli-token');
        $this->assertSame('process-token', $resolver->getGitHubToken());
        $this->assertSame('process-token', $resolver->getGitHubToken());
    }

    /**
     * @return iterable<string,array{string|false,bool,string}>
     */
    public static function processEnvironmentProvider(): iterable
    {
        yield 'exported token' => ['process-token', false, 'process-token'];
        yield 'exported token beats file' => ['process-token', true, 'process-token'];
        yield 'zero is a token' => ['0', true, '0'];
        yield 'file without exported token' => [false, true, 'env-file-token'];
        yield 'CLI without token' => [false, false, 'github-cli-token'];
        yield 'empty exported token' => ['', false, 'github-cli-token'];
        yield 'empty exported token suppresses file' => ['', true, 'github-cli-token'];
    }

    #[DataProvider('processEnvironmentProvider')]
    public function testResolvesWithSuperglobalsDisabled(string|false $token, bool $envFile, string $expected): void
    {
        if ($envFile) {
            file_put_contents($this->tmpDir.'/.env', "GITHUB_TOKEN=env-file-token\n");
        }

        $code = <<<'PHP'
        require $argv[1];
        if (isset($_SERVER['GITHUB_TOKEN']) || isset($_ENV['GITHUB_TOKEN'])) {
            exit(1);
        }
        $resolver = new ImboReleaser\GitHub\TokenResolver(
            $argv[2],
            static fn (): string => "github-cli-token\n",
        );
        for ($i = 0; $i < 2; ++$i) {
            if ($resolver->getGitHubToken() !== $argv[3]) {
                exit(2);
            }
        }
        PHP;

        $process = new Process([
            PHP_BINARY,
            '-d',
            'variables_order=GPC',
            '-r',
            $code,
            dirname(__DIR__, 2).'/vendor/autoload.php',
            $this->tmpDir,
            $expected,
        ], $this->tmpDir, ['GITHUB_TOKEN' => $token, 'SYMFONY_DOTENV_VARS' => false]);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('', $process->getOutput());
    }

    public function testResolvesFromGitHubCli(): void
    {
        $resolver = $this->tokenResolver("github-cli-token\n");

        $this->assertSame('github-cli-token', $resolver->getGitHubToken());
    }

    public function testReturnsNullWhenGitHubCliFails(): void
    {
        $resolver = $this->tokenResolver(null);

        $this->assertNull($resolver->getGitHubToken());
    }

    public function testReturnsNullWhenGitHubCliReturnsEmptyOutput(): void
    {
        $resolver = $this->tokenResolver(" \n");

        $this->assertNull($resolver->getGitHubToken());
    }

    private function tokenResolver(?string $token): TokenResolver
    {
        return new TokenResolver($this->tmpDir, static fn (): ?string => $token);
    }
}
