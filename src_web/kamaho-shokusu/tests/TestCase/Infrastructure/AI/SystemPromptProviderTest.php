<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\AI;

use App\Application\AI\AiUserRole;
use App\Infrastructure\AI\SystemPromptProvider;
use Cake\TestSuite\TestCase;

class SystemPromptProviderTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function roleProvider(): array
    {
        return [
            'child' => [AiUserRole::CHILD],
            'general' => [AiUserRole::GENERAL],
            'block_leader' => [AiUserRole::BLOCK_LEADER],
            'admin' => [AiUserRole::ADMIN],
        ];
    }

    /**
     * @dataProvider roleProvider
     */
    public function testGet_replacesPlaceholderWithRoleUrlList(string $role): void
    {
        $prompt  = (new SystemPromptProvider())->get($role);
        $urlList = (string)file_get_contents(dirname(__DIR__, 4) . '/src/Infrastructure/AI/Prompts/urls/' . $role . '.md');

        $this->assertNotSame('', $prompt);
        $this->assertStringNotContainsString('{{URL_LIST}}', $prompt);
        $this->assertStringContainsString($urlList, $prompt);
    }

    public function testGet_unknownRole_fallsBackToGeneral(): void
    {
        $provider = new SystemPromptProvider();

        $this->assertSame($provider->get(AiUserRole::GENERAL), $provider->get('hacker/../../etc/passwd'));
    }
}
