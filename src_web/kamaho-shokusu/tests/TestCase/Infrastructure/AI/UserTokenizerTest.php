<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\AI;

use App\Infrastructure\AI\UserTokenizer;
use Cake\TestSuite\TestCase;

class UserTokenizerTest extends TestCase
{
    public function testTokenize_isDeterministic12HexAndNotRawId(): void
    {
        $t = new UserTokenizer('salt-a');
        $token = $t->tokenize(42);

        $this->assertSame($token, $t->tokenize(42));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $token);
        $this->assertSame(substr(hash_hmac('sha256', '42', 'salt-a'), 0, 12), $token);
        $this->assertNotSame($token, $t->tokenize(43));
    }

    public function testTokenize_differsBySalt(): void
    {
        $this->assertNotSame((new UserTokenizer('a'))->tokenize(1), (new UserTokenizer('b'))->tokenize(1));
    }

    public function testConstruct_withoutSalt_usesAppSecurityConfig(): void
    {
        $token = (new UserTokenizer())->tokenize(1);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $token);
    }

    public function testConstruct_emptySalt_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        new UserTokenizer('');
    }
}
