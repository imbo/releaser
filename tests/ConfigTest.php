<?php declare(strict_types=1);

namespace ImboReleaser;

use DateTimeImmutable;
use ImboReleaser\Exception\InvalidArgumentException;
use ImboReleaser\Exception\RuntimeException;
use ImboReleaser\GitHub\Branch;
use ImboReleaser\GitHub\PullRequest;
use ImboReleaser\GitHub\ReleasePullRequest;
use ImboReleaser\GitHub\ReleaseTag;
use ImboReleaser\GitHub\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

use const PHP_INT_MAX;

#[CoversClass(Config::class)]
class ConfigTest extends TestCase
{
    public function testDefaultConfig(): void
    {
        $config = new Config();

        $this->assertSame('v0.1.0', (string) $config->initialVersion());
        $this->assertNull($config->gitHubRepository());
        $this->assertNull($config->branch());
        $this->assertTrue($config->filterBranch(new Branch('main')));
        $this->assertTrue($config->filterBranch(new Branch('v1')));
        $this->assertFalse($config->filterBranch(new Branch('feature-branch')));
        $this->assertStringEndsWith('/templates/default.twig', str_replace('\\', '/', (string) $config->template()));
        $this->assertSame([
            'New Features 🚀' => ['feat'],
            'Bug Fixes 🐛' => ['fix'],
            'Documentation 📚' => ['docs'],
        ], $config->pullRequestGroups());
        $this->assertSame('Other Changes ✨', $config->fallbackGroup());
        $this->assertSame('vi', $config->editor());
    }

    public function testDefaultPoliciesCanBeExtended(): void
    {
        $config = new class extends Config {
            protected function initialVersionString(): string
            {
                return 'v1.0.0';
            }

            protected function usernamesToExclude(): array
            {
                return [...parent::usernamesToExclude(), 'renovate[bot]'];
            }

            protected function mainBranchNames(): array
            {
                return [...parent::mainBranchNames(), 'trunk'];
            }

            protected function pullRequestLabelsToExclude(): array
            {
                return [...parent::pullRequestLabelsToExclude(), 'no-release'];
            }
        };

        $this->assertSame('v1.0.0', (string) $config->initialVersion());
        $this->assertTrue($config->filterBranch(new Branch('trunk')));
        $this->assertFalse($config->filterPullRequest(ReleasePullRequest::fromPullRequest(new PullRequest(123, new User('renovate[bot]'), new DateTimeImmutable(), 'fix: update dependency', 'main'))));
        $this->assertFalse($config->filterPullRequest(ReleasePullRequest::fromPullRequest(new PullRequest(123, new User('login'), new DateTimeImmutable(), 'fix: update dependency', 'main', ['no-release']))));
    }

    /**
     * @return iterable<string,array{branchName:string,valid:bool}>
     */
    public static function filterBranchProvider(): iterable
    {
        yield 'main' => ['branchName' => 'main', 'valid' => true];
        yield '1' => ['branchName' => '1', 'valid' => true];
        yield '1.x' => ['branchName' => '1.x', 'valid' => true];
        yield '1.0.x' => ['branchName' => '1.0.x', 'valid' => true];
        yield 'v1' => ['branchName' => 'v1', 'valid' => true];
        yield 'v1.x' => ['branchName' => 'v1.x', 'valid' => true];
        yield 'v1.0.x' => ['branchName' => 'v1.0.x', 'valid' => true];
        yield '123' => ['branchName' => '123', 'valid' => true];
        yield '123.x' => ['branchName' => '123.x', 'valid' => true];
        yield '123.456.x' => ['branchName' => '123.456.x', 'valid' => true];
        yield 'v123' => ['branchName' => 'v123', 'valid' => true];
        yield 'v123.x' => ['branchName' => 'v123.x', 'valid' => true];
        yield 'v123.456.x' => ['branchName' => 'v123.456.x', 'valid' => true];
        yield '1.0.0' => ['branchName' => '1.0.0', 'valid' => false];
        yield 'v1.0.0' => ['branchName' => 'v1.0.0', 'valid' => false];
        yield 'dev' => ['branchName' => 'dev', 'valid' => false];
        yield 'develop' => ['branchName' => 'develop', 'valid' => false];
        yield 'feature-branch' => ['branchName' => 'feature-branch', 'valid' => false];
        yield 'trailing newline' => ['branchName' => "1.x\n", 'valid' => false];
    }

