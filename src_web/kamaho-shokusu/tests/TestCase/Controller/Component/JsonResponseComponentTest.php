<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Component;

use App\Controller\Component\JsonResponseComponent;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;

/**
 * JsonResponseComponent のテスト（success / error の配列形式）。
 */
class JsonResponseComponentTest extends TestCase
{
    private JsonResponseComponent $component;

    protected function setUp(): void
    {
        parent::setUp();
        $this->component = new JsonResponseComponent(new ComponentRegistry(new Controller(new ServerRequest())));
    }

    public function testSuccess_includesRedirectAndData(): void
    {
        $this->assertSame(
            ['status' => 'success', 'message' => '保存しました', 'data' => ['id' => 1], 'redirect' => '/x'],
            $this->component->success('保存しました', ['id' => 1], '/x')
        );
    }

    public function testSuccess_redirectDefaultsToNull(): void
    {
        $result = $this->component->success('ok');
        $this->assertNull($result['redirect']);
        $this->assertSame([], $result['data']);
    }

    public function testError_hasNoRedirectKey(): void
    {
        $this->assertSame(
            ['status' => 'error', 'message' => '失敗', 'data' => ['field' => 'a']],
            $this->component->error('失敗', ['field' => 'a'])
        );
    }
}
