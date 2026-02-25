<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Tag::class)]
class TagTest extends TestCase
{
    /**
     * @return array<string,array{data:array<mixed>,error:string}>
     */
    public static function getInvalidAPIData(): array
    {
        return [
            'empty array' => [
                'data' => [],
                'error' => 'Missing required "name" key',
            ],
            'missing name' => [
                'data' => [
                    'tag' => '1.1.1',
                ],
                'error' => 'Missing required "name" key',
            ],
            'missing commit' => [
                'data' => [
                    'name' => 'v1.1.1',
                ],
                'error' => 'Missing required "commit" key',
            ],
            'missing sha' => [
                'data' => [
                    'name' => 'v1.1.1',
                    'commit' => [],
                ],
                'error' => 'Missing required "commit.sha" key',
            ],
        ];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('getInvalidAPIData')]
    public function testFromAPIWithInvalidData(array $data, string $error): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($error);
        Tag::fromAPI($data);
    }

    public function testFromAPIWithValidSemVerName(): void
    {
        $tag = Tag::fromAPI(['name' => '1.1.1', 'commit' => ['sha' => 'abc123']]);
        $this->assertSame('1.1.1', $tag->name);
        $this->assertSame('abc123', $tag->sha);
        $this->assertSame('1.1.1', (string) $tag);
        $this->assertNotNull($tag->version);
    }

    public function testFromAPIWithInvalidSemVerName(): void
    {
        $tag = Tag::fromAPI(['name' => 'some-tag', 'commit' => ['sha' => 'abc123']]);
        $this->assertSame('some-tag', $tag->name);
        $this->assertSame('abc123', $tag->sha);
        $this->assertNull($tag->version);
    }
}
