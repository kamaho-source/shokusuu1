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

    public function testTodayInfoCrossesMonthAndYear(): void
    {
        $prompt = (new SystemPromptProvider())->get(AiUserRole::GENERAL, new Date('2026-12-25'));

        $this->assertStringContainsString('直前予約の期間は、今日から2027年1月8日（金）までです。', $prompt);
        $this->assertStringContainsString('通常予約は2027年1月9日（土）以降です。', $prompt);
        $this->assertStringContainsString(
            '12/25(金) 12/26(土) 12/27(日) 12/28(月) 12/29(火) 12/30(水) 12/31(木) 1/1(金) 1/2(土) 1/3(日) 1/4(月) 1/5(火) 1/6(水) 1/7(木) 1/8(金)',
            $prompt
        );
    }

    public function testDefaultTodayIsJapanToday(): void
    {
        $prompt = (new SystemPromptProvider())->get(AiUserRole::GENERAL);
        $today  = Date::now('Asia/Tokyo');

        $this->assertStringContainsString(sprintf('今日は%d年%d月%d日', $today->year, $today->month, $today->day), $prompt);
    }

    public function testRulesForStaffAndRoles(): void
    {
        $prompt = (new SystemPromptProvider())->get(AiUserRole::CHILD, new Date('2026-10-28'));

        $this->assertStringContainsString('職員の予約は、直前予約の期間には、管理者以外はキャンセルできません', $prompt);
        $this->assertStringContainsString('過去の日付は、管理者以外は変更できません', $prompt);
        $this->assertStringContainsString('所属グループのない方は使えない', $prompt);
        $this->assertStringContainsString('一括登録・一括変更の画面はありません', $prompt);
        $this->assertStringContainsString('一括登録は、「利用可能な画面URL」に載っている場合だけ', $prompt);
    }

    public function testEachRoleGetsItsOwnAuthorityText(): void
    {
        $expected = [
            AiUserRole::CHILD        => 'あなたは、利用者本人（児童）です',
            AiUserRole::GENERAL      => 'あなたは、職員です',
            AiUserRole::BLOCK_LEADER => 'あなたは、ブロック長です',
            AiUserRole::ADMIN        => 'あなたは、管理者です',
        ];
        foreach ($expected as $role => $text) {
            $prompt = (new SystemPromptProvider())->get($role, new Date('2026-10-28'));

            $this->assertStringContainsString('【あなた（ログイン中のユーザー）の権限】', $prompt, $role);
            $this->assertStringContainsString($text, $prompt, $role);
            $this->assertStringNotContainsString('{{ROLE_INFO}}', $prompt, $role);
            foreach ($expected as $other => $otherText) {
                if ($other !== $role) {
                    $this->assertStringNotContainsString($otherText, $prompt, "$role に $other の権限が混ざらない");
                }
            }
        }
    }

    public function testAdminMayCancelStaffReservationsInLastMinutePeriod(): void
    {
        $admin = (new SystemPromptProvider())->get(AiUserRole::ADMIN, new Date('2026-10-28'));
        $staff = (new SystemPromptProvider())->get(AiUserRole::GENERAL, new Date('2026-10-28'));

        $this->assertStringContainsString('職員の予約を含めて、キャンセル・変更ができます', $admin);
        $this->assertStringContainsString('職員の予約（あなた自身を含む）をキャンセルできません', $staff);
    }

    public function testEveryRoleGetsReservationTypeRulesAndClarification(): void
    {
        foreach ([AiUserRole::CHILD, AiUserRole::GENERAL, AiUserRole::BLOCK_LEADER, AiUserRole::ADMIN] as $role) {
            $prompt = (new SystemPromptProvider())->get($role, new Date('2026-10-28'));

            $this->assertStringContainsString('直前予約（直前編集）', $prompt, $role);
            $this->assertStringContainsString('聞き返すのは、会話の中で最大1回です', $prompt, $role);
        }
    }
}
