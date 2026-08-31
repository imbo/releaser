<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use ImboReleaser\Exception\RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function array_merge;
use function implode;
use function max;
use function mb_strlen;
use function sprintf;
use function str_pad;
use function str_starts_with;

#[AsCommand(
    name: Commands::NAME,
    description: 'Show available commands',
    help: 'This command shows all available commands.',
    hidden: true,
)]
class Commands extends Command
{
    public const NAME = 'commands';

    /**
     * Execute the command's main logic.
     *
     * @return int The exit code of the command (0 for success, non-zero for failure)
     *
     * @throws RuntimeException If the command is not attached to an application
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication();
        if (null === $application) {
            throw new RuntimeException('The command is not attached to an application.');
        }

        $output->writeln($application->getLongVersion());
        $output->writeln('');

        $groups = $this->getCommandGroups($application);

        $width = 0;
        foreach (array_merge(...array_values($groups)) as $command) {
            $width = max($width, mb_strlen((string) $command->getName()));
        }

        foreach ($groups as $heading => $commands) {
            if ([] === $commands) {
                continue;
            }

            $output->writeln(sprintf('<comment>%s</comment>', $heading));
            $output->writeln('');

            foreach ($commands as $command) {
                $name = (string) $command->getName();

                /** @var list<string> */
                $aliases = $command->getAliases();

                $line = sprintf(
                    '<info>%s</info>   %s',
                    str_pad($name, $width),
                    $command->getDescription(),
                );

                if ([] !== $aliases) {
                    $line .= sprintf(' <comment>[%s]</comment>', implode(', ', $aliases));
                }

                $output->writeln($line);
            }

            $output->writeln('');
        }

        return self::SUCCESS;
    }

    /**
     * Get the visible commands grouped by origin, sorted by name within each group.
     *
     * Commands defined by this application (namespaced under "ImboReleaser\") are separated from
     * commands provided by Symfony.
     *
     * @return array<string,list<Command>>
     */
    private function getCommandGroups(Application $application): array
    {
        $own = [];
        $other = [];

        foreach ($application->all() as $command) {
            if ($command->isHidden() || null === $command->getName()) {
                continue;
            }

            if (str_starts_with($command::class, 'ImboReleaser\\')) {
                $own[$command->getName()] = $command;
            } else {
                $other[$command->getName()] = $command;
            }
        }

        ksort($own);
        ksort($other);

        return [
            'Available commands:' => array_values($own),
            'Other commands:' => array_values($other),
        ];
    }
}
