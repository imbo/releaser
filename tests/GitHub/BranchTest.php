<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Branch::class)]
class BranchTest extends TestCase
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
            'missing required key' => [
                'data' => [
                    'branch' => 'main',
                ],
                'error' => 'Missing required "name" key',
            ],
        ];
    }

    /**
     * @param array{data:array<mixed>} $data
     */
    #[DataProvider('getInvalidAPIData')]
    public function testFromAPIWithInvalidData(array $data, string $error): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($error);
        Branch::fromAPI($data);
    }

    public function testFromAPI(): void
    {
        $branch = Branch::fromAPI(['name' => 'main']);
        $this->assertSame('main', $branch->name);
        $this->assertSame('main', (string) $branch);
    }
}
