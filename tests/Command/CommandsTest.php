<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

use function strpos;

#[CoversClass(Commands::class)]
class CommandsTest extends TestCase
{
    public function testListsAvailableCommands(): void
    {
        $application = new Application('Test app');
        $application->addCommand(new Commands());
        $application->addCommand(new HiddenCommand());
        $application->addCommand(new AliasedCommand());

        /** @var Commands */
        $command = $application->get(Commands::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute([], ['interactive' => false]);

        $this->assertSame(Commands::SUCCESS, $commandTester->getStatusCode());
        $display = $commandTester->getDisplay();

        $this->assertStringContainsString('Test app', $display);
        $this->assertStringContainsString('Available commands:', $display);

        $this->assertStringContainsString('aliased', $display);
        $this->assertStringContainsString('An aliased command', $display);
        $this->assertStringContainsString('[a, b]', $display);
    }

    public function testGroupsOwnAndOtherCommands(): void
    {
        $application = new Application('Test app');
        $application->addCommand(new Commands());
        $application->addCommand(new AliasedCommand());
        $application->addCommand(new HelpCommand());

        /** @var Commands */
        $command = $application->get(Commands::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute([], ['interactive' => false]);

        $display = $commandTester->getDisplay();

        $this->assertStringContainsString('Available commands:', $display);
        $this->assertStringContainsString('Other commands:', $display);

        $ownHeading = strpos($display, 'Available commands:');
        $otherHeading = strpos($display, 'Other commands:');
        $aliased = strpos($display, 'aliased');
        $help = strpos($display, 'help');

        $this->assertNotFalse($ownHeading);
        $this->assertNotFalse($otherHeading);
        $this->assertNotFalse($aliased);
        $this->assertNotFalse($help);

        // The "aliased" command (ImboReleaser namespace) is listed under the own
        // heading, while "help" (Symfony) is listed under the other heading.
        $this->assertLessThan($otherHeading, $aliased);
        $this->assertLessThan($help, $otherHeading);
    }

    public function testDoesNotListHiddenCommands(): void
    {
        $application = new Application('Test app');
        $application->addCommand(new Commands());
        $application->addCommand(new HiddenCommand());

        /** @var Commands */
        $command = $application->get(Commands::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute([], ['interactive' => false]);

        $this->assertStringNotContainsString('hidden', $commandTester->getDisplay());
    }

    public function testThrowsWhenNotAttachedToAnApplication(): void
    {
        $command = new Commands();
        $commandTester = new CommandTester($command);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The command is not attached to an application.');
        $commandTester->execute([], ['interactive' => false]);
    }
}

#[AsCommand(name: 'hidden', hidden: true)]
class HiddenCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}

#[AsCommand(name: 'aliased', description: 'An aliased command', aliases: ['a', 'b'])]
class AliasedCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}
