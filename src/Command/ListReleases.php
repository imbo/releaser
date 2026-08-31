<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use ImboReleaser\Console\ProgressIndicator;
use ImboReleaser\GitHub\Release;
use ImboReleaser\GitHub\Repository;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

#[AsCommand(
    name: ListReleases::NAME,
    description: 'List releases',
)]
class ListReleases extends BaseCommand
{
    public const NAME = 'list';

    protected function commandHelp(): string
    {
        return 'This command will list all releases of a project on GitHub.';
    }

    /**
     * Execute the command's main logic.
     *
     * @return int The exit code of the command (0 for success, non-zero for failure)
     *
     * @throws InvalidArgumentException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $releases = $this->getReleases($this->getRepository($input), $output);
        if ([] === $releases) {
            $output->writeln('<info>No releases found for the repository.</info>');

            return self::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Name', 'Tag name', 'Release date'])
            ->setRows(array_map(
                static fn (Release $release): array => [
                    sprintf('<href=%s>%s</>', $release->htmlUrl, $release->name),
                    $release->tagName,
                    $release->createdAt->format('Y-m-d H:i:s'),
                ],
                $releases,
            ))
            ->render();

        return self::SUCCESS;
    }

    /**
     * @return list<Release>
     */
    private function getReleases(Repository $repository, OutputInterface $output): array
    {
        $progress = new ProgressIndicator($output);
        $progress->start('Fetching releases...');

        $releases = [];
        foreach ($this->gitHubClient->getReleases($repository) as $release) {
            $progress->advance();
            if (!$this->config->filterRelease($release)) {
                continue;
            }

            $releases[] = $release;
        }

        $progress->finish('Fetched releases');

        return $releases;
    }
}
