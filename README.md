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

- Git branches are named `main` or `master` (for development of the latest major version), and `X.x` (e.g. `1.x`) or `X.Y.x` (e.g. `1.2.x`) for maintenance releases. Maintenance branches may contain an optional `v` prefix (e.g. `v1.x` or `v1.2.x`), and do not have to include the `.x` suffix (e.g. `v1` or `v1.2`).
- Git tags are named `X.Y.Z` (e.g. `1.0.0`). Tags may also contain an optional `v` prefix (e.g. `v1.0.0`).
- Only pull requests are used when generating release notes and calculating the next version to release. Commits pushed directly to branches are ignored. The pull request titles must follow the [Conventional Commits](https://www.conventionalcommits.org/) specification.

  Types are matched case-insensitively: `feat:`, `Feat:`, and `FEAT:` have the same effect on version calculation and release-note grouping. The `BREAKING CHANGE` footer must remain uppercase.
- Release notes are attached to the GitHub release and annotated tags, and are not committed to the repository.
- It does all repository operations using the GitHub API, and not using a local checkout of your repository.

Release tags must end in a semantic version such as `1.2.3` or `v1.2.3`; other Git tags are ignored. Other tag prefixes are supported. Override `filterTag()` to exclude release tags and, when using maintenance branches, override `getLatestTagForBranch()` to define how the prefix maps to a branch.

If reading from GitHub fails because of a connection problem, temporary server error, or rate limit, Imbo Releaser retries up to three times. It waits as instructed by GitHub, up to one minute. If GitHub requires a longer wait, the command fails so you can try again later.

Once installed you can see the available commands and documentation by running the `imbo-releaser` script.

The commands described below share a few common options, most notably `--repository` / `-r` for specifying the GitHub repository and `--config` / `-c` for pointing to a configuration file. If you omit `--config`, the application looks for a configuration file in the [locations described below](#where-the-configuration-is-loaded-from). If you omit `--repository`, it uses the repository from your configuration or asks you to enter one when running interactively. Run any command with `--help` to see all available options and arguments.

For non-interactive environments such as CI, pass `--no-interaction` (or `-n`) and provide every value that would otherwise be prompted for. For example:

```bash
imbo-releaser create --no-interaction --no-edit --repository owner/repo --branch main
```

### Create a new release

```bash
imbo-releaser create --help
```

This command calculates the next version, generates release notes from the pull requests merged since the previous release, and creates an annotated Git tag and a GitHub release. By default it opens your editor so you can review and adjust the release notes, and asks for confirmation before anything is created.

When a previous tag exists, the application checks which pull requests added commits since that tag. It uses commit identifiers rather than dates, so differences between a commit's date and the time its pull request was merged do not cause already released changes to be included again. If GitHub does not provide the resulting commit's identifier for a pull request, that pull request is skipped and affects neither the release notes nor the next version.

Pass `--name` to set the GitHub release name; otherwise the calculated version is used. Pass `--draft` to create the GitHub release as a draft.

GitHub chooses the latest release using its [date and semantic-version policy](https://docs.github.com/en/rest/releases/releases?apiVersion=2026-03-10#create-a-release); publishing a maintenance release does not force it to become latest. Drafts and prereleases are not eligible to be latest.

Pass `--prerelease <identifier>` to create a prerelease, for example `--prerelease rc` creates `v1.2.3-rc.1`. Repeating the command with the same identifier increments the prerelease number, such as `v1.2.3-rc.2`. Run the command without `--prerelease` to create the stable release; prerelease tags do not affect stable version calculation.

As required by [Semantic Versioning](https://semver.org/spec/v2.0.0.html#spec-item-9), prerelease identifiers made entirely of digits cannot have leading zeros. For example, `0` and `1` are allowed, but `01` is not. Identifiers containing letters or hyphens, such as `rc` or `01alpha`, are also allowed.

Pass `--dry-run` to preview the calculated release and release notes without creating a tag or release.

### Example release workflow

Imbo Releaser calculates the next version from the titles of merged pull requests. Use a [Conventional Commit](https://www.conventionalcommits.org/) title when creating a pull request:

```bash
git switch -c feat/add-export
git commit -am "feat: add export command"
git push --set-upstream origin feat/add-export
gh pr create --title "feat: add export command" --base main
gh pr merge --merge --delete-branch
```

After the pull request is merged, create a release candidate. With a latest stable tag of `v1.2.3`, the feature pull request makes the next version `v1.3.0`:

```bash
imbo-releaser create --repository owner/repo --branch main --prerelease rc --name "Version 1.3 release candidate"
# Creates the v1.3.0-rc.1 tag and GitHub prerelease
```

Run the same command after further changes to create the next candidate:

```bash
imbo-releaser create --repository owner/repo --branch main --prerelease rc --name "Version 1.3 release candidate"
# Creates the v1.3.0-rc.2 tag and GitHub prerelease
```

When the candidate is ready to publish, omit `--prerelease` to create the stable release:

```bash
imbo-releaser create --repository owner/repo --branch main --name "Version 1.3"
# Creates the v1.3.0 tag and GitHub release
```

### List existing releases

```bash
imbo-releaser list --help
```

This command prints a table of the existing releases in the repository, including the release name, tag name, and publication date, with the most recently published releases first. The date is when the release was published on GitHub, rather than when its commit was created.

Releases with no publication date show `Unknown` after dated releases. Drafts show `Draft` and appear last. Entries with the same date, unknown dates, or draft status are ordered alphabetically by tag name.

Releases without a name are displayed using their tag name.

### Delete a release

```bash
imbo-releaser delete --help
```

This command deletes a GitHub release and its associated Git tag. If no version is given, you are prompted to select a release to delete.

Pass `-d` or `--dry-run` with a version to preview the deletion without deleting the GitHub release or tag.

The GitHub release and its tag are deleted in separate steps. If the release is deleted but deleting the tag fails, remove the remaining tag with:

```bash
imbo-releaser delete --tag-only 1.2.3
```

## Exit codes

| Code | Meaning                                                   |
| ---- | --------------------------------------------------------- |
| `0`  | Success                                                   |
| `1`  | Error                                                     |
| `2`  | Invalid usage (e.g. missing required argument)            |
| `3`  | Aborted by the user (e.g. declined a confirmation prompt) |

## Configuration

To customize behavior, provide a configuration file that returns an instance of `ImboReleaser\ConfigInterface`. The easiest approach is to extend the `ImboReleaser\Config` class, which holds the default configuration values, and override only what you need:

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

### Extending default policies

Override the protected default-provider methods to add to the built-in branch names, excluded usernames, or excluded pull request labels. Call the parent method to retain future defaults:

```php
return new class extends Config {
    protected function usernamesToExclude(): array
    {
        return [
            ...parent::usernamesToExclude(),
            'renovate[bot]',
        ];
    }
};
```

By default, pull requests authored by `dependabot[bot]` and pull requests labelled `skip-release` are excluded. Excluded pull requests are not included in release notes and do not affect the calculated version.

### Where the configuration is loaded from

You can choose a configuration file with the `--config` / `-c` option. Otherwise the application checks the following locations in order and uses the first configuration file it finds:

1. `.imbo-releaser.php` in the current working directory
2. `.imbo-releaser.dist.php` in the current working directory
3. `config.php` in your config home (`$XDG_CONFIG_HOME/imbo-releaser/config.php`, falling back to `~/.config/imbo-releaser/config.php`)

Only the first file found is used; settings from different files are not combined. You can keep a personal configuration in your user configuration directory for projects that don't provide their own. A configuration file in the working directory is used instead when present. If none of these files exist, the built-in defaults are used.

## Authentication

Imbo Releaser requires a GitHub API token to interact with the GitHub API. It looks for a token in the following places, in order:

1. The `GITHUB_TOKEN` environment variable (also loaded from a `.env` file in the current directory if present; externally provided values take precedence)
2. The output of `gh auth token` (requires the [GitHub CLI](https://cli.github.com/) to be installed and authenticated)

If neither source provides a token, the application will exit with an error.

## Release notes templates

Release notes are generated using [Twig](https://twig.symfony.com/) templates. The default template groups changes by Conventional Commit type and credits the contributors.

To use a custom template, either override `template()` in your config or pass `--template` on the command line when running the `create` command.

Templates produce Markdown, so characters such as `<`, `>`, and `&` are preserved rather than converted to HTML entities. This keeps code examples and links intact.

### Available template variables

The following variables are available in all templates:

| Variable              | Type                                     | Description                                                                                                                             |
| --------------------- | ---------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| `nextVersion`         | `Version`                                | The next version being released (e.g. `1.2.0`)                                                                                          |
| `repository`          | `Repository`                             | The GitHub repository                                                                                                                   |
| `pullRequests`        | `list<ReleasePullRequest>`               | All filtered pull requests included in this release                                                                                     |
| `groupedPullRequests` | `array<string,list<ReleasePullRequest>>` | Pull requests grouped by their Conventional Commit type label, as defined by `pullRequestGroups()` and `fallbackGroup()` in your config |
| `newContributors`     | `array<string,ReleasePullRequest>`       | Map of username to their first pull request, for contributors making their first contribution in this release                           |
| `releaserVersion`     | `?string`                                | Installed Imbo Releaser version, if available                                                                                           |

## Version calculation

The next version is determined automatically based on the Conventional Commit types of merged pull requests:

| Condition                                                                | Version bump    |
| ------------------------------------------------------------------------ | --------------- |
| Any PR has a breaking change (e.g. `feat!:` or `BREAKING CHANGE` footer) | Major (`X.0.0`) |
| Any PR is a feature (`feat:`)                                            | Minor (`x.Y.0`) |
| Otherwise                                                                | Patch (`x.y.Z`) |

If no tags exist yet, the configured `initialVersion()` is used (default `v0.1.0`).

## Maintainer release procedure

If dependency updates are intended for the release, update them deliberately and review the lockfile changes before committing them:

```bash
composer update
git diff -- composer.json composer.lock
```

Before creating a release of Imbo Releaser, verify the release commit with:

```bash
composer validate --strict
composer run ci
```

Install the candidate in a clean Composer project using both supported installation modes, then verify that `imbo-releaser --help`, `imbo-releaser --version`, and an authenticated API command work:

```bash
composer global require imbo/releaser:<version>
composer require --dev imbo/releaser:<version>
```

## License

MIT, see [LICENSE](LICENSE).
