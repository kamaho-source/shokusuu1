<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\CreateRoom;

use App\Application\Port\AuditActor;
use App\Application\UseCase\CreateRoom\CreateRoomInput;
use App\Application\UseCase\CreateRoom\CreateRoomUseCase;
use App\Application\UseCase\DeleteRoom\DeleteRoomInput;
use App\Application\UseCase\DeleteRoom\DeleteRoomUseCase;
use App\Infrastructure\Log\AuditLogAdapter;
use App\Infrastructure\Repository\CakeRoomRepository;
use Cake\Chronos\ClockFactory;
use Cake\TestSuite\TestCase;

/**
 * 部屋の作成・削除を、本物の Repository・監査ログにつないで確かめる（旧 RoomServiceTest の採番・削除を引き継ぐ）。
 * フィクスチャ: 部屋 1（表示順は fixture の値）。
 */
class CreateDeleteRoomIntegrationTest extends TestCase
{
    protected array $fixtures = [
        'app.MRoomInfo',
        'app.MUserGroup',
        'app.MUserInfo',
        'app.TAuditLog',
    ];

    private function actor(): AuditActor
    {
        return new AuditActor('test_admin', 1, 'admin_user', '127.0.0.1');
    }

    public function testCreate_nextDisplayNoCreatorAndAudit(): void
    {
        $rooms = $this->getTableLocator()->get('MRoomInfo');
        $max = (int)$rooms->find()->select(['m' => 'MAX(i_disp_no)'])->enableHydration(false)->first()['m'];

        $result = (new CreateRoomUseCase(new CakeRoomRepository(), new AuditLogAdapter(), new ClockFactory('Asia/Tokyo')))
            ->execute(new CreateRoomInput(['c_room_name' => 'さくら', 'i_disp_no' => 999, 'i_del_flg' => 1, 'i_enable' => 1], $this->actor()));

        $this->assertTrue($result->created);
        $room = $rooms->find()->where(['c_room_name' => 'さくら'])->firstOrFail();
        $this->assertSame($max + 1, (int)$room->i_disp_no, '部屋名以外は受け取らない');
        $this->assertSame(0, (int)$room->i_del_flg);
        $this->assertNull($room->i_enable);
        $this->assertSame('test_admin', $room->c_create_user);
        $log = $this->getTableLocator()->get('TAuditLog')->find()->where(['c_action' => 'room_create'])->firstOrFail();
        $this->assertSame((string)$room->i_id_room, (string)$log->c_target_id);
        $this->assertStringContainsString('さくら', (string)$log->c_detail);
    }

    public function testCreate_tooLongName_returnsFieldError(): void
    {
        $result = (new CreateRoomUseCase(new CakeRoomRepository(), new AuditLogAdapter(), new ClockFactory('Asia/Tokyo')))
            ->execute(new CreateRoomInput(['c_room_name' => str_repeat('あ', 51)], $this->actor()));

        $this->assertFalse($result->created);
        $this->assertArrayHasKey('c_room_name', $result->fieldErrors);
        $this->assertSame(0, $this->getTableLocator()->get('TAuditLog')->find()->where(['c_action' => 'room_create'])->count());
    }

    public function testDelete_setsFlagUpdaterAndAudit(): void
    {
        $deleted = (new DeleteRoomUseCase(new CakeRoomRepository(), new AuditLogAdapter(), new ClockFactory('Asia/Tokyo')))
            ->execute(new DeleteRoomInput(1, $this->actor()));

        $this->assertTrue($deleted);
        $room = $this->getTableLocator()->get('MRoomInfo')->get(1);
        $this->assertSame(1, (int)$room->i_del_flg);
        $this->assertSame('test_admin', $room->c_update_user);
        $this->assertSame(1, (int)$this->getTableLocator()->get('TAuditLog')->find()->where(['c_action' => 'room_delete'])->firstOrFail()->i_result);
    }

    public function testDelete_missingRoom_recordsFailure(): void
    {
        $deleted = (new DeleteRoomUseCase(new CakeRoomRepository(), new AuditLogAdapter(), new ClockFactory('Asia/Tokyo')))
            ->execute(new DeleteRoomInput(999, $this->actor()));

        $this->assertFalse($deleted);
        $this->assertSame(0, (int)$this->getTableLocator()->get('TAuditLog')->find()->where(['c_action' => 'room_delete'])->firstOrFail()->i_result);
    }
}
