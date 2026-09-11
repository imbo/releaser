<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use ImboReleaser\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Repository::class)]
class RepositoryTest extends TestCase
{
    public function testToString(): void
    {
        $repository = new Repository('imbo', 'releaser');
        $this->assertSame('imbo/releaser', (string) $repository);
    }

    public function testFromString(): void
    {
        $repository = Repository::fromString('imbo/releaser');
        $this->assertSame('imbo', $repository->owner);
        $this->assertSame('releaser', $repository->repo);
    }

    /**
     * @return iterable<string,array{repository:string}>
     */
    public static function invalidRepositoryProvider(): iterable
    {
        yield 'missing separator' => ['repository' => 'invalid-repo-string'];
        yield 'missing owner' => ['repository' => '/repo'];
        yield 'missing repository' => ['repository' => 'owner/'];
        yield 'owner contains whitespace' => ['repository' => 'owner name/repo'];
        yield 'repository contains whitespace' => ['repository' => 'owner/repo name'];
        yield 'additional path segment' => ['repository' => 'owner/repo/path'];
    }

    #[DataProvider('invalidRepositoryProvider')]
    public function testFromStringWithInvalidString(string $repository): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected format "owner/repo"');
        Repository::fromString($repository);
    }

    #[DataProvider('invalidRepositoryProvider')]
    public function testInvalidRepositoryStringIsNotValid(string $repository): void
    {
        $this->assertFalse(Repository::isValid($repository));
    }

    public function testValidRepositoryStringIsValid(): void
    {
        $this->assertTrue(Repository::isValid('imbo/releaser'));
    }

    public function testUrl(): void
    {
        $repository = new Repository('imbo', 'releaser');
        $this->assertSame('https://github.com/imbo/releaser', $repository->url());
    }
}
