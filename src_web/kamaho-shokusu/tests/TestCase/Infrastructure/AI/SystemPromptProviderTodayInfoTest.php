<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\AI;

use App\Application\AI\AiUserRole;
use App\Infrastructure\AI\SystemPromptProvider;
use Cake\I18n\Date;
use Cake\TestSuite\TestCase;

/**
 * 直前予約・通常予約の境目の日付がプロンプトに入り、聞き返しの指針が含まれること。
 */
class SystemPromptProviderTodayInfoTest extends TestCase
{
    public function testTodayInfoIsFilledWithBoundaryDates(): void
    {
        $prompt = (new SystemPromptProvider())->get(AiUserRole::GENERAL, new Date('2026-10-28'));

        $this->assertStringContainsString('今日は2026年10月28日（水）です。', $prompt);
        $this->assertStringContainsString('直前予約の期間は、今日から2026年11月11日（水）までです。', $prompt);
        $this->assertStringContainsString('通常予約は2026年11月12日（木）以降です。', $prompt);
        $this->assertStringContainsString('10/28(水) 10/29(木)', $prompt);
        $this->assertStringContainsString('11/11(水)', $prompt);
        $this->assertStringNotContainsString('{{TODAY_INFO}}', $prompt);
        $this->assertStringNotContainsString('{{URL_LIST}}', $prompt);
    }

    public function testEveryRoleGetsReservationTypeRulesAndClarification(): void
    {
        foreach ([AiUserRole::CHILD, AiUserRole::GENERAL, AiUserRole::BLOCK_LEADER, AiUserRole::ADMIN] as $role) {
            $prompt = (new SystemPromptProvider())->get($role, new Date('2026-10-28'));

            $this->assertStringContainsString('直前予約（直前編集）', $prompt, $role);
            $this->assertStringContainsString('1つだけ聞き返してください', $prompt, $role);
        }
    }
}
