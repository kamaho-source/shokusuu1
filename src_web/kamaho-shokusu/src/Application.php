<?php
declare(strict_types=1);

namespace App;

use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Middleware\AuthenticationMiddleware;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Authorization\AuthorizationService;
use Authorization\AuthorizationServiceInterface;
use Authorization\AuthorizationServiceProviderInterface;
use Authorization\Middleware\AuthorizationMiddleware;
use Authorization\Policy\MapResolver;
use Authorization\Policy\ResolverCollection;
use App\Application\AI\SystemPromptProviderInterface;
use App\Application\Query\DayViewQueryInterface;
use App\Application\Query\MealPriceQueryInterface;
use App\Application\Query\ContactQueryInterface;
use App\Application\Query\NoticeQueryInterface;
use App\Application\UseCase\AddContactReplyFromEmail\AddContactReplyFromEmailUseCase;
use App\Application\UseCase\AddContactReplyFromUser\AddContactReplyFromUserUseCase;
use App\Application\UseCase\ReplyToContact\ReplyToContactUseCase;
use App\Application\UseCase\SubmitContact\SubmitContactUseCase;
use App\Application\Query\RoomTransferQueryInterface;
use App\Application\UseCase\CreateMealPrice\CreateMealPriceUseCase;
use App\Application\UseCase\DeleteMealPrice\DeleteMealPriceUseCase;
use App\Application\UseCase\ExportMealSummary\ExportMealSummaryUseCase;
use App\Application\UseCase\UpdateMealPrice\UpdateMealPriceUseCase;
use App\Application\UseCase\CreateNotice\CreateNoticeUseCase;
use App\Application\UseCase\DeleteNotice\DeleteNoticeUseCase;
use App\Application\UseCase\UpdateNotice\UpdateNoticeUseCase;
use App\Application\Query\RoomUsageQueryInterface;
use App\Application\Query\SystemReportQueryInterface;
use App\Application\Query\RoomMealDetailQueryInterface;
use App\Application\UseCase\ApplyRoomTransfers\ApplyRoomTransfersUseCase;
use App\Application\UseCase\AssignUserRooms\AssignUserRoomsUseCase;
use App\Application\UseCase\CancelRoomTransfer\CancelRoomTransferUseCase;
use App\Application\UseCase\CreateRoomTransfer\CreateRoomTransferUseCase;
use App\Command\ApplyRoomTransferScheduleCommand;
use App\Application\UseCase\BulkAddReservations\BulkAddReservationsUseCase;
use App\Application\UseCase\BulkChangeEditReservations\BulkChangeEditReservationsUseCase;
use App\Application\UseCase\BulkToggleMeals\BulkToggleMealsUseCase;
use App\Application\UseCase\ChangeMealRoom\ChangeMealRoomUseCase;
use App\Application\UseCase\ReportNoMeal\ReportNoMealUseCase;
use App\Application\UseCase\SaveChangeEdit\SaveChangeEditUseCase;
use App\Application\UseCase\ChangeReportAccess\ChangeReportAccessUseCase;
use App\Application\UseCase\ChangeUserRole\ChangeUserRoleUseCase;
use App\Application\UseCase\BulkImportUsers\BulkImportUsersUseCase;
use App\Application\UseCase\ChangePassword\ChangePasswordUseCase;
use App\Application\UseCase\CopyReservations\CopyReservationsUseCase;
use App\Application\UseCase\CopyReservations\PreviewReservationCopyUseCase;
use App\Application\UseCase\CopyReservations\ReservationCopyPlanner;
use App\Application\UseCase\CreateRoom\CreateRoomUseCase;
use App\Application\UseCase\CreateUser\CreateUserUseCase;
use App\Application\UseCase\DeleteRoom\DeleteRoomUseCase;
use App\Application\UseCase\UpdateRoom\UpdateRoomUseCase;
use App\Application\UseCase\DeleteUser\DeleteUserUseCase;
use App\Application\UseCase\EditUser\EditUserUseCase;
use App\Application\UseCase\DirectRegisterMeals\DirectRegisterMealsUseCase;
use App\Application\UseCase\RegisterGroupReservation\RegisterGroupReservationUseCase;
use App\Application\UseCase\RegisterIndividualReservation\RegisterIndividualReservationUseCase;
use App\Application\UseCase\RestoreUser\RestoreUserUseCase;
use App\Application\UseCase\ToggleMeal\ToggleMealUseCase;
use App\Controller\AiAssistantController;
use App\Controller\RoomUsageController;
use App\Controller\StatsAiController;
use App\Controller\SystemReportController;
use App\Infrastructure\AI\SystemPromptProvider;
use App\Infrastructure\AI\UserTokenizer;
use App\Infrastructure\Authentication\UserIdSessionAuthenticator;
use App\Infrastructure\Log\AuditLogAdapter;
use App\Infrastructure\Persistence\CakeTransactionManager;
use App\Infrastructure\ReservationUseCases;
use App\Infrastructure\Repository\CakeReservationRepository;
use App\Infrastructure\Query\CakeDayViewQuery;
use App\Infrastructure\Query\CakeMealPriceQuery;
use App\Infrastructure\Query\CakeMealSummaryQuery;
use App\Infrastructure\Notification\CakeContactNotifier;
use App\Infrastructure\Query\CakeContactQuery;
use App\Infrastructure\Query\CakeNoticeQuery;
use App\Infrastructure\Repository\CakeContactRepository;
use App\Infrastructure\Query\CakeRoomTransferQuery;
use App\Infrastructure\Repository\CakeMealPriceRepository;
use App\Infrastructure\Repository\CakeNoticeRepository;
use App\Infrastructure\Query\CakeRoomUsageQuery;
use App\Infrastructure\Query\CakeSystemReportQuery;
use App\Infrastructure\Query\CakeRoomMealDetailQuery;
use App\Infrastructure\Query\CakeRoomQuery;
use App\Infrastructure\Query\CakeUserQuery;
use App\Infrastructure\Repository\CakeRoomMembershipRepository;
use App\Infrastructure\Cache\ReservationCacheAdapter;
use App\Infrastructure\Repository\CakeRoomRepository;
use App\Infrastructure\Repository\CakeRoomTransferScheduleRepository;
use App\Infrastructure\Repository\CakeUserAccountRepository;
use App\Service\AiStatsContextService;
use App\Service\FeatureUsageSummaryService;
use App\Policy\PersistenceOrmResolver;
use App\Application\Query\CalendarQueryInterface;
use App\Application\Query\ChangeEditQueryInterface;
use App\Application\Query\DashboardQueryInterface;
use App\Application\Query\MealCountGridQueryInterface;
use App\Application\Query\MealReportQueryInterface;
use App\Application\Query\ReservationHistoryQueryInterface;
use App\Application\Query\ReservationReadQueryInterface;
use App\Application\Query\RoomQueryInterface;
use App\Application\Query\UserQueryInterface;
use App\Controller\ApprovalController;
use App\Controller\MRoomInfoController;
use App\Controller\PagesController;
use App\Controller\ReservationActualMealController;
use App\Controller\ReservationAddController;
use App\Controller\ReservationBulkController;
use App\Controller\ReservationChangeEditController;
use App\Controller\ReservationCopyController;
use App\Controller\ReservationDirectRegisterController;
use App\Controller\ReservationHistoryController;
use App\Controller\ReservationReportController;
use App\Controller\ReservationToggleController;
use App\Controller\TReservationInfoController;
use App\Infrastructure\Query\CakeCalendarQuery;
use App\Infrastructure\Query\CakeChangeEditQuery;
use App\Infrastructure\Query\CakeDashboardQuery;
use App\Infrastructure\Query\CakeMealCountGridQuery;
use App\Infrastructure\Query\CakeMealReportQuery;
use App\Infrastructure\Query\CakeReservationHistoryQuery;
use App\Infrastructure\Query\CakeReservationReadQuery;
use App\Policy\ApprovalPolicy;
use App\Policy\MRoomInfoPolicy;
use App\Policy\MUserInfoPolicy;
use App\Policy\TReservationInfoPolicy;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Cake\Chronos\ClockFactory;
use Psr\Clock\ClockInterface;
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
use Cake\Routing\Middleware\RoutingMiddleware;

