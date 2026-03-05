# Imbo Releaser

⚠️ This project is under active development and is not yet ready for testing. ⚠️

Imbo Releaser is a configurable CLI application designed to simplify the release process for projects hosted on GitHub.

When creating a release, Imbo Releaser will:

- Create a new annotated Git tag and an optional corresponding GitHub release for a selected branch
- Generate release notes based on [Conventional Commits](https://www.conventionalcommits.org/) since the previous release on that branch

## Requirements

- [PHP](https://www.php.net/) 8.3 or later
- [Composer](https://getcomposer.org/) installed

## Installation

Install as a development dependency using Composer:

```bash
composer require --dev imbo/releaser
```

Or install globally:

```bash
composer global require imbo/releaser
```

## Usage

Imbo Releaser is designed to be run from the command line. It makes certain assumptions about the layout of your repository, but it is highly configurable and can be used in a wide variety of scenarios. See the [Configuration](#configuration) section below for more details.

The key points regarding how Imbo Releaser works are as follows:

- Git branches are named `main` or `master` (for development of the latest major version), and `X.x` (e.g. `1.x`) for maintenance of older major versions. The `X.x` branches may contain an optional `v` prefix (e.g. `v1.x`), and does not have to include the `.x` suffix (e.g. `v1`).
- Git tags are named `X.Y.Z` (e.g. `1.0.0`). Tags may also contain an optional `v` prefix (e.g. `v1.0.0`).
- Only pull requests are used with regards to the generated release notes and calculating the next version to release. Commits pushed directly to branches are ignored. The pull request titles must follow the [Conventional Commits](https://www.conventionalcommits.org/) specification.
- Release notes are attached to the GitHub release and annotated tags, and are not committed to the repository.

## Configuration

If the above assumptions match your project, you can simply run `imbo-releaser` from the command line and follow the prompts to generate a new release.

If you need to adjust one or more assumptions, you can customize the behavior of Imbo Releaser by providing a configuration file. The configuration file is a PHP file named `.imbo-releaser.php` or `.imbo-releaser.dist.php`, and must exist in the directory where you run the `imbo-releaser` command.

The file must return an instance of the `ImboReleaser\ConfigInterface` interface. If you only need to customize a few aspects of the default behavior, you can extend the `ImboReleaser\Config` class (which specifies the default behavior), and override only the parts you need.

## License

MIT, see [LICENSE](LICENSE).
