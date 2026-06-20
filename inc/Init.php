<?php

namespace Inc;

use Inc\Contracts\ServiceInterface;
use Inc\Modules\AdSync\AdSyncModule;
use Inc\Controllers\Enrollment\ApplicationController;
use Inc\Controllers\Pages\ApplyPageController;
use Inc\Controllers\Person\ConsentController;
use Inc\Controllers\System\CronController;
use Inc\Controllers\System\AdminController;
use Inc\Controllers\Auth\AuthController;
use Inc\Controllers\Pages\AuthPageController;
use Inc\Controllers\Subject\BoilerplateController;
use Inc\Controllers\Enrollment\EnrollmentController;
use Inc\Controllers\Course\LessonController;
use Inc\Controllers\Course\LessonMetaBoxController;
use Inc\Controllers\Course\WorkController;
use Inc\Controllers\Course\WorkMetaBoxController;
use Inc\Controllers\Course\CourseBuilderController;
use Inc\Controllers\Course\CourseController;
use Inc\Controllers\Assessment\AssessmentMetaBoxController;
use Inc\Controllers\Course\LearningMenuController;
use Inc\Controllers\Subject\ContentDeletionGuard;
use Inc\Controllers\Problems\ProblemsController;
use Inc\Controllers\Subject\MetaBoxController;
use Inc\Controllers\Person\PiiController;
use Inc\Controllers\Person\ProfileController;
use Inc\Controllers\Enrollment\ExpulsionController;
use Inc\Controllers\Enrollment\RecoveryController;
use Inc\Controllers\Group\StudentGroupController;
use Inc\Controllers\Subject\SubjectController;
use Inc\Controllers\Subject\TaskCreationController;
use Inc\Controllers\Pages\AssessmentPageController;
use Inc\Controllers\Pages\TaskPageController;
use Inc\Controllers\System\LogsController;
use Inc\Controllers\Settings\ConfigController;
use Inc\Controllers\Settings\SettingsController;
use Inc\Controllers\Subscribers\AuthLogController;
use Inc\Controllers\Subscribers\EntityAuditSubscriber;
use Inc\Controllers\Subscribers\PostEntityAuditController;
use Inc\Controllers\Subscribers\EnrollmentAuditSubscriber;
use Inc\Controllers\Subscribers\PiiAccessSubscriber;
use Inc\Controllers\Subscribers\DataChangeSubscriber;
use Inc\Controllers\Subscribers\ConsentChangeSubscriber;
use Inc\Controllers\Subscribers\EmailSubscriber;
use Inc\Controllers\Subscribers\DeletionSubscriber;
use Inc\Controllers\Subscribers\LearningEventSubscriber;
use Inc\Controllers\Deletion\DeletionController;
use Inc\Controllers\Assessment\AssessmentController;
use Inc\Controllers\Group\ScheduleController;
use Inc\Controllers\Group\GroupCockpitController;
use Inc\Controllers\Course\LessonPlayerController;
use Inc\Controllers\Course\LessonProgressController;
use Inc\Controllers\Course\SubmissionController;
use Inc\Controllers\System\ImportController;
use Inc\Controllers\Person\UserController;
use Inc\Services\Export\ExportServiceBootstrap;
use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Core\Container;
use Inc\Core\Enqueue;
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
			Enqueue::class,           // Подключение скриптов и стилей
			AdminController::class,   // Административное меню
			SubjectController::class, // Управление предметами и CPT
			MetaBoxController::class,        // Метабоксы заданий
			LearningMenuController::class,   // Меню «Обучение» (банки контента)
			LessonMetaBoxController::class,  // Метабокс урока
			LessonController::class,         // AJAX конструктора урока
			WorkMetaBoxController::class,    // Метабокс работы
			WorkController::class,           // AJAX конструктора работы
			CourseController::class,             // AJAX конструктора курса
			CourseBuilderController::class,      // Stepik-конструктор курса (страница + AJAX)
			AssessmentMetaBoxController::class,  // Метабокс контрольной / экзамена
			ProblemsController::class,       // CPT fs_lms_problems + problem_tag + шаблон
			ContentDeletionGuard::class,     // Гейт удаления / архивации банков
			TaskCreationController::class, // Создание заданий
			TaskPageController::class,       // Frontend-страница задания
			AssessmentPageController::class, // Frontend-страница контрольной
			BoilerplateController::class,  // Типовые условия (boilerplate)
			UserController::class,
			AuthController::class,
			AuthPageController::class,
			ApplyPageController::class,
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
			LessonPlayerController::class,    // пошаговый плеер урока (до кокпита: ?gl=)
			GroupCockpitController::class,    // фронт-страница кокпита (/group/)
			LessonProgressController::class,  // AJAX записи прогресса шага
			LearningEventSubscriber::class,   // лента событий обучения
			// ==== Этап 3 — сдача работ ====
			SubmissionController::class,       // AJAX сдачи / проверки / журнала
			AssessmentController::class,       // AJAX попыток контрольных
			// ==== Опциональные модули (изолированы, вырезаются удалением каталога + этой строки) ====
			AdSyncModule::class,              // Inc\Modules\AdSync — синхронизация заявок с AD (флаг-гейт)
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
		$capsVersion = '1.2';
		if ( get_option( 'fs_lms_caps_version' ) !== $capsVersion ) {
			$roleManager = $container->get( \Inc\Managers\Person\RoleManager::class );
			$roleManager->syncCapabilities();
			update_option( 'fs_lms_caps_version', $capsVersion );
		}
	}
}