    #[DataProvider('filterBranchProvider')]
    public function testFilterBranch(string $branchName, bool $valid): void
    {
        $this->assertSame($valid, (new Config())->filterBranch(new Branch($branchName)));
    }

    public function testFilterTag(): void
    {
        $this->assertTrue((new Config())->filterTag(new ReleaseTag('v1.0.0', 'sha')));
    }

    /**
     * @return iterable<string,array{message:string,user:string,labels:list<string>,valid:bool}>
     */
    public static function filterPullRequestProvider(): iterable
    {
        yield 'conventional commit' => ['message' => 'feat: add new feature', 'user' => 'login', 'labels' => [], 'valid' => true];
        yield 'excluded label' => ['message' => 'feat: add new feature', 'user' => 'login', 'labels' => ['skip-release'], 'valid' => false];
        yield 'excluded user' => ['message' => 'feat: add new feature', 'user' => 'dependabot[bot]', 'labels' => [], 'valid' => false];
    }

    /**
     * @param list<string> $labels
     */
    #[DataProvider('filterPullRequestProvider')]
    public function testFilterPullRequest(string $message, string $user, array $labels, bool $valid): void
    {
        $pullRequest = ReleasePullRequest::fromPullRequest(new PullRequest(123, new User($user), new DateTimeImmutable(), $message, 'main', $labels));

        $this->assertSame($valid, (new Config())->filterPullRequest($pullRequest));
    }

    public function testDetermineNextVersionWithoutPullRequests(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one pull request must be provided to determine the next version');
        (new Config())->determineNextVersion(new ReleaseTag('1.0.0', 'sha'), []);
    }

    /**
     * @return iterable<string,array{current:string,titles:list<string>,expected:string}>
     */
    public static function determineNextVersionProvider(): iterable
    {
        yield 'uppercase feature' => ['current' => '1.0.0', 'titles' => ['FEAT: a feature'], 'expected' => '1.1.0'];
        yield 'mixed-case feature' => ['current' => '1.0.0', 'titles' => ['Feat: a feature'], 'expected' => '1.1.0'];
        yield 'uppercase breaking change' => ['current' => '1.0.0', 'titles' => ['FIX(API)!: a breaking change'], 'expected' => '2.0.0'];
        yield 'patch changes' => [
            'current' => '1.0.0',
            'titles' => ['fix: some bug'],
            'expected' => '1.0.1',
        ];
        yield 'minor changes' => [
            'current' => '1.0.0',
            'titles' => [
                'fix: some bug',
                'feat: some feature',
            ],
            'expected' => '1.1.0',
        ];
        yield 'major changes' => [
            'current' => '1.0.0',
            'titles' => [
                'fix: some bug',
                'feat: some feature',
                'feat!: some breaking change',
            ],
            'expected' => '2.0.0',
        ];
        yield 'major changes with breaking change in body' => [
            'current' => '1.0.0',
            'titles' => [
                'fix: some bug',
                'feat: some feature',
                <<<MESSAGE
                feat: some breaking change

                BREAKING CHANGE: this is a breaking change
                MESSAGE,
            ],
            'expected' => '2.0.0',
        ];
    }

    /**
     * @param list<string> $titles
     */
    #[DataProvider('determineNextVersionProvider')]
    public function testDetermineNextVersion(string $current, array $titles, string $expected): void
    {
        $next = (new Config())->determineNextVersion(
            new ReleaseTag($current, 'sha'),
            array_map(static fn (string $title) => ReleasePullRequest::fromPullRequest(new PullRequest(123, new User('login'), new DateTimeImmutable(), $title, 'main')), $titles),
        );
        $this->assertSame($expected, (string) $next);
    }

