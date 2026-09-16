<?php declare(strict_types=1);

namespace ImboReleaser\Command;

use ImboReleaser\Console\ProgressIndicator;
use ImboReleaser\Exception\InvalidArgumentException;
use ImboReleaser\GitHub\Release;
use ImboReleaser\GitHub\Repository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;
use function strcmp;

#[AsCommand(
    name: ListReleases::NAME,
    description: 'List releases',
)]
class ListReleases extends BaseCommand
{
    public const NAME = 'list';

    protected function commandHelp(): string
    {
        return <<<'HELP'
        This command lists GitHub releases whose tags match the supported version format,
        including prereleases and custom prefixes (e.g. 1.2.3, v1.2.3-rc.1, or release-1.2.3).
        Releases with tags such as nightly are not listed.
        HELP;
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
        $repository = $this->getRepository($input);
        $releases = $this->getReleases($repository, $output);
        if ([] === $releases) {
            $output->writeln(sprintf('<info>No releases with supported version tags found in repository "%s".</info>', $repository));

            return self::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Name', 'Tag name', 'Release date'])
            ->setRows(array_map(
                static fn (Release $release): array => [
                    sprintf('<href=%s>%s</>', $release->htmlUrl, $release->name),
                    $release->tagName,
                    $release->draft ? 'Draft' : ($release->publishedAt?->format('Y-m-d H:i:s') ?? 'Unknown'),
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
            if (null === $release->version) {
                continue;
            }

            $releases[] = $release;
        }

        $progress->finish('Fetched releases');

        usort($releases, static fn (Release $a, Release $b): int => ($a->draft <=> $b->draft)
            ?: ($a->draft ? 0 : ($b->publishedAt <=> $a->publishedAt))
            ?: strcmp($a->tagName, $b->tagName));

        return $releases;
    }
}
