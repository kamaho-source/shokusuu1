<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Facility Entity
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $facility_code
 * @property string $name
 * @property string $timezone
 * @property bool $is_active
 * @property \Cake\I18n\DateTime $created_at
 * @property \Cake\I18n\DateTime $updated_at
 * @property \App\Model\Entity\Tenant $tenant
 */
class Facility extends Entity
{
    protected array $_accessible = [
        'tenant_id'     => true,
        'facility_code' => true,
        'name'          => true,
        'timezone'      => true,
        'is_active'     => true,
        'created_at'    => true,
        'updated_at'    => true,
    ];
}
