<?php
declare(strict_types=1);

namespace App;

use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Identifier\AbstractIdentifier;
use Authentication\Middleware\AuthenticationMiddleware;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Authorization\AuthorizationService;
use Authorization\AuthorizationServiceInterface;
use Authorization\AuthorizationServiceProviderInterface;
use Authorization\Middleware\AuthorizationMiddleware;
use Authorization\Policy\MapResolver;
use Authorization\Policy\OrmResolver;
use Authorization\Policy\ResolverCollection;
use App\Application\AI\SystemPromptProviderInterface;
use App\Controller\AiAssistantController;
use App\Controller\RoomUsageController;
use App\Controller\StatsAiController;
use App\Infrastructure\AI\SystemPromptProvider;
use App\Infrastructure\AI\UserTokenizer;
use App\Service\AiStatsContextService;
use App\Service\FeatureUsageSummaryService;
use App\Service\RoomUsageService;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\Middleware\SecurityHeadersMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\ORM\Locator\TableLocator;
use Cake\Routing\Middleware\AssetMiddleware;
use App\Middleware\TenantResolutionMiddleware;
use Cake\Routing\Middleware\RoutingMiddleware;

class Application extends BaseApplication implements AuthenticationServiceProviderInterface, AuthorizationServiceProviderInterface
{
    public function bootstrap(): void
    {
        parent::bootstrap();

        $this->addPlugin('Authentication');
        $this->addPlugin('Authorization');

        if (PHP_SAPI === 'cli') {
            $this->bootstrapCli();
        } else {
            FactoryLocator::add('Table', (new TableLocator())->allowFallbackClass(false));
        }

        if (Configure::read('debug')) {
            $this->addPlugin('DebugKit');
        }
    }

    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        // 全レスポンス共通のセキュリティヘッダー
        // （クリックジャッキング・MIMEスニッフィング・リファラ漏洩対策）
        $securityHeaders = (new SecurityHeadersMiddleware())
            ->setXFrameOptions('sameorigin')
            ->noSniff()
            ->setReferrerPolicy('same-origin');

        $middlewareQueue
            ->add($securityHeaders)
            ->add(new ErrorHandlerMiddleware(Configure::read('Error'), $this))
            ->add(new AssetMiddleware(['cacheTime' => Configure::read('Asset.cacheTime')]))
            ->add(new RoutingMiddleware($this))
            ->add(new BodyParserMiddleware())
            ->add(new CsrfProtectionMiddleware(['httponly' => true]));

        $middlewareQueue->add(new TenantResolutionMiddleware());

        // AuthenticationMiddleware はオプションなしで登録
        $middlewareQueue->add(new AuthenticationMiddleware($this));
        $middlewareQueue->add(new AuthorizationMiddleware($this, [
            'requireAuthorizationCheck' => true,
        ]));

        // DebugKit（開発環境のみ）のコントローラーは authorize() を呼ばないため、
        // requireAuthorizationCheck により AuthorizationRequiredException が
        // error.log に記録され続ける。DebugKit のリクエストに限り認可チェックを免除する。
        if (Configure::read('debug')) {
            $middlewareQueue->add(function (ServerRequest $request, RequestHandlerInterface $handler): ResponseInterface {
                if ($request->getParam('plugin') === 'DebugKit') {
                    $authorization = $request->getAttribute('authorization');
                    if ($authorization !== null) {
                        $authorization->skipAuthorization();
                    }
                }

                return $handler->handle($request);
            });
        }

