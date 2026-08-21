<?php

declare( strict_types=1 );

namespace Inc;

use Inc\Contracts\ServiceInterface;
use Inc\Enums\Settings\OptionName;
use Inc\Modules\AdSync\AdSyncModule;
use Inc\Modules\DaData\DaDataModule;
use Inc\Modules\EgeComputer\EgeComputerModule;
use Inc\Modules\SmartCaptcha\SmartCaptchaModule;
use Inc\Modules\VideoLibrary\VideoLibraryModule;
use Inc\Controllers\Enrollment\ApplicationController;
use Inc\Controllers\Pages\ApplyPageController;
use Inc\Controllers\Person\ConsentController;
use Inc\Controllers\System\CronController;
use Inc\Controllers\System\AdminController;
use Inc\Controllers\System\AdminFooterModalsController;
use Inc\Controllers\System\MediaUploadController;
use Inc\Controllers\System\ModulesDashboardController;
use Inc\Controllers\Task\BoilerplateController;
use Inc\Controllers\Enrollment\EnrollmentController;
use Inc\Controllers\Course\LessonController;
use Inc\Controllers\Course\LessonMetaBoxController;
use Inc\Controllers\Course\WorkController;
use Inc\Controllers\Course\WorkMetaBoxController;
use Inc\Controllers\Course\CourseBuilderController;
use Inc\Controllers\Course\CourseController;
use Inc\Controllers\Course\CourseMetaBoxController;
use Inc\Controllers\Assessment\AssessmentMetaBoxController;
use Inc\Controllers\Course\BankChromeController;
use Inc\Controllers\Course\BankListTableController;
use Inc\Controllers\Course\BankRowActionsController;
use Inc\Controllers\Course\LearningMenuController;
use Inc\Controllers\Subject\ContentDeletionGuard;
use Inc\Controllers\Problems\ProblemsController;
use Inc\Controllers\Task\MetaBoxController;
use Inc\Controllers\Person\AuthPageController;
use Inc\Controllers\Person\PiiController;
use Inc\Controllers\Person\ProfileController;
use Inc\Controllers\Enrollment\ExpulsionController;
use Inc\Controllers\Enrollment\RecoveryController;
use Inc\Controllers\Group\StudentGroupController;
use Inc\Controllers\Subject\SubjectController;
use Inc\Controllers\Task\TaskCreationController;
use Inc\Controllers\Pages\AllTasksPageController;
use Inc\Controllers\Article\ArticleMetaBoxController;
use Inc\Controllers\Article\ArticleSlugController;
use Inc\Controllers\Pages\ArticlePageController;
use Inc\Controllers\Pages\SubjectLandingController;
use Inc\Controllers\Pages\AssessmentPageController;
use Inc\Controllers\Pages\TaskPageController;
use Inc\Controllers\Log\LogsController;
use Inc\Controllers\Settings\ConfigController;
use Inc\Controllers\Settings\SettingsController;
use Inc\Controllers\Subscribers\AuthLogController; // логирует общие WP-события (wp_login, wp_login_failed)
use Inc\Controllers\Subscribers\EntityAuditSubscriber;
use Inc\Controllers\Subscribers\PostEntityAuditController;
use Inc\Controllers\Subscribers\EnrollmentAuditSubscriber;
use Inc\Controllers\Subscribers\PiiAccessSubscriber;
use Inc\Controllers\Subscribers\DataChangeSubscriber;
use Inc\Controllers\Subscribers\ConsentChangeSubscriber;
use Inc\Controllers\Subscribers\EmailSubscriber;
use Inc\Controllers\Subscribers\DeletionSubscriber;
use Inc\Controllers\Subscribers\LearningEventSubscriber;
use Inc\Controllers\Subscribers\NotificationSubscriber;
use Inc\Controllers\Deletion\DeletionController;
use Inc\Controllers\Assessment\AssessmentController;
use Inc\Controllers\Group\ScheduleController;
use Inc\Controllers\Group\SubstitutionController;
use Inc\Controllers\Group\RoomController;
use Inc\Controllers\Profile\ProfileDashboardController;
use Inc\Controllers\Profile\LearnerProfileController;
use Inc\Controllers\Profile\NotificationController;
use Inc\Controllers\Group\JournalController;
use Inc\Controllers\Course\CoursePreviewController;
use Inc\Controllers\Course\PreviewSolveController;
use Inc\Controllers\Course\LessonPlayerController;
use Inc\Controllers\Course\LessonProgressController;
use Inc\Controllers\Course\SubmissionController;
use Inc\Cli\ArticleSlugCommand;
use Inc\Cli\SubjectBundleCommand;
use Inc\Cli\TaskBundleMigrationCommand;
use Inc\Controllers\Import\ImportController;
use Inc\Controllers\Person\UserController;
use Inc\Services\Export\ExportServiceBootstrap;
use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Core\Container;
use Inc\Core\Enqueue;
use Inc\Migrations\ArticlesSectionMigration;
use Inc\Migrations\AssessmentAnswerUniqueMigration;
use Inc\Migrations\BroadcastStepMigration;
use Inc\Migrations\Migration_1_0_0;
use Inc\Migrations\Migration_1_1_0;
use Inc\Migrations\MigrationRunner;
use Inc\Migrations\RoutingPagesMigration;
use Inc\Services\System\PageGeneratorService;
use Inc\Services\Log\LogEventDispatcher;
use Inc\Services\Shared\WpClock;

