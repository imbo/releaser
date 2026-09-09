<?php declare(strict_types=1);

use ImboReleaser\Config;

return new class extends Config {
    public function gitHubRepository(): ?string
    {
        return 'imbo/releaser';
    }

    public function branch(): ?string
    {
        return 'main';
    }

    protected function initialVersionString(): string
    {
        return 'v1.0.0';
    }

    protected function usernamesToExclude(): array
    {
        return [
            ...parent::usernamesToExclude(),
            'imbo-automation[bot]',
        ];
    }
};
