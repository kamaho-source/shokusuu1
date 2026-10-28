<?php
declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\AiUserRole;
use App\Application\AI\SystemPromptProviderInterface;
use Cake\Core\Exception\CakeException;
use Cake\I18n\Date;

/**
 * ロールに応じたMarkdownファイルからシステムプロンプトを組み立てる実装。
 *
 * ai_assistant.md（ベース）の {{URL_LIST}} プレースホルダーを
 * ロール別 urls/{role}.md の内容で置換して返す。
 * {{TODAY_INFO}} は、今日の日付と直前予約・通常予約の境目の日付（日本時間）に置換する。
 */
final class SystemPromptProvider implements SystemPromptProviderInterface
{
    private const BASE_FILE       = __DIR__ . '/Prompts/ai_assistant.md';
    private const URLS_DIR        = __DIR__ . '/Prompts/urls/';
    private const URL_PLACEHOLDER = '{{URL_LIST}}';
    private const TODAY_PLACEHOLDER = '{{TODAY_INFO}}';
    private const WEEKDAYS        = ['日', '月', '火', '水', '木', '金', '土'];

    private const VALID_ROLES = [
        AiUserRole::CHILD,
        AiUserRole::GENERAL,
        AiUserRole::BLOCK_LEADER,
        AiUserRole::ADMIN,
    ];

    /**
     * @param string    $role  AiUserRole::* 定数
     * @param Date|null $today 今日の日付。null のときは日本時間の今日
     * @throws CakeException プロンプトファイルが読み込めない場合
     */
    public function get(string $role, ?Date $today = null): string
    {
        if (!in_array($role, self::VALID_ROLES, true)) {
            $role = AiUserRole::GENERAL;
        }

        $base    = $this->readFile(self::BASE_FILE);
        $urlList = $this->readFile(self::URLS_DIR . $role . '.md');

        return str_replace(
            [self::URL_PLACEHOLDER, self::TODAY_PLACEHOLDER],
            [$urlList, $this->todayInfo($today ?? Date::now('Asia/Tokyo'))],
            $base
        );
    }

    /**
     * 今日の日付と、直前予約（今日から14日後まで）・通常予約（15日後以降）の境目を文章にする。
     */
    private function todayInfo(Date $today): string
    {
        $lastMinuteEnd = $today->addDays(14);
        $normalStart   = $today->addDays(15);

        $calendar = [];
        for ($i = 0; $i <= 14; $i++) {
            $day        = $today->addDays($i);
            $calendar[] = sprintf('%d/%d(%s)', $day->month, $day->day, self::WEEKDAYS[(int)$day->format('w')]);
        }

        return sprintf(
            "今日は%sです。直前予約の期間は、今日から%sまでです。通常予約は%s以降です。\n今日から14日後までの日付と曜日: %s\n曜日は計算しないでください。「来週の月曜」のような言い方は、この一覧から該当の曜日を探して日付に直し、一覧にない日付には曜日を付けないでください。ユーザーが言った日付（例：12月20日）は、そのまま使ってください。",
            $this->formatDate($today),
            $this->formatDate($lastMinuteEnd),
            $this->formatDate($normalStart),
            implode(' ', $calendar)
        );
    }

    private function formatDate(Date $date): string
    {
        return sprintf('%d年%d月%d日（%s）', $date->year, $date->month, $date->day, self::WEEKDAYS[(int)$date->format('w')]);
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new CakeException('AI prompt file could not be read: ' . $path);
        }

        return $content;
    }
}
