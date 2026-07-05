<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\AppController;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;

/**
 * AppController::isSafeRedirect のオープンリダイレクト防御を検証する。
 *
 * バックスラッシュ（ブラウザが / に正規化する）による
 * `/\evil.com` → `//evil.com` バイパス（CVE-2026-55590 と同種）を含む。
 */
class AppControllerRedirectTest extends TestCase
{
    private function callIsSafeRedirect(string|array|null $url): bool
    {
        $controller = new class (new ServerRequest()) extends AppController {
            public function initialize(): void
            {
                // コンポーネント・サービス初期化は検証対象外のためスキップ
            }
        };

        $method = new \ReflectionMethod($controller, 'isSafeRedirect');

        return (bool)$method->invoke($controller, $url);
    }

    public function testInternalPathIsAllowed(): void
    {
        $this->assertTrue($this->callIsSafeRedirect('/TReservationInfo/index'));
    }

    public function testBackslashBypassIsRejected(): void
    {
        $this->assertFalse($this->callIsSafeRedirect('/\\evil.com'));
        $this->assertFalse($this->callIsSafeRedirect('\\\\evil.com'));
        $this->assertFalse($this->callIsSafeRedirect('/foo\\bar'));
    }

    public function testProtocolRelativeUrlIsRejected(): void
    {
        $this->assertFalse($this->callIsSafeRedirect('//evil.com'));
    }

    public function testAbsoluteExternalUrlIsRejected(): void
    {
        $this->assertFalse($this->callIsSafeRedirect('https://evil.com/'));
    }

    public function testEmptyAndNullAreRejected(): void
    {
        $this->assertFalse($this->callIsSafeRedirect(''));
        $this->assertFalse($this->callIsSafeRedirect(null));
    }

    public function testRelativePathWithoutLeadingSlashIsRejected(): void
    {
        $this->assertFalse($this->callIsSafeRedirect('evil.com'));
    }
}
