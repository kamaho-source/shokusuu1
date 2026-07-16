<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * MLpImage Entity
 *
 * LP（ランディングページ）に掲載する画像のマスタ。
 *
 * @property int $i_id
 * @property string $c_title
 * @property string $c_section
 * @property string $c_file_path
 * @property int $i_display
 * @property int $i_sort
 * @property \Cake\I18n\DateTime|null $dt_create
 * @property \Cake\I18n\DateTime|null $dt_update
 */
class MLpImage extends Entity
{
    protected array $_accessible = [
        'c_title'     => true,
        'c_section'   => true,
        'c_file_path' => true,
        'i_display'   => true,
        'i_sort'      => true,
        'dt_create'   => true,
        'dt_update'   => true,
    ];
}
