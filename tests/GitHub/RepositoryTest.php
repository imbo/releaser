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
        yield 'trailing newline' => ['repository' => "owner/repo\n"];
        foreach (['#123', '?example', '%23', '\\', ':', '@', "'", '&', "\0"] as $suffix) {
            yield 'owner URL delimiter '.bin2hex($suffix) => ['repository' => 'owner'.$suffix.'/repo'];
            yield 'repository URL delimiter '.bin2hex($suffix) => ['repository' => 'owner/repo'.$suffix];
        }
        yield 'owner dot segment' => ['repository' => '../repo'];
        yield 'repository dot segment' => ['repository' => 'owner/.'];
        yield 'repository parent segment' => ['repository' => 'owner/..'];
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

    /**
     * @return iterable<string,array{owner:string,repo:string}>
     */
    public static function invalidRepositoryComponentsProvider(): iterable
    {
        yield 'empty owner' => ['owner' => '', 'repo' => 'repo'];
        yield 'empty repository' => ['owner' => 'owner', 'repo' => ''];
        yield 'owner path separator' => ['owner' => 'owner/other', 'repo' => 'repo'];
        yield 'repository path separator' => ['owner' => 'owner', 'repo' => 'repo/other'];
        yield 'leading owner hyphen' => ['owner' => '-owner', 'repo' => 'repo'];
        yield 'trailing owner hyphen' => ['owner' => 'owner-', 'repo' => 'repo'];

        foreach (self::invalidRepositoryProvider() as $name => ['repository' => $repository]) {
            if (1 === substr_count($repository, '/')) {
                [$owner, $repo] = explode('/', $repository);
                yield $name => ['owner' => $owner, 'repo' => $repo];
            }
        }
    }

    #[DataProvider('invalidRepositoryComponentsProvider')]
    public function testConstructorRejectsInvalidComponents(string $owner, string $repo): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected format "owner/repo"');
        new Repository($owner, $repo);
    }

    /**
     * @return iterable<string,array{repository:string}>
     */
    public static function validRepositoryProvider(): iterable
    {
        yield 'simple' => ['repository' => 'imbo/releaser'];
        yield 'single characters' => ['repository' => 'a/b'];
        yield 'mixed case and punctuation' => ['repository' => 'My-Org/My_Repo.v1-2'];
        yield 'enterprise managed user' => ['repository' => 'user_shortcode/repo'];
        yield 'dot prefix' => ['repository' => 'owner/.github'];
        yield 'hyphen prefix' => ['repository' => 'owner/-repo'];
        yield 'consecutive dots' => ['repository' => 'owner/repo..name'];
    }

    #[DataProvider('validRepositoryProvider')]
    public function testValidRepositoryStringIsValid(string $repository): void
    {
        $this->assertTrue(Repository::isValid($repository));
        $this->assertSame($repository, (string) Repository::fromString($repository));
    }

    public function testUrl(): void
    {
        $repository = new Repository('imbo', 'releaser');
        $this->assertSame('https://github.com/imbo/releaser', $repository->url());
    }
}
