<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ApiResponseService;
use Cake\Http\Response;
use Cake\TestSuite\TestCase;

/**
 * ApiResponseService のテスト（JSON 形式・ステータス・エンコード失敗時のフォールバック）。
 */
class ApiResponseServiceTest extends TestCase
{
    /** @return array<string, mixed> */
    private function body(Response $r): array
    {
        return json_decode((string)$r->getBody(), true);
    }

    public function testSuccess_defaultsTo200WithoutMessage(): void
    {
        $r = (new ApiResponseService())->success(new Response(), ['a' => 1]);

        $this->assertSame(200, $r->getStatusCode());
        $this->assertStringContainsString('application/json', $r->getHeaderLine('Content-Type'));
        $this->assertSame(['ok' => true, 'data' => ['a' => 1]], $this->body($r));
    }

    public function testSuccess_includesMessageAndCustomStatus_butOmitsEmptyMessage(): void
    {
        $service = new ApiResponseService();

        $created = $service->success(new Response(), [], '作成しました', 201);
        $this->assertSame(201, $created->getStatusCode());
        $this->assertSame('作成しました', $this->body($created)['message']);

        $this->assertArrayNotHasKey('message', $this->body($service->success(new Response(), [], '')));
    }

    public function testError_returnsMessageStatusAndData_withUnescapedJapanese(): void
    {
        $r = (new ApiResponseService())->error(new Response(), '入力が不正です', 422, ['field' => 'x']);

        $this->assertSame(422, $r->getStatusCode());
        $this->assertStringContainsString('入力が不正です', (string)$r->getBody());
        $this->assertSame(['ok' => false, 'message' => '入力が不正です', 'data' => ['field' => 'x']], $this->body($r));
    }

    public function testError_defaultsTo400(): void
    {
        $this->assertSame(400, (new ApiResponseService())->error(new Response(), 'x')->getStatusCode());
    }

    public function testForbidden_defaultAndCustomMessage(): void
    {
        $service = new ApiResponseService();

        $default = $service->forbidden(new Response());
        $this->assertSame(403, $default->getStatusCode());
        $this->assertSame('権限がありません。', $this->body($default)['message']);
        $this->assertSame('見せられません', $this->body($service->forbidden(new Response(), '見せられません'))['message']);
    }

    /** JSON にできない値（INF）が入っても例外を投げず、固定のエラー JSON を返す */
    public function testSuccess_unencodablePayload_fallsBackToErrorJson(): void
    {
        $r = (new ApiResponseService())->success(new Response(), ['bad' => INF]);

        $this->assertSame(['ok' => false, 'message' => 'レスポンスの生成に失敗しました。'], $this->body($r));
    }
}
