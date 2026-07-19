<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * @property int $id
 * @property string $tenant_code
 * @property string $name
 * @property string $status
 * @property \Cake\I18n\DateTime|null $trial_expires_at
 * @property string|null $stripe_customer_id
 * @property string|null $billing_contact_name
 * @property string|null $billing_contact_email
 * @property string|null $billing_address
 * @property string|null $plan_code
 * @property \Cake\I18n\DateTime|null $contract_started_at
 * @property \Cake\I18n\DateTime|null $contract_ended_at
 * @property \Cake\I18n\DateTime $created_at
 * @property \Cake\I18n\DateTime $updated_at
 */
class Tenant extends Entity
{
    protected array $_accessible = [
        'tenant_code'           => true,
        'name'                  => true,
        'status'                => true,
        'trial_expires_at'      => true,
        'stripe_customer_id'    => true,
        'billing_contact_name'  => true,
        'billing_contact_email' => true,
        'billing_address'       => true,
        'plan_code'             => true,
        'contract_started_at'   => true,
        'contract_ended_at'     => true,
        'created_at'            => true,
        'updated_at'            => true,
    ];
}
