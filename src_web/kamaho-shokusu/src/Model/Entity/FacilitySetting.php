<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * FacilitySetting Entity
 *
 * @property int $id
 * @property int $facility_id
 * @property int $tenant_id
 * @property int $reservation_changeable_days   予約変更可能日数
 * @property bool $enable_weekly_bulk           週単位一括予約
 * @property bool $enable_monthly_bulk          月単位一括予約
 * @property bool $lunch_bento_exclusive        昼食と弁当の排他
 * @property bool $approval_enabled             承認機能の利用
 * @property bool $resident_self_edit_enabled   利用者本人の予約変更
 * @property string|null $fiscal_year_update_date  年度更新日（MM-DD形式）
 * @property string|null $export_template_code  Excel出力テンプレート
 * @property \Cake\I18n\Date|null $reservation_deadline_time  予約締切時刻
 * @property \Cake\I18n\DateTime $created_at
 * @property \Cake\I18n\DateTime $updated_at
 */
class FacilitySetting extends Entity
{
    protected array $_accessible = [
        'reservation_changeable_days'  => true,
        'enable_weekly_bulk'           => true,
        'enable_monthly_bulk'          => true,
        'lunch_bento_exclusive'        => true,
        'approval_enabled'             => true,
        'resident_self_edit_enabled'   => true,
        'fiscal_year_update_date'      => true,
        'export_template_code'         => true,
        'reservation_deadline_time'    => true,
        'facility_id'                  => false,
        'tenant_id'                    => false,
    ];
}