/**
 * Class Init
 *
 * Точка входа для инициализации всех сервисов плагина.
 *
 * Реализует паттерн Service Registry — централизованно управляет
 * списком всех сервисов, которые необходимо зарегистрировать.
 *
 * Использует DI-контейнер для автоматического разрешения зависимостей
 * и гарантирует, что каждый сервис реализует интерфейс ServiceInterface.
 *
 * @package Inc
 *
 * @example
 * // Запуск плагина
 * Init::run();
 */
final class Init {

	/**
	 * Возвращает список всех сервисов плагина.
	 *
	 * Сервисы регистрируются в порядке добавления.
	 * Каждый сервис должен реализовывать интерфейс ServiceInterface.
	 *
	 * @return array<int, class-string<ServiceInterface>> Массив имён классов сервисов
	 */
	public static function getServices(): array {
		return array(
			Enqueue::class,                   // Подключение скриптов и стилей (фасад Core/Assets)
			AdminFooterModalsController::class, // Модалки Confirm/Alert в admin_footer
			AdminController::class,           // Административное меню
			ModulesDashboardController::class, // AJAX и локализация для Dashboard-модулей
			MediaUploadController::class,     // Проверка типов загружаемых файлов (.txt, принятый finfo за CSV)
			SubjectController::class, // Управление предметами и CPT
			MetaBoxController::class,        // Метабоксы заданий
			LearningMenuController::class,   // Меню «Обучение» (банки контента): пункты + подсветка
			BankChromeController::class,     // Шапка банков над list-table + лендинг-фолбэки
			BankListTableController::class,  // Фильтры list-table банков + статус «Незавершённая»
			BankRowActionsController::class, // «Дублировать» в строках банков + модалка черновика
			LessonMetaBoxController::class,  // Метабокс урока
			LessonController::class,         // AJAX конструктора урока
			WorkMetaBoxController::class,    // Метабокс работы
			WorkController::class,           // AJAX конструктора работы
			CourseController::class,             // AJAX конструктора курса
			CourseBuilderController::class,      // Stepik-конструктор курса (страница + AJAX)
			CourseMetaBoxController::class,      // Метабоксы страницы редактирования курса
			AssessmentMetaBoxController::class,  // Метабокс контрольной / экзамена
			ProblemsController::class,       // CPT fs_lms_problems + problem_tag + шаблон
			ContentDeletionGuard::class,     // Гейт удаления / архивации банков
			TaskCreationController::class, // Создание заданий
			TaskPageController::class,       // Frontend-страница задания
			AllTasksPageController::class,   // Frontend-страница «Все задания» (тренажёр)
			ArticleMetaBoxController::class, // Метабокс краткого описания статьи
			ArticleSlugController::class,    // Слаг статьи: article-task-{задание}-{номер}
			ArticlePageController::class,    // Frontend-страница статьи
			SubjectLandingController::class, // Разделы лендинга предмета (шорткоды страниц)
			AssessmentPageController::class, // Frontend-страница контрольной
			BoilerplateController::class,  // Типовые условия (boilerplate)
			UserController::class,
			ApplyPageController::class,
			AuthPageController::class,       // Страница входа /sign-in/ (шорткод + перехват wp-login.php)
			ProfileController::class,
			StudentGroupController::class,
			CronController::class,
			ConsentController::class,
			ApplicationController::class,
			EnrollmentController::class,
			PiiController::class,
			RecoveryController::class,
			ExpulsionController::class,
			DeletionController::class,
			ImportController::class,   // Импорт учеников из CSV
			SubjectBundleCommand::class, // WP-CLI: перенос предмета пакетом (регистрируется только под WP_CLI)
			ArticleSlugCommand::class,   // WP-CLI: пакетное переименование слагов статей
			TaskBundleMigrationCommand::class, // WP-CLI: перевод связок 19-21 на модель parent+children
			ConfigController::class,
			SettingsController::class,
			LogsController::class,
			AuthLogController::class,
			EntityAuditSubscriber::class,
			PostEntityAuditController::class,
			EnrollmentAuditSubscriber::class,
			PiiAccessSubscriber::class,
			DataChangeSubscriber::class,
			ConsentChangeSubscriber::class,
			EmailSubscriber::class,
			DeletionSubscriber::class,
			ExportServiceBootstrap::class,
			// ==== Этап 2 — программа группы ====
			ScheduleController::class,        // AJAX программы группы
			SubstitutionController::class,    // AJAX замен преподавателя (Эпик 5)
			RoomController::class,            // AJAX справочника кабинетов (Эпик 9)
			ProfileDashboardController::class,// AJAX «Главной» кабинета (Эпик 6)
			LearnerProfileController::class,  // AJAX профиля учащегося/родителя (Эпик 7)
			NotificationController::class,    // AJAX уведомлений кабинета (колокольчик)
			JournalController::class,         // AJAX журнала и посещаемости (Эпик 2)
			LessonPlayerController::class,    // пошаговый плеер урока (/lesson/?gid=&gl=)
			CoursePreviewController::class,   // preview-плеер курса (Фаза 5, D3/D4): /course-preview/?course=
			PreviewSolveController::class,    // dry-run проверка заданий/работ/контрольных в предпросмотре (#5)
			LessonProgressController::class,  // AJAX записи прогресса шага
			LearningEventSubscriber::class,   // лента событий обучения
			NotificationSubscriber::class,    // событийные продюсеры уведомлений кабинета
			// ==== Этап 3 — сдача работ ====
			SubmissionController::class,       // AJAX сдачи / проверки / журнала
			AssessmentController::class,       // AJAX попыток контрольных
			// ==== Опциональные модули (изолированы, вырезаются удалением каталога + этой строки) ====
			AdSyncModule::class,              // Inc\Modules\AdSync — синхронизация заявок с AD (флаг-гейт)
			EgeComputerModule::class,         // Inc\Modules\EgeComputer — плеер ЕГЭ (Компьютер) (флаг-гейт, T7.20)
			DaDataModule::class,              // Inc\Modules\DaData — автодополнение DaData на /lms/join (флаг-гейт)
			SmartCaptchaModule::class,        // Inc\Modules\SmartCaptcha — капча Yandex на /lms/apply (флаг-гейт)
			VideoLibraryModule::class,        // Inc\Modules\VideoLibrary — видеозаписи занятий S3 + REST (флаг-гейт)
		);
	}

