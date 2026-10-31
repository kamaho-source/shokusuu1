<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\ErrorController;
use Cake\Event\Event;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;

/**
 * ErrorController のテスト（レイアウト用変数の既定値とテンプレートパス）。
 */
class ErrorControllerTest extends TestCase
{
    public function testInitialize_setsSafeLayoutDefaults(): void
    {
        $controller = new ErrorController(new ServerRequest());

        $vars = $controller->viewBuilder()->getVars();
        $this->assertSame(0, $vars['notificationUnreadCount']);
        $this->assertSame([], $vars['recentNotifications']);
        $this->assertNull($vars['user']);
    }

    public function testBeforeRender_usesErrorTemplatePath(): void
    {
        $controller = new ErrorController(new ServerRequest());

        $controller->beforeRender(new Event('Controller.beforeRender', $controller));

        $this->assertSame('Error', $controller->viewBuilder()->getTemplatePath());
    }
}
