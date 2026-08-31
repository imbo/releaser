<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use ImboReleaser\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PullRequest::class)]
class PullRequestTest extends TestCase
{
    /**
     * @return iterable<string,array{data:array<mixed>,error:string}>
     */
    public static function fromAPIProvider(): iterable
    {
        yield 'empty array' => [
            'data' => [],
            'error' => 'Missing required "number" key:',
        ];
        yield 'missing number' => [
            'data' => [
                'id' => 123,
            ],
            'error' => 'Missing required "number" key:',
        ];
        yield 'missing user' => [
            'data' => [
                'number' => 123,
            ],
            'error' => 'Missing required "user" key:',
        ];
        yield 'missing user.login' => [
            'data' => [
                'number' => 123,
                'user' => [],
            ],
            'error' => 'Missing required "user.login" key:',
        ];
        yield 'missing title' => [
            'data' => [
                'number' => 123,
                'user' => [
                    'login' => 'johndoe',
                ],
            ],
            'error' => 'Missing required "title" key:',
        ];
        yield 'missing merged_at' => [
            'data' => [
                'number' => 123,
                'user' => [
                    'login' => 'johndoe',
                ],
                'title' => 'feat: add new feature',
            ],
            'error' => 'Missing required "merged_at" key:',
        ];
        yield 'missing base' => [
            'data' => [
                'number' => 123,
                'user' => [
                    'login' => 'johndoe',
                ],
                'title' => 'feat: add new feature',
                'merged_at' => '2024-01-01T00:00:00Z',
            ],
            'error' => 'Missing required "base" key:',
        ];
        yield 'missing base.ref' => [
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
        ];
        yield 'invalid merged_at' => [
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
            'body' => 'This is the body of the pull request.',
            'base' => [
                'ref' => 'main',
            ],
            'labels' => [
                ['name' => 'bug'],
                ['name' => 'enhancement'],
            ],
        ]);
        $this->assertSame(123, $pullRequest->number);
        $this->assertSame('johndoe', $pullRequest->user->login);
        $this->assertSame('2024-01-01T00:00:00+00:00', $pullRequest->mergedAt->format('c'));
        $this->assertStringStartsWith('feat: add new feature', $pullRequest->rawMessage);
        $this->assertStringStartsWith('feat: add new feature', (string) $pullRequest->message);
        $this->assertStringContainsString('This is the body of the pull request.', (string) $pullRequest->message);
        $this->assertSame('main', $pullRequest->baseRef);
        $this->assertSame(['bug', 'enhancement'], $pullRequest->labels);
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
        $this->assertNull($pullRequest->message);
    }
}