	/**
	 * Запускает регистрацию всех сервисов плагина.
	 *
	 * Процесс инициализации:
	 * 1. Создаёт DI-контейнер
	 * 2. Для каждого сервиса из списка получает экземпляр через контейнер
	 * 3. Проверяет, реализует ли объект интерфейс ServiceInterface
	 * 4. Вызывает метод register() для инициализации сервиса
	 *
	 * @return void
	 */
	public static function run(): void {
		$container = new Container();
		$container->bind( ClockInterface::class, WpClock::class );
		$container->bind( LogEventDispatcherInterface::class, LogEventDispatcher::class );

		foreach ( self::getServices() as $class ) {
			$service = $container->get( $class );

			// Проверяем, что объект реализует интерфейс ServiceInterface
			if ( $service instanceof ServiceInterface ) {
				$service->register();
			}
		}

		// Синхронизация capabilities администратора при несоответствии версии.
		// Запись в БД происходит только один раз при смене FS_LMS_CAPS_VERSION.
		$capsVersion = '5.3'; // 5.3: − AuthorLmsBank (откат авторинга банка преподавателем)
		if ( get_option( OptionName::CapsVersion->value ) !== $capsVersion ) {
			$roleManager = $container->get( \Inc\Managers\Person\RoleManager::class );
			$roleManager->registerAll();
			update_option( OptionName::CapsVersion->value, $capsVersion );
		}

		// Одноразовая data-миграция recording_slot → broadcast (Этап 1), version-gated
		// собственной опцией (дешёвый option-read при уже выполненной). Здесь, а не в
		// MigrationRunner: тот вызывается только при активации, а на установках с
		// fs_lms_schema_version=1.0.0 его up() уже не запускается.
		( new BroadcastStepMigration() )->ensure();

		// MigrationRunner штатно регистрируется и запускается только при активации
		// (Activate::activate()) — установки, уже получившие fs_lms_schema_version,
		// новые версионные миграции иначе никогда не увидят. Здесь — та же регистрация,
		// но на обычной загрузке: run() накатывает только миграции с version() выше уже
		// применённой (get_option + version_compare, без реальных запросов, если накатывать
		// нечего), поэтому безопасно вызывать на каждом запросе.
		$migrationRunner = new MigrationRunner();
		$migrationRunner->register( new Migration_1_0_0() );
		$migrationRunner->register( new Migration_1_1_0() );
		$migrationRunner->run();

		// Раздел «Учебник»: /{key}/textbook/ → /{key}/articles/ (ключ опции,
		// слаг страницы, тег шорткода). Гейт — собственная опция миграции.
		( new ArticlesSectionMigration() )->ensure();

		// Уникальный ключ (attempt_id, task_id) на ответах попытки + вычистка
		// дублей, накопленных до атомарного upsert() (см. класс миграции).
		( new AssessmentAnswerUniqueMigration() )->ensure();

		// Служебные страницы маршрутизации (/apply/, /profile/, /lesson/,
		// /course-preview/) создаются только при активации — на установках,
		// где страница добавилась в код позже или была случайно удалена,
		// без этого маршрут молча даёт 404 (см. докблок класса миграции).
		// На 'init', не сразу: ensure() умеет создавать страницы (wp_insert_post()),
		// а это в цепочке вызывает get_permalink() → нужен $wp_rewrite. Init::run()
		// выполняется при подключении плагина (wp-settings.php, до 'plugins_loaded'),
		// а $wp_rewrite создаётся позже — прямой вызов здесь падает фатальной ошибкой
		// на первом запросе, где миграция ещё не отмечена выполненной.
		add_action( 'init', static function (): void {
			( new RoutingPagesMigration( new PageGeneratorService() ) )->ensure();
		} );
	}
}