    /**
     * @return iterable<string,array{branch:string,version:string,allowed:bool}>
     */
    public static function versionBranchCompatibilityProvider(): iterable
    {
        foreach (['1', 'v1', '1.x', 'v1.x'] as $branch) {
            yield $branch.' compatible feature' => ['branch' => $branch, 'version' => 'v1.3.0', 'allowed' => true];
            yield $branch.' incompatible major' => ['branch' => $branch, 'version' => 'v2.0.0', 'allowed' => false];
            yield $branch.' lower major' => ['branch' => $branch, 'version' => 'v0.9.9', 'allowed' => false];
        }
        foreach (['1.2', 'v1.2', '1.2.x', 'v1.2.x'] as $branch) {
            yield $branch.' compatible patch' => ['branch' => $branch, 'version' => 'v1.2.4', 'allowed' => true];
            yield $branch.' incompatible minor' => ['branch' => $branch, 'version' => 'v1.3.0', 'allowed' => false];
            yield $branch.' lower minor' => ['branch' => $branch, 'version' => 'v1.1.9', 'allowed' => false];
        }
        yield 'main unrestricted' => ['branch' => 'main', 'version' => 'v2.0.0', 'allowed' => true];
        yield 'master unrestricted' => ['branch' => 'master', 'version' => 'v2.0.0', 'allowed' => true];
        yield 'custom name unrestricted' => ['branch' => 'release/current', 'version' => 'v2.0.0', 'allowed' => true];
        yield 'zero major' => ['branch' => '0.x', 'version' => 'v0.2.0', 'allowed' => true];
        yield 'zero minor' => ['branch' => '1.0.x', 'version' => 'v1.0.1', 'allowed' => true];
        yield 'custom tag prefix' => ['branch' => '1.2.x', 'version' => 'release-1.2.4', 'allowed' => true];
        yield 'custom tag prefix outside release line' => ['branch' => '1.2.x', 'version' => 'release-1.3.0', 'allowed' => false];
        yield 'compatible prerelease and metadata' => ['branch' => '1.2.x', 'version' => 'v1.2.4-rc.1+build.2', 'allowed' => true];
        yield 'incompatible prerelease' => ['branch' => '1.2.x', 'version' => 'v1.3.0-rc.1', 'allowed' => false];
        yield 'different major with same prefix' => ['branch' => '1.x', 'version' => 'v10.0.0', 'allowed' => false];
        yield 'different minor with same prefix' => ['branch' => '1.2.x', 'version' => 'v1.20.0', 'allowed' => false];
        yield 'maximum major' => ['branch' => PHP_INT_MAX.'.x', 'version' => 'v'.PHP_INT_MAX.'.0.0', 'allowed' => true];
        yield 'maximum minor' => ['branch' => '1.'.PHP_INT_MAX.'.x', 'version' => 'v1.'.PHP_INT_MAX.'.0', 'allowed' => true];
        yield 'maximum patch' => ['branch' => '1.2.x', 'version' => 'v1.2.'.PHP_INT_MAX, 'allowed' => true];
    }

    #[DataProvider('versionBranchCompatibilityProvider')]
    public function testValidateVersionForBranch(string $branch, string $version, bool $allowed): void
    {
        if ($allowed) {
            $this->expectNotToPerformAssertions();
        } else {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(sprintf('Cannot release version "%s" from maintenance branch "%s"', $version, $branch));
        }

        (new Config())->validateVersionForBranch(new Branch($branch), Version::fromString($version));
    }

    public function testConfiguredMainBranchIsNotBoundedByItsName(): void
    {
        $config = new class extends Config {
            protected function mainBranchNames(): array
            {
                return [...parent::mainBranchNames(), '1.x'];
            }
        };

        $this->expectNotToPerformAssertions();
        $config->validateVersionForBranch(new Branch('1.x'), Version::fromString('v2.0.0'));
    }

    /**
     * @return iterable<string,array{branch:string}>
     */
    public static function unsupportedMaintenanceVersionLineProvider(): iterable
    {
        yield 'leading zero major' => ['branch' => '01.x'];
        yield 'leading zero minor' => ['branch' => '1.02.x'];
        yield 'overflowing major' => ['branch' => PHP_INT_MAX.'0.x'];
        yield 'overflowing minor' => ['branch' => '1.'.PHP_INT_MAX.'0.x'];
    }

