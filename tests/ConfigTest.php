<?php declare(strict_types=1);

namespace ImboReleaser;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
class ConfigTest extends TestCase
{
    public function testDefaultConfig(): void
    {
        $config = new Config();

        $this->assertSame('0.1.0', (string) $config->initialVersion());
        $this->assertNull($config->gitHubRepository());
        $this->assertNull($config->branch());
        $this->assertTrue($config->filterBranch(new GitHub\Branch('main')));
        $this->assertTrue($config->filterBranch(new GitHub\Branch('v1')));
        $this->assertFalse($config->filterBranch(new GitHub\Branch('feature-branch')));
    }

    /**
     * @return array<array{branch:string,valid:bool}>
     */
    public static function branchesToFilter(): array
    {
        return [
            ['branch' => 'main', 'valid' => true],
            ['branch' => '1', 'valid' => true],
            ['branch' => '1.x', 'valid' => true],
            ['branch' => '1.0.x', 'valid' => true],
            ['branch' => 'v1', 'valid' => true],
            ['branch' => 'v1.x', 'valid' => true],
            ['branch' => 'v1.0.x', 'valid' => true],
            ['branch' => '123', 'valid' => true],
            ['branch' => '123.x', 'valid' => true],
            ['branch' => '123.456.x', 'valid' => true],
            ['branch' => 'v123', 'valid' => true],
            ['branch' => 'v123.x', 'valid' => true],
            ['branch' => 'v123.456.x', 'valid' => true],
            ['branch' => '1.0.0', 'valid' => false],
            ['branch' => 'v1.0.0', 'valid' => false],
            ['branch' => 'dev', 'valid' => false],
            ['branch' => 'develop', 'valid' => false],
            ['branch' => 'feature-branch', 'valid' => false],
        ];
    }

    #[DataProvider('branchesToFilter')]
    public function testFilterBranch(string $branch, bool $valid): void
    {
        $this->assertSame($valid, (new Config())->filterBranch(new GitHub\Branch($branch)));
    }
}
