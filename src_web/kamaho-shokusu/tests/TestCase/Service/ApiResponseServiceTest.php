<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ApiResponseService;
use Cake\Http\Response;
use Cake\TestSuite\TestCase;

class ApiResponseServiceTest extends TestCase
{
    private ApiResponseService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new ApiResponseService();
    }

    // ---------------------------------------------------------------------------
    // success()
    // ---------------------------------------------------------------------------

    public function testSuccessReturnsOkTrueWithData(): void
    {
        $response = $this->service->success(new Response(), ['id' => 1], null, 200);
        $body = json_decode((string)$response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['ok']);
        $this->assertSame(['id' => 1], $body['data']);
        $this->assertArrayNotHasKey('message', $body);
    }

    public function testSuccessIncludesMessageWhenProvided(): void
    {
        $response = $this->service->success(new Response(), [], '保存しました');
        $body = json_decode((string)$response->getBody(), true);

        $this->assertTrue($body['ok']);
        $this->assertSame('保存しました', $body['message']);
    }

    public function testSuccessDoesNotIncludeMessageWhenNull(): void
    {
        $response = $this->service->success(new Response(), [], null);
        $body = json_decode((string)$response->getBody(), true);

        $this->assertArrayNotHasKey('message', $body);
    }

    public function testSuccessDoesNotIncludeMessageWhenEmptyString(): void
    {
        $response = $this->service->success(new Response(), [], '');
        $body = json_decode((string)$response->getBody(), true);

        $this->assertArrayNotHasKey('message', $body);
    }

    public function testSuccessReturnsCustomStatusCode(): void
    {
        $response = $this->service->success(new Response(), [], null, 201);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testSuccessContentTypeIsJson(): void
    {
        $response = $this->service->success(new Response());

        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    // ---------------------------------------------------------------------------
    // error()
    // ---------------------------------------------------------------------------

    public function testErrorReturnsOkFalseWithMessage(): void
    {
        $response = $this->service->error(new Response(), '入力エラー', 400);
        $body = json_decode((string)$response->getBody(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($body['ok']);
        $this->assertSame('入力エラー', $body['message']);
        $this->assertSame([], $body['data']);
    }

    public function testErrorIncludesDataWhenProvided(): void
    {
        $response = $this->service->error(new Response(), 'エラー', 422, ['field' => 'required']);
        $body = json_decode((string)$response->getBody(), true);

        $this->assertSame(['field' => 'required'], $body['data']);
    }

    public function testErrorDefaultStatusIs400(): void
    {
        $response = $this->service->error(new Response(), 'エラー');

        $this->assertSame(400, $response->getStatusCode());
    }

    // ---------------------------------------------------------------------------
    // forbidden()
    // ---------------------------------------------------------------------------

    public function testForbiddenReturns403WithDefaultMessage(): void
    {
        $response = $this->service->forbidden(new Response());
        $body = json_decode((string)$response->getBody(), true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($body['ok']);
        $this->assertSame('権限がありません。', $body['message']);
    }

    public function testForbiddenUsesCustomMessageWhenProvided(): void
    {
        $response = $this->service->forbidden(new Response(), 'アクセス拒否');
        $body = json_decode((string)$response->getBody(), true);

        $this->assertSame('アクセス拒否', $body['message']);
    }
}