    #[DataProvider('unsupportedMaintenanceVersionLineProvider')]
    public function testRejectsUnsupportedMaintenanceVersionLine(string $branch): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Maintenance branch "%s" does not name a supported version line.', $branch));
        (new Config())->validateVersionForBranch(new Branch($branch), Version::fromString('v1.2.3'));
    }

    /**
     * @return iterable<string,array{branchName:string,tagNames:list<string>,expectedTagName:string|null}>
     */
    public static function getLatestTagForBranchProvider(): iterable
    {
        yield 'build metadata does not change version ordering' => [
            'branchName' => 'main',
            'tagNames' => ['v1.2.3+build.4.5.6', 'v2.0.0'],
            'expectedTagName' => 'v2.0.0',
        ];
        yield 'no tags' => [
            'branchName' => 'main',
            'tagNames' => [],
            'expectedTagName' => null,
        ];
        yield 'main branch' => [
            'branchName' => 'main',
            'tagNames' => [
                'v1.0.0',
                'v1.1.0',
                'v2.0.0',
            ],
            'expectedTagName' => 'v2.0.0',
        ];
        yield 'main branch selects stable tag over prerelease' => [
            'branchName' => 'main',
            'tagNames' => [
                'v1.0.0',
                'v1.1.0-rc.1',
            ],
            'expectedTagName' => 'v1.0.0',
        ];
        yield 'main branch with custom prefix' => [
            'branchName' => 'main',
            'tagNames' => [
                'release-1.0.0',
                'release-2.0.0',
                'release-1.1.0',
            ],
            'expectedTagName' => 'release-2.0.0',
        ];
        yield 'release branch' => [
            'branchName' => 'v2',
            'tagNames' => [
                'v1.0.0',
                'v1.1.0',
                'v2.0.0',
                'v2.0.1',
                'v3.0.0',
            ],
            'expectedTagName' => 'v2.0.1',
        ];
        yield 'major maintenance branch' => [
            'branchName' => '2.x',
            'tagNames' => [
                'v1.0.0',
                'v2.0.0',
                'v2.1.0',
                'v3.0.0',
            ],
            'expectedTagName' => 'v2.1.0',
        ];
        yield 'prefixed major maintenance branch' => [
            'branchName' => 'v2.x',
            'tagNames' => [
                'v1.0.0',
                'v2.0.0',
                'v2.1.0',
                'v3.0.0',
            ],
            'expectedTagName' => 'v2.1.0',
        ];
        yield 'minor maintenance branch' => [
            'branchName' => '2.3.x',
            'tagNames' => [
                'v2.2.0',
                'v2.3.0',
                'v2.3.1',
                'v2.4.0',
            ],
            'expectedTagName' => 'v2.3.1',
        ];
        yield 'prefixed minor maintenance branch' => [
            'branchName' => 'v2.3.x',
            'tagNames' => [
                'v2.2.0',
                'v2.3.0',
                'v2.3.1',
                'v2.4.0',
            ],
            'expectedTagName' => 'v2.3.1',
        ];
        yield 'no match' => [
            'branchName' => 'v2',
            'tagNames' => [
                'v1.0.0',
                'v1.1.0',
                'v3.0.0',
            ],
            'expectedTagName' => null,
        ];
    }

    /**
     * @param list<string> $tagNames
     */
    #[DataProvider('getLatestTagForBranchProvider')]
    public function testGetLatestTagForBranch(string $branchName, array $tagNames, ?string $expectedTagName): void
    {
        $tag = (new Config())->getLatestTagForBranch(
            new Branch($branchName),
            array_map(static fn (string $tagName) => new ReleaseTag($tagName, 'sha'), $tagNames),
        );
        if (null === $expectedTagName) {
            $this->assertNull($tag);

            return;
        }

        if (null === $tag) {
            $this->fail('Expected a tag but got null');
        }

        $this->assertSame($expectedTagName, $tag->name);
    }
}
