<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Persistence;

use App\Infrastructure\Persistence\Entity\MUserInfo;
use App\Infrastructure\Persistence\Entity\TReservationInfo;
use App\Policy\MUserInfoPolicy;
use App\Policy\PersistenceOrmResolver;
use App\Policy\TReservationInfoPolicy;
use Cake\Datasource\FactoryLocator;
use Cake\TestSuite\TestCase;

/**
 * Table・Entity を永続化層（src/Infrastructure/Persistence）に置いたときの探索のテスト。
 *
 * テスト環境の Table 探索はクラスが無いと汎用の Table で代用するため、黙って代用されていないことを確かめる。
 */
class PersistenceLocationTest extends TestCase
{
    public function testEveryTableClassIsFoundByAlias_withItsEntity(): void
    {
        $dir = dirname(__DIR__, 4) . '/src/Infrastructure/Persistence';
        $locator = FactoryLocator::get('Table');
        $files = glob($dir . '/Table/*Table.php') ?: [];
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $alias = basename($file, 'Table.php');
            $table = $locator->get($alias);
            $this->assertSame('App\\Infrastructure\\Persistence\\Table\\' . $alias . 'Table', get_class($table), $alias);
            $this->assertStringStartsWith('App\\Infrastructure\\Persistence\\Entity\\', $table->getEntityClass(), $alias);
        }
    }

    public function testEntityPoliciesAreResolvedFromPersistenceNamespace(): void
    {
        $resolver = new PersistenceOrmResolver();

        $this->assertInstanceOf(MUserInfoPolicy::class, $resolver->getPolicy(new MUserInfo()));
        $this->assertInstanceOf(TReservationInfoPolicy::class, $resolver->getPolicy(new TReservationInfo()));
    }

    /**
     * プラグイン（DebugKit など）の Table は CakePHP 既定の Model/Table から探すため、探索先から外さない。
     * 外すと Web（見つからないときに代用しない設定）でログイン画面から 500 になる。
     */
    public function testTableLocationsKeepCakeDefaultForPlugins(): void
    {
        $this->assertSame(['Infrastructure/Persistence/Table', 'Model/Table'], \App\Application::TABLE_LOCATIONS);
    }
}
