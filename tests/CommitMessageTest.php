<?php declare(strict_types=1);

namespace ImboReleaser;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function dirname;

use const PHP_BINARY;

#[CoversNothing]
class CommitMessageTest extends TestCase
{
    /**
     * @return iterable<string,array{message:string,exitCode:int}>
     */
    public static function messageProvider(): iterable
    {
        yield 'uppercase' => ['message' => 'FEAT: add a feature', 'exitCode' => 1];
        yield 'mixed case' => ['message' => 'Feat: add a feature', 'exitCode' => 1];
        yield 'scoped breaking change' => ['message' => 'FIX(API)!: change behavior', 'exitCode' => 1];
        yield 'lowercase' => ['message' => 'feat: add a feature', 'exitCode' => 0];
        yield 'uppercase scope and description' => ['message' => 'fix(API)!: Change behavior', 'exitCode' => 0];
        yield 'custom type' => ['message' => 'custom: change behavior', 'exitCode' => 0];
    }

    #[DataProvider('messageProvider')]
    public function testRequiresLowercaseTypes(string $message, int $exitCode): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/scripts/conventional-commit-msg.php', $message]);
        $process->run();

        $this->assertSame($exitCode, $process->getExitCode(), $process->getErrorOutput());
        if (1 === $exitCode) {
            $this->assertStringContainsString('lowercase Conventional Commit type', $process->getErrorOutput());
        }
    }
}
