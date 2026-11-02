<?php declare(strict_types=1);

namespace ImboReleaser;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function dirname;

use const PHP_BINARY;
use const PHP_EOL;

#[CoversNothing]
class EntrypointTest extends TestCase
{
    public function testMissingAutoloaderReturnsAnActionableError(): void
    {
        $filesystem = new Filesystem();
        $directory = sys_get_temp_dir().'/imbo-releaser-'.bin2hex(random_bytes(8));
        // Keep every candidate autoloader path inside the isolated directory.
        $entrypoint = $directory.'/package/bin/imbo-releaser';

        try {
            $filesystem->mkdir(dirname($entrypoint));
            $filesystem->copy(dirname(__DIR__).'/imbo-releaser', $entrypoint);

            $process = new Process([PHP_BINARY, $entrypoint, '--help']);
            $process->run();

            $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame('', $process->getOutput());
            $this->assertSame(
                'Error: Unable to locate autoload.php. Run "composer install" in the project directory to install dependencies.'.PHP_EOL,
                $process->getErrorOutput(),
            );
        } finally {
            $filesystem->remove($directory);
        }
    }

    /**
     * @return iterable<string,array{arguments:list<string>,message:string}>
     */
    public static function invalidUsageProvider(): iterable
    {
        yield 'unknown command' => ['arguments' => ['not-a-command'], 'message' => 'not defined'];
        yield 'unknown option' => ['arguments' => ['list', '--unknown-option'], 'message' => 'does not exist'];
        yield 'missing option value' => ['arguments' => ['create', '--branch'], 'message' => 'requires a value'];
        yield 'extra argument' => ['arguments' => ['list', 'extra'], 'message' => 'No arguments expected'];
        yield 'invalid repository' => ['arguments' => ['list', '--repository', 'owner/'], 'message' => 'Invalid repository string'];
        yield 'invalid version' => ['arguments' => ['delete', '--repository', 'owner/repo', 'invalid'], 'message' => 'Invalid version'];
        yield 'invalid template' => ['arguments' => ['create', '--repository', 'owner/repo', '--branch', 'main', '--template', '/missing/template.twig'], 'message' => 'specified template file'];
        yield 'invalid prerelease' => ['arguments' => ['create', '--repository', 'owner/repo', '--branch', 'main', '--prerelease', '01'], 'message' => 'Invalid prerelease identifier'];
        yield 'create repository' => ['arguments' => ['create', '--branch', 'main'], 'message' => 'Specify a GitHub repository'];
        yield 'list repository' => ['arguments' => ['list'], 'message' => 'Specify a GitHub repository'];
        yield 'delete repository' => ['arguments' => ['delete', 'v1.0.0'], 'message' => 'Specify a GitHub repository'];
        yield 'create branch' => ['arguments' => ['create', '--repository', 'owner/repo'], 'message' => 'Specify a branch'];
        yield 'delete version' => ['arguments' => ['delete', '--repository', 'owner/repo'], 'message' => 'Specify the version to delete'];
        yield 'tag-only version' => ['arguments' => ['delete', '--repository', 'owner/repo', '--tag-only'], 'message' => 'Specify the version to delete'];
        yield 'dry-run version' => ['arguments' => ['delete', '--repository', 'owner/repo', '--dry-run'], 'message' => 'Specify the version to delete'];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('invalidUsageProvider')]
    public function testInvalidUsageReturnsTwo(array $arguments, string $message): void
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__).'/imbo-releaser',
            ...$arguments,
            '--no-interaction',
            '--no-ansi',
            '--config',
            __DIR__.'/fixtures/valid-custom-config-1.php',
        ], env: ['COLUMNS' => '200']);
        $process->run();

        $this->assertSame(2, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString($message, $process->getErrorOutput());
    }

    public function testInvalidConfigReturnsInvalidUsage(): void
    {
        $process = new Process([
            PHP_BINARY, dirname(__DIR__).'/imbo-releaser', 'list', '--no-interaction',
            '--config', __DIR__.'/fixtures/invalid-custom-config.php',
        ], env: ['COLUMNS' => '200']);
        $process->run();

        $this->assertSame(2, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('does not return a valid configuration', $process->getErrorOutput());
    }

    public function testHelpDoesNotRequireGitHubToken(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/imbo-releaser', '--help'], env: [
            'GITHUB_TOKEN' => false,
            'PATH' => '/tmp',
        ]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('Show available commands', $process->getOutput());
    }

    public function testVersionDoesNotRequireGitHubToken(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/imbo-releaser', '--version'], env: [
            'GITHUB_TOKEN' => false,
            'PATH' => '/tmp',
        ]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringStartsWith('Imbo releaser ', $process->getOutput());
    }
}
