<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use DateTimeImmutable;
use ImboReleaser\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Release::class)]
class ReleaseTest extends TestCase
{
    /**
     * @return iterable<string,array{data:array<mixed>,error:string}>
     */
    public static function fromAPIProvider(): iterable
    {
        yield 'empty array' => [
            'data' => [],
            'error' => 'Missing required "name" key',
        ];
        yield 'missing name' => [
            'data' => [
                'id' => 123,
            ],
            'error' => 'Missing required "name" key',
        ];
        yield 'invalid name' => [
            'data' => ['name' => 123],
            'error' => 'Invalid "name" value',
        ];
        yield 'missing tag_name' => [
            'data' => [
                'name' => 'release name',
            ],
            'error' => 'Missing required "tag_name" key',
        ];
        yield 'missing html_url' => [
            'data' => [
                'name' => 'release name',
                'tag_name' => 'v1.0.0',
            ],
            'error' => 'Missing required "html_url" key',
        ];
        yield 'missing created_at' => [
            'data' => [
                'name' => 'release name',
                'tag_name' => 'v1.0.0',
                'html_url' => '<release-url>',
            ],
            'error' => 'Missing required "created_at" key',
        ];
        yield 'invalid created_at' => [
            'data' => [
                'name' => 'release name',
                'tag_name' => 'v1.0.0',
                'html_url' => '<release-url>',
                'created_at' => 'not-a-date',
            ],
            'error' => 'Invalid "created_at" value: not-a-date',
        ];

        $data = ['name' => 'release name', 'tag_name' => 'v1.0.0', 'html_url' => '<release-url>', 'created_at' => '2024-01-01T00:00:00Z'];
        foreach ([123, [], '', '   ', 'not-a-date'] as $index => $publishedAt) {
            yield 'invalid published_at '.$index => [
                'data' => [...$data, 'published_at' => $publishedAt],
                'error' => 'Invalid "published_at" value',
            ];
        }
        foreach ([null, 1, 'false'] as $index => $draft) {
            yield 'invalid draft '.$index => [
                'data' => [...$data, 'draft' => $draft],
                'error' => 'Invalid "draft" value',
            ];
        }
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('fromAPIProvider')]
    public function testFromAPIWithInvalidData(array $data, string $error): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($error);
        Release::fromAPI($data);
    }

    public function testFromAPIWithValidData(): void
    {
        $url = 'https://github.com/owner/repo/releases/tag/v1.0.0';
        $release = Release::fromAPI(['name' => 'release name', 'tag_name' => 'v1.1.1', 'html_url' => $url, 'created_at' => '2024-01-01T00:00:00Z', 'published_at' => '2024-02-01T12:34:56Z', 'draft' => false]);
        $this->assertSame($url, $release->htmlUrl);
        $this->assertSame('release name', $release->name);
        $this->assertSame('2024-01-01T00:00:00+00:00', $release->createdAt->format('c'));
        $this->assertSame('2024-02-01T12:34:56+00:00', $release->publishedAt?->format('c'));
        $this->assertFalse($release->draft);
    }

    public function testDraftHasNoPublicationDate(): void
    {
        $release = Release::fromAPI(['name' => 'Draft release', 'tag_name' => 'v1.1.1', 'html_url' => 'url', 'created_at' => '2024-01-01T00:00:00Z', 'published_at' => null, 'draft' => true]);

        $this->assertNull($release->publishedAt);
        $this->assertTrue($release->draft);
        $this->assertSame('2024-01-01T00:00:00+00:00', $release->createdAt->format('c'));
    }

    public function testMissingPublicationDataDoesNotUseCommitDate(): void
    {
        $release = Release::fromAPI(['name' => 'release name', 'tag_name' => 'v1.1.1', 'html_url' => 'url', 'created_at' => '2024-01-01T00:00:00Z']);

        $this->assertNull($release->publishedAt);
        $this->assertFalse($release->draft);
    }

    public function testConstructorAcceptsOriginalFourArguments(): void
    {
        $createdAt = new DateTimeImmutable('2024-01-01T00:00:00Z');
        $release = new Release('release name', 'v1.1.1', 'url', $createdAt);

        $this->assertSame($createdAt, $release->createdAt);
        $this->assertNull($release->publishedAt);
        $this->assertFalse($release->draft);
    }

    public function testPreservesReleaseId(): void
    {
        $release = Release::fromAPI([
            'id' => 42, 'name' => 'Draft', 'tag_name' => 'v1.0.0', 'draft' => true,
            'html_url' => 'url', 'created_at' => '2026-01-01T00:00:00Z',
        ]);

        $this->assertSame(42, $release->id);
    }

    public function testRejectsInvalidReleaseId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid "id" value');
        Release::fromAPI([
            'id' => '42', 'name' => 'Draft', 'tag_name' => 'v1.0.0',
            'html_url' => 'url', 'created_at' => '2026-01-01T00:00:00Z',
        ]);
    }

    public function testFromAPIWithNullName(): void
    {
        $release = Release::fromAPI([
            'name' => null,
            'tag_name' => 'v1.2.3',
            'html_url' => 'https://github.com/owner/repo/releases/tag/v1.2.3',
            'created_at' => '2024-01-01T00:00:00Z',
        ]);

        $this->assertSame('v1.2.3', $release->name);
        $this->assertSame('v1.2.3', $release->tagName);
    }

    public function testFromAPIWithValidVersion(): void
    {
        $release = Release::fromAPI(['name' => '1.1.1', 'tag_name' => '1.2.3', 'html_url' => 'url', 'created_at' => '2024-01-01T00:00:00Z']);
        $this->assertSame('1.2.3', (string) $release->version);
    }

    public function testFromAPIWithInvalidVersion(): void
    {
        $release = Release::fromAPI(['name' => 'release name', 'tag_name' => 'invalid-version', 'html_url' => 'url', 'created_at' => '2024-01-01T00:00:00Z']);
        $this->assertNull($release->version);
    }

    public function testToStringWithValidVersion(): void
    {
        $release = Release::fromAPI(['name' => 'release name', 'tag_name' => 'v1.2.3', 'html_url' => 'url', 'created_at' => '2024-01-01T00:00:00Z']);
        $this->assertSame('v1.2.3', (string) $release);
    }

    public function testToStringWithInvalidVersion(): void
    {
        $release = Release::fromAPI(['name' => 'release name', 'tag_name' => 'not-semver', 'html_url' => 'url', 'created_at' => '2024-01-01T00:00:00Z']);
        $this->assertSame('not-semver', (string) $release);
    }
}
