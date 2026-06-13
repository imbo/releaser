# Imbo Releaser

Imbo Releaser is a configurable CLI application designed to simplify the release process for projects hosted on GitHub.

When creating a release, Imbo Releaser will:

- Create a new annotated Git tag and a corresponding GitHub release for a selected branch
- Generate release notes based on [Conventional Commits](https://www.conventionalcommits.org/) since the previous release on that branch

## Requirements

- [PHP](https://www.php.net/) 8.3 or later
- [Composer](https://getcomposer.org/) installed
- A GitHub API token (see [Authentication](#authentication))

## Installation

The recommended way is to install Imbo Releaser globally using Composer:

```bash
composer global require imbo/releaser
imbo-releaser # display available commands and options
```

The snippet above requires you to add the global vendor binaries directory to your `$PATH` environment variable. You can get the absolute path to the directory by running the following command:

```bash
composer global config bin-dir --absolute -q
```

## Usage

Imbo Releaser is designed to be run from the command line. It makes certain assumptions about the layout of your repository, but it is highly configurable and can be used in a wide variety of scenarios. See the [Configuration](#configuration) section below for more details.

The key points regarding how Imbo Releaser works out of the box are as follows:

- Git branches are named `main` or `master` (for development of the latest major version), and `X.x` (e.g. `1.x`) for maintenance of older major versions. The `X.x` branches may contain an optional `v` prefix (e.g. `v1.x`), and does not have to include the `.x` suffix (e.g. `v1`).
- Git tags are named `X.Y.Z` (e.g. `1.0.0`). Tags may also contain an optional `v` prefix (e.g. `v1.0.0`).
- Only pull requests are used when generating release notes and calculating the next version to release. Commits pushed directly to branches are ignored. The pull request titles must follow the [Conventional Commits](https://www.conventionalcommits.org/) specification.
- Release notes are attached to the GitHub release and annotated tags, and are not committed to the repository.
- It does all repository operations using the GitHub API, and not using a local checkout of your repository.

Once installed you can see the available commands and documentation by running the `imbo-releaser` script.

The commands described below share a few common options, most notably `--repository` / `-r` for specifying the GitHub repository and `--config` / `-c` for pointing to a configuration file. When these are not provided, they are resolved from your [configuration](#configuration) or, in interactive mode, prompted for. Run any command with `--help` to see all available options and arguments.

### Create a new release

```bash
imbo-releaser create-release --help # aliases: create, release
```

This command calculates the next version, generates release notes from the pull requests merged since the previous release, and creates an annotated Git tag and a GitHub release. By default it opens your editor so you can review and adjust the release notes, and asks for confirmation before anything is created. If no branch is given, you are prompted to select one from the repository.

### List existing releases

```bash
imbo-releaser list-releases --help # aliases: ls, list, releases
```

This command prints a table of the existing releases in the repository, including the release name, tag name, and release date.

### Delete a release

```bash
imbo-releaser delete-release --help # alises: rm, delete
```

This command deletes a GitHub release and, by default, its associated Git tag. Pass `--keep-tag` to delete only the release and keep the tag. If no version is given, you are prompted to select a release to delete.

## Configuration

To customize behavior, create a file named `.imbo-releaser.php` (or `.imbo-releaser.dist.php`) in your project root. The file must return an instance of `ImboReleaser\ConfigInterface`. The easiest approach is to extend the `ImboReleaser\Config` class and override only what you need:

```php
<?php declare(strict_types=1);

use ImboReleaser\Config;

return new class extends Config {
    public function gitHubRepository(): ?string
    {
        return 'myorg/myproject';
    }

    public function branch(): ?string
    {
        return 'main';
    }
};
```

You can also point to an explicit config file using the `--config` / `-c` option.

## Authentication

Imbo Releaser requires a GitHub API token to interact with the GitHub API. The token is resolved in the following order:

1. The `GITHUB_TOKEN` environment variable (also loaded from a `.env` file in the current directory if present)
2. The output of `gh auth token` (requires the [GitHub CLI](https://cli.github.com/) to be installed and authenticated)

If neither source provides a token, the application will exit with an error.

## Release notes templates

Release notes are generated using [Twig](https://twig.symfony.com/) templates. The built-in default template produces output grouped by Conventional Commit type with contributor attribution.

To use a custom template, either override `template()` in your config or pass `--template` on the command line when running the `create-release` command.

## Version calculation

The next version is determined automatically based on the Conventional Commit types of merged pull requests:

| Condition                                                                | Version bump    |
| ------------------------------------------------------------------------ | --------------- |
| Any PR has a breaking change (e.g. `feat!:` or `BREAKING CHANGE` footer) | Major (`X.0.0`) |
| Any PR is a feature (`feat:`)                                            | Minor (`x.Y.0`) |
| Otherwise                                                                | Patch (`x.y.Z`) |

If no tags exist yet, the configured `initialVersion()` is used (default `v0.1.0`).

## License

MIT, see [LICENSE](LICENSE).
