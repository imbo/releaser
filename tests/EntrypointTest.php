<?php declare(strict_types=1);

namespace ImboReleaser;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function dirname;

use const PHP_BINARY;

#[CoversNothing]
class EntrypointTest extends TestCase
{
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
