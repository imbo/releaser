<?php declare(strict_types=1);

namespace ImboReleaser\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(ProgressIndicator::class)]
class ProgressIndicatorTest extends TestCase
{
    public function testDoesNotRenderToNonDecoratedOutput(): void
    {
        $output = new BufferedOutput();
        $progress = new ProgressIndicator($output);
        $progress->start('Fetching releases...');
        $progress->advance();
        $progress->finish('Fetched releases');

        $this->assertSame('', $output->fetch());
    }

    public function testRendersToDecoratedOutput(): void
    {
        $output = new BufferedOutput(decorated: true);
        $progress = new ProgressIndicator($output);
        $progress->start('Fetching releases...');
        $progress->finish('Fetched releases');

        $this->assertStringContainsString('Fetched releases', $output->fetch());
    }
}
