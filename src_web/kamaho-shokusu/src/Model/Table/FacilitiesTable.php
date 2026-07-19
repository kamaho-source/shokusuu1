<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

class FacilitiesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('facilities');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }
}
