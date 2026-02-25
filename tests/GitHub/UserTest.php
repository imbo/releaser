<?php declare(strict_types=1);

namespace ImboReleaser\GitHub;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
class UserTest extends TestCase
{
    public function testNewUser(): void
    {
        $user = new User('johndoe');
        $this->assertSame('johndoe', $user->login);
        $this->assertSame('johndoe', (string) $user);
    }
}
