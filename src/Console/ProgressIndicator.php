<?php declare(strict_types=1);

namespace ImboReleaser\Console;

use Symfony\Component\Console\Helper\ProgressIndicator as SymfonyProgressIndicator;
use Symfony\Component\Console\Output\OutputInterface;

class ProgressIndicator extends SymfonyProgressIndicator
{
    public function __construct(private readonly OutputInterface $output)
    {
        parent::__construct($output);
    }

    public function start(string $message): void
    {
        if (!$this->output->isDecorated()) {
            return;
        }

        parent::start($message);
    }

    public function advance(): void
    {
        if (!$this->output->isDecorated()) {
            return;
        }

        parent::advance();
    }

    public function finish(string $message): void
    {
        if (!$this->output->isDecorated()) {
            return;
        }

        parent::finish($message);
    }
}