        return $middlewareQueue;
    }

    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        // リダイレクト先・queryParam はここで指定
        $authenticationService = new AuthenticationService([
            'unauthenticatedRedirect' => Router::url('/MUserInfo/login'),
            'queryParam'              => 'redirect',
        ]);

        // 識別子の設定
        // finder: 'forAuthentication' によりテナント境界・有効ユーザーの条件を付与する
        // tenant_id / facility_id をセッションアイデンティティに含めることで
        // Phase 2 以降の認可チェックで参照可能にする
        $authenticationService->loadIdentifier('Authentication.Password', [
            'resolver' => [
                'className' => 'Authentication.Orm',
                'userModel' => 'MUserInfo',
                'finder'    => 'forAuthentication',
                'fields'    => ['i_id_user', 'c_login_account', 'c_user_name', 'i_admin', 'i_user_level', 'tenant_id', 'facility_id'],
            ],
            'fields' => [
                'username' => 'c_login_account',
                'password' => 'c_login_passwd',
            ],
            'passwordHasher' => [
                'className' => DefaultPasswordHasher::class,
            ],
        ]);

        // 認証器の設定（セッション → フォーム）
        // identify: true でリクエストごとにDBから最新ユーザー情報を再取得する。
        // これによりDBでロール(i_admin)を変更した場合に再ログイン不要で即時反映される。
        $authenticationService->loadAuthenticator('Authentication.Session', [
            'identify' => true,
            'fields'   => [
                AbstractIdentifier::CREDENTIAL_USERNAME => 'c_login_account',
            ],
        ]);
        $authenticationService->loadAuthenticator('Authentication.Form', [
            'fields'   => [
                'username' => 'c_login_account',
                'password' => 'c_login_passwd',
            ],
            'loginUrl' => Router::url('/MUserInfo/login'),
        ]);

        return $authenticationService;
    }

    public function services(ContainerInterface $container): void
    {
        $container->add(SystemPromptProviderInterface::class, SystemPromptProvider::class);

        $container->add(AiAssistantController::class)
            ->addArgument(SystemPromptProviderInterface::class)
            ->addArgument(ServerRequest::class);

        $container->add(RoomUsageService::class);
        $container->add(RoomUsageController::class)
            ->addArgument(RoomUsageService::class)
            ->addArgument(ServerRequest::class);

        $container->add(UserTokenizer::class);
        $container->add(FeatureUsageSummaryService::class);
        $container->add(AiStatsContextService::class)
            ->addArgument(FeatureUsageSummaryService::class)
            ->addArgument(UserTokenizer::class)
            ->addArgument(RoomUsageService::class);
        $container->add(StatsAiController::class)
            ->addArgument(AiStatsContextService::class)
            ->addArgument(UserTokenizer::class)
            ->addArgument(ServerRequest::class);

        $container->add(\App\Application\UseCase\FacilitySetting\GetFacilitySettingUseCase::class)
            ->addArgument(\App\Model\Table\FacilitySettingsTable::class);
        $container->add(\App\Application\UseCase\FacilitySetting\SaveFacilitySettingUseCase::class)
            ->addArgument(\App\Model\Table\FacilitySettingsTable::class);

        $container->add(\App\Controller\FacilitySettingsController::class)
            ->addArgument(\App\Application\UseCase\FacilitySetting\GetFacilitySettingUseCase::class)
            ->addArgument(\App\Application\UseCase\FacilitySetting\SaveFacilitySettingUseCase::class)
            ->addArgument(ServerRequest::class);
    }

    public function getAuthorizationService(ServerRequestInterface $request): AuthorizationServiceInterface
    {
        // コントローラークラス → ポリシークラスの明示マッピング
        $mapResolver = new MapResolver([
            \App\Controller\ApprovalController::class     => \App\Policy\ApprovalPolicy::class,
            \App\Controller\NotificationsController::class => \App\Policy\NotificationPolicy::class,
            \App\Controller\ContactsController::class      => \App\Policy\ContactsPolicy::class,
            \App\Controller\AuditLogController::class             => \App\Policy\AuditLogPolicy::class,
            \App\Controller\RoomUsageController::class           => \App\Policy\RoomUsagePolicy::class,
            \App\Controller\FeatureUsageSummaryController::class => \App\Policy\FeatureUsageSummaryPolicy::class,
            \App\Controller\AiAssistantController::class      => \App\Policy\AiAssistantPolicy::class,
            \App\Controller\StatsAiController::class          => \App\Policy\StatsAiPolicy::class,
            \App\Controller\AdminTenantsController::class     => \App\Policy\AdminTenantsPolicy::class,
        ]);

        // MapResolver で解決できない場合は OrmResolver（エンティティ→ポリシー）にフォールバック
        $resolver = new ResolverCollection([$mapResolver, new OrmResolver()]);

        return new AuthorizationService($resolver);
    }

    protected function bootstrapCli(): void
    {
        $this->addOptionalPlugin('Bake');
        $this->addPlugin('Migrations');
    }
}