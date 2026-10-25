<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Element;

use App\View\AppView;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;

/**
 * 操作パネル（templates/element/side_actions.php）の出力テスト。
 */
class SideActionsTest extends TestCase
{
    private function render(array $data): string
    {
        $request = (new ServerRequest(['url' => '/', 'webroot' => '/']))->withAttribute('csrfToken', 'test-token');
        $view = new AppView($request);

        return $view->element('side_actions', $data);
    }

    private function items(): array
    {
        return [
            ['tone' => 'edit', 'label' => '編集する', 'url' => '/edit/1', 'primary' => true],
            ['tone' => 'view', 'label' => '詳細を表示する', 'url' => '/view/1'],
            ['tone' => 'back', 'label' => '一覧へ戻る', 'url' => '/index'],
            ['tone' => 'delete', 'label' => '削除する', 'url' => '/delete/1', 'confirmTarget' => 'ひまわり'],
        ];
    }

    public function testRendersPanelBarAndSheetFromSameItems(): void
    {
        $html = $this->render(['items' => $this->items()]);

        $this->assertStringContainsString('<aside class="col-md-3 d-none d-md-block sa-aside" aria-label="操作">', $html);
        $this->assertStringContainsString('<nav class="sa-bar d-md-none" aria-label="操作">', $html);
        $this->assertStringContainsString('id="sa-sheet"', $html);
        // PC のパネルには、編集・詳細・戻る・削除がすべて出る
        $panel = explode('<nav class="sa-bar', $html)[0];
        foreach (['編集する', '詳細を表示する', '一覧へ戻る', '削除する'] as $label) {
            $this->assertStringContainsString($label, $panel);
        }
        $this->assertStringContainsString('sa-item--primary', $panel);
    }

    public function testBarHasBackIconPrimaryAndMoreButton(): void
    {
        $html = $this->render(['items' => $this->items()]);
        $bar = explode('</nav>', explode('<nav class="sa-bar', $html)[1])[0];

        $this->assertStringContainsString('class="sa-bar-back"', $bar);
        $this->assertStringContainsString('aria-label="一覧へ戻る"', $bar);
        $this->assertStringContainsString('sa-bar-wide--primary', $bar);
        $this->assertStringContainsString('aria-label="その他の操作"', $bar);
    }

    public function testSheetHoldsOthersAndDeleteButNotPrimaryOrBack(): void
    {
        $html = $this->render(['items' => $this->items()]);
        $sheet = explode('id="sa-sheet"', $html)[1];

        $this->assertStringContainsString('詳細を表示する', $sheet);
        $this->assertStringContainsString('削除する', $sheet);
        $this->assertStringNotContainsString('編集する', $sheet);
        $this->assertStringNotContainsString('一覧へ戻る', $sheet);
    }

    public function testDeleteIsPostFormWithConfirmMessageAndCsrf(): void
    {
        $html = $this->render(['items' => $this->items()]);

        $this->assertStringContainsString('action="/delete/1"', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('data-sa-confirm="「ひまわり」を削除してもよろしいですか？"', $html);
        $this->assertStringContainsString('name="_csrfToken"', $html);
        $this->assertStringContainsString('type="button" class="sa-item sa-item--delete"', $html);
    }

    public function testDeleteWithoutTargetUsesPlainMessage(): void
    {
        $html = $this->render(['items' => [['tone' => 'delete', 'label' => '削除する', 'url' => '/delete/1']]]);

        $this->assertStringContainsString('data-sa-confirm="削除してもよろしいですか？"', $html);
    }

    public function testBackOnlyShowsWideBackAndNoMoreButton(): void
    {
        $html = $this->render(['items' => [['tone' => 'back', 'label' => '部屋一覧へ戻る', 'url' => '/index']]]);
        $bar = explode('</nav>', explode('<nav class="sa-bar', $html)[1])[0];

        $this->assertStringContainsString('sa-bar-wide--back', $bar);
        $this->assertStringNotContainsString('sa-bar-more', $bar);
        $this->assertStringNotContainsString('id="sa-sheet"', $html);
    }

    public function testWithoutPrimaryBackBecomesLabeledWideButtonBesideMore(): void
    {
        $html = $this->render(['items' => [
            ['tone' => 'back', 'label' => '食数予約一覧へ戻る', 'url' => '/index'],
            ['tone' => 'add', 'label' => '週の一括予約', 'url' => '/bulk'],
        ]]);
        $bar = explode('</nav>', explode('<nav class="sa-bar', $html)[1])[0];

        $this->assertStringContainsString('sa-bar-wide--back', $bar);
        $this->assertStringContainsString('食数予約一覧へ戻る', $bar);
        $this->assertStringContainsString('sa-bar-more', $bar);
        $this->assertStringNotContainsString('class="sa-bar-back"', $bar);
    }

    public function testHiddenAndEmptyRenderNothing(): void
    {
        $this->assertSame('', trim($this->render(['items' => $this->items(), 'hidden' => true])));
        $this->assertSame('', trim($this->render(['items' => []])));
    }

    public function testLabelsAreEscaped(): void
    {
        $html = $this->render(['items' => [['tone' => 'view', 'label' => '<script>alert(1)</script>', 'url' => '/x']]]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testUnknownToneThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->render(['items' => [['tone' => 'unknown', 'label' => 'x', 'url' => '/x']]]);
    }

    public function testTwoPrimariesThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->render(['items' => [
            ['tone' => 'edit', 'label' => 'a', 'url' => '/a', 'primary' => true],
            ['tone' => 'add', 'label' => 'b', 'url' => '/b', 'primary' => true],
        ]]);
    }

    public function testMissingKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->render(['items' => [['tone' => 'view', 'url' => '/x']]]);
    }

    public function testConfirmTargetIsEscapedInAttribute(): void
    {
        $html = $this->render(['items' => [['tone' => 'delete', 'label' => '削除する', 'url' => '/d/1', 'confirmTarget' => 'A"<b>']]]);

        $this->assertStringNotContainsString('A"<b>', $html);
        $this->assertStringContainsString('A&quot;&lt;b&gt;', $html);
    }

    public function testDeleteOnlyShowsWideMoreButtonAndSheetAndTwoForms(): void
    {
        $html = $this->render(['items' => [['tone' => 'delete', 'label' => '削除する', 'url' => '/d/1']]]);

        $this->assertStringContainsString('sa-bar-more sa-bar-more--wide', $html);
        $this->assertStringContainsString('id="sa-sheet"', $html);
        // PC のパネルとシートに1つずつ
        $this->assertSame(2, substr_count($html, 'class="sa-delete-form"'));
    }

    public function testPrimaryOnlyShowsPrimaryAndNoMoreButton(): void
    {
        $html = $this->render(['items' => [['tone' => 'edit', 'label' => '編集する', 'url' => '/e/1', 'primary' => true]]]);
        $bar = explode('</nav>', explode('<nav class="sa-bar', $html)[1])[0];

        $this->assertStringContainsString('sa-bar-wide--primary', $bar);
        $this->assertStringNotContainsString('sa-bar-more', $bar);
        $this->assertStringNotContainsString('id="sa-sheet"', $html);
    }

    public function testDeleteCannotBePrimary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->render(['items' => [['tone' => 'delete', 'label' => '削除する', 'url' => '/d/1', 'primary' => true]]]);
    }

    public function testNonStringToneThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->render(['items' => [['tone' => ['x'], 'label' => 'x', 'url' => '/x']]]);
    }
}