class Application extends BaseApplication implements AuthenticationServiceProviderInterface, AuthorizationServiceProviderInterface
{
    /** Table クラスの探索先（永続化層）。 */
    public const TABLE_LOCATION = 'Infrastructure/Persistence/Table';

    /**
     * Table の探索先。アプリは永続化層、プラグイン（DebugKit など）は CakePHP 既定の Model/Table。
     * 既定を外すとプラグインの Table が見つからず、Web（allowFallbackClass(false)）では 500 になる。
     */
    public const TABLE_LOCATIONS = [self::TABLE_LOCATION, 'Model/Table'];

    public function bootstrap(): void
    {
        parent::bootstrap();

        $this->addPlugin('Authentication');
        $this->addPlugin('Authorization');

        if (PHP_SAPI === 'cli') {
            $this->bootstrapCli();
        } else {
            FactoryLocator::add('Table', (new TableLocator(self::TABLE_LOCATIONS))->allowFallbackClass(false));
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
            ->add(new BodyParserMiddleware());

        // 外部サービス(Resend)からのWebhookはブラウザセッション/CSRFトークンを持たないため対象外とする。
        // 代わりにWebhook自体の署名検証（Svix HMAC）で保護する。
        $csrfMiddleware = new CsrfProtectionMiddleware(['httponly' => true]);
        $csrfMiddleware->skipCheckCallback(function (ServerRequest $request): bool {
            // RoutingMiddleware が先に実行されるため、ここではルーティング済みの
            // controller/actionパラメータで判定できる（パスの部分一致よりベースパスの影響を受けず確実）。
            // action名まで絞ることで、将来WebhooksControllerに他のアクションが増えても
            // 意図せずCSRF対象外になることを防ぐ。
            return $request->getParam('controller') === 'Webhooks'
                && $request->getParam('action') === 'resendInbound';
        });
        $middlewareQueue->add($csrfMiddleware);

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
        $authenticationService->loadIdentifier('Authentication.Password', [
            'resolver' => [
                'className' => 'Authentication.Orm',
                'userModel' => 'MUserInfo',
            ],
            'fields' => [
                'username' => 'c_login_account',
                'password' => 'c_login_passwd',
            ],
            'passwordHasher' => [
                'className' => DefaultPasswordHasher::class,
            ],
        ]);

        // セッションの利用者 ID から利用者を引く識別子（UserIdSessionAuthenticator が使う）
        $authenticationService->loadIdentifier('Authentication.Token', [
            'tokenField' => 'i_id_user',
            'dataField'  => 'key',
            'resolver'   => [
                'className' => 'Authentication.Orm',
                'userModel' => 'MUserInfo',
            ],
        ]);

        // 認証器の設定（セッション → フォーム）
        // セッションには利用者 ID だけを保存し、リクエストのたびに DB から最新の利用者を読み直す。
        // これによりDBでロール(i_admin)を変更した場合に再ログイン不要で即時反映される。
        // Entity をセッションに保存しないので、Entity のクラスを移してもログイン中のセッションが壊れない。
        $authenticationService->loadAuthenticator(UserIdSessionAuthenticator::class, [
            'idField'       => 'i_id_user',
            'identifierKey' => 'key',
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
        // 時計は1つだけ（Asia/Tokyo。Chronos::setTestNow に従う）。各所で new せず、ここから受け取る
        $container->addShared(ClockInterface::class, static fn(): ClockInterface => new ClockFactory('Asia/Tokyo'));
        $clock = static fn(): ClockInterface => $container->get(ClockInterface::class);

        $container->add(SystemPromptProviderInterface::class, SystemPromptProvider::class);

        $container->add(AiAssistantController::class)
            ->addArgument(SystemPromptProviderInterface::class)
            ->addArgument(ServerRequest::class);

        $container->add(RoomUsageQueryInterface::class, static fn() => new CakeRoomUsageQuery($clock()));
        $container->add(RoomUsageController::class)
            ->addArgument(RoomUsageQueryInterface::class)
            ->addArgument(ServerRequest::class);

        $container->add(UserTokenizer::class);
        $container->add(FeatureUsageSummaryService::class);
        $container->add(AiStatsContextService::class)
            ->addArgument(FeatureUsageSummaryService::class)
            ->addArgument(UserTokenizer::class)
            ->addArgument(RoomUsageQueryInterface::class);
        $container->add(StatsAiController::class)
            ->addArgument(AiStatsContextService::class)
            ->addArgument(UserTokenizer::class)
            ->addArgument(ServerRequest::class);

        // 予約の書き込みの UseCase（Controller はアクションの引数で受け取る）。
        // Repository を共有するため、組み立て口（ReservationUseCases）は1リクエストに1つにする
        $container->addShared(ReservationUseCases::class, static function () use ($container): ReservationUseCases {
            $tables = FactoryLocator::get('Table');

            return new ReservationUseCases($tables->get('TIndividualReservationInfo'), $tables->get('MUserInfo'), $container->get(ClockInterface::class));
        });
        foreach ([
            ToggleMealUseCase::class                   => 'toggleMeal',
            BulkToggleMealsUseCase::class              => 'bulkToggleMeals',
            ChangeMealRoomUseCase::class               => 'changeMealRoom',
            DirectRegisterMealsUseCase::class          => 'directRegisterMeals',
            RegisterIndividualReservationUseCase::class => 'registerIndividualReservation',
            RegisterGroupReservationUseCase::class     => 'registerGroupReservation',
            BulkAddReservationsUseCase::class          => 'bulkAddReservations',
            BulkChangeEditReservationsUseCase::class   => 'bulkChangeEditReservations',
            ReportNoMealUseCase::class                 => 'reportNoMeal',
            SaveChangeEditUseCase::class               => 'saveChangeEdit',
        ] as $useCase => $factory) {
            $container->add($useCase, static fn() => $container->get(ReservationUseCases::class)->{$factory}());
        }

        // 利用者の管理の UseCase（Controller はアクションの引数で受け取る）
        $userAccounts = static fn(): CakeUserAccountRepository => new CakeUserAccountRepository(FactoryLocator::get('Table')->get('MUserInfo'));
        $container->add(ChangeUserRoleUseCase::class, static fn() => new ChangeUserRoleUseCase($userAccounts(), new AuditLogAdapter()));
        $container->add(ChangeReportAccessUseCase::class, static fn() => new ChangeReportAccessUseCase($userAccounts(), new AuditLogAdapter()));
        $userTransaction = static fn(): CakeTransactionManager => new CakeTransactionManager(FactoryLocator::get('Table')->get('MUserInfo')->getConnection());
        $container->add(DeleteUserUseCase::class, static fn() => new DeleteUserUseCase(
            $userAccounts(),
            new CakeReservationRepository(FactoryLocator::get('Table')->get('TIndividualReservationInfo')),
            $userTransaction(),
            new AuditLogAdapter(),
            $clock(),
        ));
        $container->add(RestoreUserUseCase::class, static fn() => new RestoreUserUseCase($userAccounts(), $userTransaction(), new AuditLogAdapter()));
        $container->add(CreateUserUseCase::class, static fn() => new CreateUserUseCase(
            $userAccounts(),
            new AuditLogAdapter(),
            $clock(),
        ));
        $container->add(EditUserUseCase::class, static fn() => new EditUserUseCase(
            $userAccounts(),
            new CakeRoomMembershipRepository(),
            $userTransaction(),
            new AuditLogAdapter(),
            $clock(),
        ));
        $container->add(BulkImportUsersUseCase::class, static fn() => new BulkImportUsersUseCase(
            $userAccounts(),
            new CakeRoomMembershipRepository(),
            $userTransaction(),
            new AuditLogAdapter(),
            $clock(),
        ));
        $container->add(ChangePasswordUseCase::class, static fn() => new ChangePasswordUseCase($userAccounts(), new AuditLogAdapter()));
        $container->add(UserQueryInterface::class, static fn() => new CakeUserQuery($userAccounts(), new CakeRoomMembershipRepository()));
        $container->add(CreateRoomUseCase::class, static fn() => new CreateRoomUseCase(new CakeRoomRepository(), new AuditLogAdapter(), $clock()));
        $container->add(UpdateRoomUseCase::class, static fn() => new UpdateRoomUseCase(new CakeRoomRepository(), $userTransaction(), new AuditLogAdapter(), $clock()));
        $container->add(DeleteRoomUseCase::class, static fn() => new DeleteRoomUseCase(new CakeRoomRepository(), new AuditLogAdapter(), $clock()));
        $container->add(CreateRoomTransferUseCase::class, static fn() => new CreateRoomTransferUseCase(new CakeRoomTransferScheduleRepository(), $clock()));
        $container->add(CancelRoomTransferUseCase::class, static fn() => new CancelRoomTransferUseCase(new CakeRoomTransferScheduleRepository(), $clock()));
        $container->add(ApplyRoomTransfersUseCase::class, static fn() => new ApplyRoomTransfersUseCase(
            new CakeRoomTransferScheduleRepository(),
            $userAccounts(),
            new CakeReservationRepository(FactoryLocator::get('Table')->get('TIndividualReservationInfo')),
            $userTransaction(),
            new ReservationCacheAdapter(),
            $clock(),
        ));
        $container->add(ApplyRoomTransferScheduleCommand::class)->addArgument(ApplyRoomTransfersUseCase::class);
        $copyPlanner = static fn(CakeReservationRepository $reservations): ReservationCopyPlanner => new ReservationCopyPlanner(
            $reservations,
            new CakeRoomMembershipRepository(),
            $userAccounts(),
            $clock(),
        );
        $container->add(ReservationCopyPlanner::class, static fn() => $copyPlanner(
            new CakeReservationRepository(FactoryLocator::get('Table')->get('TIndividualReservationInfo')),
        ));
        $container->add(CopyReservationsUseCase::class, static function () use ($copyPlanner, $userTransaction): CopyReservationsUseCase {
            // 判定（Planner）と書き込みで同じリポジトリを使い、同じトランザクションの中で読み書きする
            $reservations = new CakeReservationRepository(FactoryLocator::get('Table')->get('TIndividualReservationInfo'));

            return new CopyReservationsUseCase($copyPlanner($reservations), $reservations, $userTransaction());
        });
        $container->add(PreviewReservationCopyUseCase::class)->addArgument(ReservationCopyPlanner::class);
        $container->add(NoticeQueryInterface::class, CakeNoticeQuery::class);
        $container->add(MealPriceQueryInterface::class, CakeMealPriceQuery::class);
        $container->add(RoomTransferQueryInterface::class, CakeRoomTransferQuery::class);
        $container->add(ContactQueryInterface::class, CakeContactQuery::class);
        $container->add(SubmitContactUseCase::class, static fn() => new SubmitContactUseCase(new CakeContactRepository(), new CakeContactNotifier()));
        $container->add(ReplyToContactUseCase::class, static fn() => new ReplyToContactUseCase(new CakeContactRepository(), new CakeContactNotifier()));
        $container->add(AddContactReplyFromUserUseCase::class, static fn() => new AddContactReplyFromUserUseCase(new CakeContactRepository(), new CakeContactNotifier()));
        $container->add(AddContactReplyFromEmailUseCase::class, static fn() => new AddContactReplyFromEmailUseCase(new CakeContactRepository(), new CakeContactNotifier()));
        $container->add(CreateMealPriceUseCase::class, static fn() => new CreateMealPriceUseCase(new CakeMealPriceRepository(), new AuditLogAdapter()));
        $container->add(UpdateMealPriceUseCase::class, static fn() => new UpdateMealPriceUseCase(new CakeMealPriceRepository(), new AuditLogAdapter()));
        $container->add(DeleteMealPriceUseCase::class, static fn() => new DeleteMealPriceUseCase(new CakeMealPriceRepository(), new AuditLogAdapter()));
        $container->add(ExportMealSummaryUseCase::class, static fn() => new ExportMealSummaryUseCase(new CakeMealPriceRepository(), new CakeMealSummaryQuery($clock()), new AuditLogAdapter()));
        $container->add(CreateNoticeUseCase::class, static fn() => new CreateNoticeUseCase(new CakeNoticeRepository(), new AuditLogAdapter()));
        $container->add(UpdateNoticeUseCase::class, static fn() => new UpdateNoticeUseCase(new CakeNoticeRepository(), new AuditLogAdapter()));
        $container->add(DeleteNoticeUseCase::class, static fn() => new DeleteNoticeUseCase(new CakeNoticeRepository(), new AuditLogAdapter()));
        $container->add(DayViewQueryInterface::class, static fn() => new CakeDayViewQuery($container->get(RoomQueryInterface::class), $clock()));
        $container->add(RoomMealDetailQueryInterface::class, static fn() => new CakeRoomMealDetailQuery($clock()));
        $container->add(AssignUserRoomsUseCase::class, static fn() => new AssignUserRoomsUseCase($userAccounts(), new CakeRoomMembershipRepository(), $userTransaction()));

        // 読み取り（Query）。Controller・Policy は new せず、ここから受け取る
        $container->add(RoomQueryInterface::class, CakeRoomQuery::class);
        $container->add(CalendarQueryInterface::class, static fn() => new CakeCalendarQuery($clock()));
        $container->add(ReservationReadQueryInterface::class, static fn() => new CakeReservationReadQuery($container->get(RoomQueryInterface::class), $clock()));
        $container->add(ChangeEditQueryInterface::class, static fn() => new CakeChangeEditQuery($clock(), new CakeRoomMembershipRepository()));
        $container->add(MealReportQueryInterface::class, static fn() => new CakeMealReportQuery($clock()));
        $container->add(MealCountGridQueryInterface::class, static fn() => new CakeMealCountGridQuery($clock()));
        $container->add(ReservationHistoryQueryInterface::class, static fn() => new CakeReservationHistoryQuery($container->get(RoomQueryInterface::class), $clock()));
        $container->add(DashboardQueryInterface::class, CakeDashboardQuery::class);

        // Controller のコンストラクタ注入（全アクションで使う依存。アクションだけで使うものはアクションの引数で受け取る）
        foreach ([
            TReservationInfoController::class,
            ReservationAddController::class,
            ReservationBulkController::class,
            ReservationCopyController::class,
            ReservationDirectRegisterController::class,
            ReservationToggleController::class,
            ReservationActualMealController::class,
        ] as $reservationController) {
            $container->add($reservationController)
                ->addArguments([ServerRequest::class, CalendarQueryInterface::class, RoomQueryInterface::class, ReservationReadQueryInterface::class]);
        }
        $container->add(ReservationChangeEditController::class)
            ->addArguments([ServerRequest::class, CalendarQueryInterface::class, RoomQueryInterface::class, ReservationReadQueryInterface::class, ChangeEditQueryInterface::class]);
        $container->add(ReservationReportController::class)
            ->addArguments([ServerRequest::class, CalendarQueryInterface::class, RoomQueryInterface::class, ReservationReadQueryInterface::class, MealReportQueryInterface::class]);
        $container->add(ReservationHistoryController::class)->addArguments([ServerRequest::class, ReservationHistoryQueryInterface::class]);
        $container->add(MRoomInfoController::class)->addArguments([ServerRequest::class, RoomQueryInterface::class]);
        $container->add(ApprovalController::class)->addArguments([ServerRequest::class, RoomQueryInterface::class]);
        $container->add(PagesController::class)->addArguments([ServerRequest::class, DashboardQueryInterface::class, NoticeQueryInterface::class, RoomQueryInterface::class]);

        // Policy（Resolver がコンテナから作る）
        foreach ([TReservationInfoPolicy::class, MRoomInfoPolicy::class, MUserInfoPolicy::class, ApprovalPolicy::class] as $policy) {
            $container->add($policy)->addArgument(RoomQueryInterface::class);
        }

        $container->add(SystemReportQueryInterface::class, static fn() => new CakeSystemReportQuery($clock()));
        $container->add(SystemReportController::class)
            ->addArgument(SystemReportQueryInterface::class)
            ->addArgument(ServerRequest::class);
    }

    public function getAuthorizationService(ServerRequestInterface $request): AuthorizationServiceInterface
    {
        // コントローラークラス → ポリシークラスの明示マッピング
        // Policy の依存（RoomQuery など）はコンテナから渡す
        $container = $this->getContainer();
        $mapResolver = new MapResolver([
            \App\Controller\ApprovalController::class     => \App\Policy\ApprovalPolicy::class,
            \App\Controller\NotificationsController::class => \App\Policy\NotificationPolicy::class,
            \App\Controller\ContactsController::class      => \App\Policy\ContactsPolicy::class,
            \App\Controller\AuditLogController::class             => \App\Policy\AuditLogPolicy::class,
            \App\Controller\RoomUsageController::class           => \App\Policy\RoomUsagePolicy::class,
            \App\Controller\FeatureUsageSummaryController::class => \App\Policy\FeatureUsageSummaryPolicy::class,
            \App\Controller\AiAssistantController::class      => \App\Policy\AiAssistantPolicy::class,
            \App\Controller\StatsAiController::class          => \App\Policy\StatsAiPolicy::class,
            \App\Controller\SystemReportController::class     => \App\Policy\SystemReportPolicy::class,
            \App\Controller\ReservationHistoryController::class => \App\Policy\ReservationHistoryPolicy::class,
        ], $container);

        // MapResolver で解決できない場合は、永続化層のエンティティ・テーブル→ポリシーにフォールバック
        $resolver = new ResolverCollection([$mapResolver, new PersistenceOrmResolver('App', [], $container)]);

        return new AuthorizationService($resolver);
    }

    protected function bootstrapCli(): void
    {
        $this->addOptionalPlugin('Bake');
        $this->addOptionalPlugin('IdeHelper');
        $this->addPlugin('Migrations');
    }
}