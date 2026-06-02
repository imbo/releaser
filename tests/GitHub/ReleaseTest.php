<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Release::class)]
class ReleaseTest extends TestCase
{
    /**
     * @return array<string,array{data:array<mixed>,error:string}>
     */
    public static function fromAPIProvider(): array
    {
        return [
            'empty array' => [
                'data' => [],
                'error' => 'Missing required "html_url" key',
            ],
            'missing html_url' => [
                'data' => [
                    'id' => 123,
                ],
                'error' => 'Missing required "html_url" key',
            ],
            'non-string html_url' => [
                'data' => [
                    'html_url' => 123,
                ],
                'error' => 'Missing required "html_url" key',
            ],
        ];
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
        $release = Release::fromAPI(['html_url' => $url]);
        $this->assertSame($url, $release->htmlUrl);
    }
}
