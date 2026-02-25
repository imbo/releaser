<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use const PHP_EOL;

#[CoversClass(PullRequest::class)]
class PullRequestTest extends TestCase
{
    /**
     * @return array<string,array{data:array<mixed>,error:string}>
     */
    public static function getInvalidAPIData(): array
    {
        return [
            'empty array' => [
                'data' => [],
                'error' => 'Missing required "number" key:',
            ],
            'missing number' => [
                'data' => [
                    'id' => 123,
                ],
                'error' => 'Missing required "number" key:',
            ],
            'missing user' => [
                'data' => [
                    'number' => 123,
                ],
                'error' => 'Missing required "user" key:',
            ],
            'missing user.login' => [
                'data' => [
                    'number' => 123,
                    'user' => [],
                ],
                'error' => 'Missing required "user.login" key:',
            ],
            'missing title' => [
                'data' => [
                    'number' => 123,
                    'user' => [
                        'login' => 'johndoe',
                    ],
                ],
                'error' => 'Missing required "title" key:',
            ],
            'missing merged_at' => [
                'data' => [
                    'number' => 123,
                    'user' => [
                        'login' => 'johndoe',
                    ],
                    'title' => 'feat: add new feature',
                ],
                'error' => 'Missing required "merged_at" key:',
            ],
            'missing base' => [
                'data' => [
                    'number' => 123,
                    'user' => [
                        'login' => 'johndoe',
                    ],
                    'title' => 'feat: add new feature',
                    'merged_at' => '2024-01-01T00:00:00Z',
                ],
                'error' => 'Missing required "base" key:',
            ],
            'missing base.ref' => [
                'data' => [
                    'number' => 123,
                    'user' => [
                        'login' => 'johndoe',
                    ],
                    'title' => 'feat: add new feature',
                    'merged_at' => '2024-01-01T00:00:00Z',
                    'base' => [],
                ],
                'error' => 'Missing required "base.ref" key:',
            ],

            'invalid merged_at' => [
                'data' => [
                    'number' => 123,
                    'user' => [
                        'login' => 'johndoe',
                    ],
                    'title' => 'feat: add new feature',
                    'merged_at' => 'invalid-date',
                    'base' => [
                        'ref' => 'main',
                    ],
                ],
                'error' => 'Invalid "merged_at" value:',
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
        PullRequest::fromAPI($data);
    }

    public function testFromAPI(): void
    {
        $pullRequest = PullRequest::fromAPI([
            'number' => 123,
            'user' => [
                'login' => 'johndoe',
            ],
            'merged_at' => '2024-01-01T00:00:00Z',
            'title' => 'feat: add new feature',
            'base' => [
                'ref' => 'main',
            ],
        ]);
        $this->assertSame(123, $pullRequest->number);
        $this->assertSame('johndoe', $pullRequest->user->login);
        $this->assertSame('2024-01-01T00:00:00+00:00', $pullRequest->mergedAt->format('c'));
        $this->assertSame('feat: add new feature', $pullRequest->rawTitle);
        $this->assertSame('feat: add new feature'.PHP_EOL, (string) $pullRequest->title);
        $this->assertSame('main', $pullRequest->baseRef);
    }

    public function testFromAPIWithInvalidConventionalCommitMessage(): void
    {
        $pullRequest = PullRequest::fromAPI([
            'number' => 123,
            'user' => [
                'login' => 'johndoe',
            ],
            'merged_at' => '2024-01-01T00:00:00Z',
            'title' => 'Some commit message',
            'base' => [
                'ref' => 'main',
            ],
        ]);
        $this->assertNull($pullRequest->title);
    }
}
