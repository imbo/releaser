<?php declare(strict_types=1);

namespace ImboReleaser;

use DateTimeImmutable;
use ImboReleaser\GitHub\Branch;
use ImboReleaser\GitHub\PullRequest;
use ImboReleaser\GitHub\Release;
use ImboReleaser\GitHub\Tag;
use ImboReleaser\GitHub\User;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        $this->assertStringEndsWith('/templates/default.twig', (string) $config->template());
        $this->assertSame([
            'New Features 🚀' => ['feat'],
            'Bug Fixes 🐛' => ['fix'],
            'Documentation 📚' => ['docs'],
        ], $config->pullRequestGroups());
        $this->assertSame('Other Changes ✨', $config->fallbackGroup());
        $this->assertSame('vi', $config->editor());
    }

    /**
     * @return list<array{branchName:string,valid:bool}>
     */
    public static function filterBranchProvider(): array
    {
        return [
            ['branchName' => 'main', 'valid' => true],
            ['branchName' => '1', 'valid' => true],
            ['branchName' => '1.x', 'valid' => true],
            ['branchName' => '1.0.x', 'valid' => true],
            ['branchName' => 'v1', 'valid' => true],
            ['branchName' => 'v1.x', 'valid' => true],
            ['branchName' => 'v1.0.x', 'valid' => true],
            ['branchName' => '123', 'valid' => true],
            ['branchName' => '123.x', 'valid' => true],
            ['branchName' => '123.456.x', 'valid' => true],
            ['branchName' => 'v123', 'valid' => true],
            ['branchName' => 'v123.x', 'valid' => true],
            ['branchName' => 'v123.456.x', 'valid' => true],
            ['branchName' => '1.0.0', 'valid' => false],
            ['branchName' => 'v1.0.0', 'valid' => false],
            ['branchName' => 'dev', 'valid' => false],
            ['branchName' => 'develop', 'valid' => false],
            ['branchName' => 'feature-branch', 'valid' => false],
        ];
    }

    #[DataProvider('filterBranchProvider')]
    public function testFilterBranch(string $branchName, bool $valid): void
    {
        $this->assertSame($valid, (new Config())->filterBranch(new Branch($branchName)));
    }

    /**
     * @return array<string,array{release:Release,valid:bool}>
     */
    public static function filterReleaseProvider(): array
    {
        return [
            'no version' => [
                'release' => new Release('name', 'some-tag-name', 'url', new DateTimeImmutable()),
                'valid' => false,
            ],
            'valid version' => [
                'release' => new Release('name', 'v1.2.3', 'url', new DateTimeImmutable()),
                'valid' => true,
            ],
        ];
    }

    #[DataProvider('filterReleaseProvider')]
    public function testFilterRelease(Release $release, bool $valid): void
    {
        $this->assertSame($valid, (new Config())->filterRelease($release));
    }

    /**
     * @return list<array{tagName:string,valid:bool}>
     */
    public static function filterTagProvider(): array
    {
        return [
            ['tagName' => 'v1.0.0', 'valid' => true],
            ['tagName' => 'foo', 'valid' => false],
        ];
    }

    #[DataProvider('filterTagProvider')]
    public function testFilterTag(string $tagName, bool $valid): void
    {
        $this->assertSame($valid, (new Config())->filterTag(new Tag($tagName, 'sha')));
    }

    /**
     * @return list<array{message:string,valid:bool}>
     */
    public static function filterPullRequestProvider(): array
    {
        return [
            ['message' => 'feat: add new feature', 'valid' => true],
            ['message' => 'Some commit message', 'valid' => false],
        ];
    }

    #[DataProvider('filterPullRequestProvider')]
    public function testFilterPullRequest(string $message, bool $valid): void
    {
        $this->assertSame($valid, (new Config())->filterPullRequest(new PullRequest(123, new User('login'), new DateTimeImmutable(), $message, 'main')));
    }

    public function testDetermineNextVersionWithoutSemVer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The current tag does not have a valid version');
        (new Config())->determineNextVersion(new Tag('some-name', 'sha'), []);
    }

    public function testDetermineNextVersionWithoutPullRequests(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one pull request must be provided to determine the next version');
        (new Config())->determineNextVersion(new Tag('1.0.0', 'sha'), []);
    }

    /**
     * @return array<string,array{current:string,titles:list<string>,expected:string}>
     */
    public static function determineNextVersionProvider(): array
    {
        return [
            'no conventional commits' => [
                'current' => '1.0.0',
                'titles' => ['some random title'],
                'expected' => '1.0.1',
            ],
            'patch changes' => [
                'current' => '1.0.0',
                'titles' => ['fix: some bug'],
                'expected' => '1.0.1',
            ],
            'minor changes' => [
                'current' => '1.0.0',
                'titles' => [
                    'fix: some bug',
                    'feat: some feature',
                ],
                'expected' => '1.1.0',
            ],
            'major changes' => [
                'current' => '1.0.0',
                'titles' => [
                    'fix: some bug',
                    'feat: some feature',
                    'feat!: some breaking change',
                ],
                'expected' => '2.0.0',
            ],
            'major changes with breaking change in body' => [
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
            ],
        ];
    }

    /**
     * @param list<string> $titles
     */
    #[DataProvider('determineNextVersionProvider')]
    public function testDetermineNextVersion(string $current, array $titles, string $expected): void
    {
        $next = (new Config())->determineNextVersion(
            new Tag($current, 'sha'),
            array_map(static fn (string $title) => new PullRequest(123, new User('login'), new DateTimeImmutable(), $title, 'main'), $titles),
        );
        $this->assertSame($expected, (string) $next);
    }

    /**
     * @return array<string,array{branchName:string,tagNames:list<string>,expectedTagName:string|null}>
     */
    public static function getLatestTagForBranchProvider(): array
    {
        return [
            'no tags' => [
                'branchName' => 'main',
                'tagNames' => [],
                'expectedTagName' => null,
            ],

            'no valid' => [
                'branchName' => 'main',
                'tagNames' => ['some-tag', 'another-tag'],
                'expectedTagName' => null,
            ],

            'main branch' => [
                'branchName' => 'main',
                'tagNames' => [
                    'v1.0.0',
                    'v1.1.0',
                    'v2.0.0',
                ],
                'expectedTagName' => 'v2.0.0',
            ],
            'release branch' => [
                'branchName' => 'v2',
                'tagNames' => [
                    'v1.0.0',
                    'v1.1.0',
                    'v2.0.0',
                    'v2.0.1',
                    'v3.0.0',
                ],
                'expectedTagName' => 'v2.0.1',
            ],
            'no match' => [
                'branchName' => 'v2',
                'tagNames' => [
                    'v1.0.0',
                    'v1.1.0',
                    'v3.0.0',
                ],
                'expectedTagName' => null,
            ],
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
            array_map(static fn (string $tagName) => new Tag($tagName, 'sha'), $tagNames),
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
