<?php
/**
 * Замер экранов состава группы на мастер-классе с 500 временными аккаунтами (аудит 2.7, этап 2).
 *
 * Запуск (внутри контейнера WordPress; ≈ 3–4 минуты на dev-стеке: 500 аккаунтов создаются порциями по 25):
 *   docker exec wp_app php /var/www/html/wp-content/plugins/fs-lms/tests/stand/masterclass-roster-load.php [число аккаунтов]
 *
 * Меряет время сервисов, которые строят состав группы (ростер, журнал, посещаемость, отчёт по заданиям занятия),
 * и страницы модуля (список аккаунтов, обзор). Создаёт мастер-класс «zz_mk Нагрузка» и всё за собой удаляет по точным ID.
 * Только dev: пишет в живую БД стека. Время — стенки процесса, без загрузки WordPress.
 */

declare( strict_types=1 );

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Controllers\Deletion\DeletionController;
use Inc\Core\Container;
use Inc\Modules\Masterclass\DTO\MasterclassInputDTO;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Schema\MasterclassSchema;
use Inc\Modules\Masterclass\Services\MasterclassAccountService;
use Inc\Modules\Masterclass\Services\MasterclassService;
use Inc\Services\Course\AttendanceService;
use Inc\Services\Course\JournalService;
use Inc\Services\Course\TaskAttemptReportService;
use Inc\Services\Deletion\DeleteGroupEvent;
use Inc\Services\Deletion\DeleteStudentEvent;
use Inc\Services\Deletion\DeletionEventDispatcher;
use Inc\Services\Group\GroupRosterService;
use Inc\Services\Shared\WpClock;

require dirname( __DIR__, 5 ) . '/wp-load.php';

global $wpdb;

$total = max( 25, (int) ( $argv[1] ?? 500 ) );

$container = new Container();
$container->bind( ClockInterface::class, WpClock::class );
$container->bind( LogEventDispatcherInterface::class, \Inc\Services\Log\LogEventDispatcher::class );
$container->get( DeletionController::class )->register();

$service     = $container->get( MasterclassService::class );
$accounts    = $container->get( MasterclassAccountService::class );
$accountRows = $container->get( MasterclassAccountRepository::class );
$dispatcher  = $container->get( DeletionEventDispatcher::class );

$lessonId = null;
foreach ( $service->lessonOptions( 'inf_oge' ) as $option ) {
	if ( array() === $option['problems'] ) {
		$lessonId = $option['id'];
		break;
	}
}
$teachers = $service->teacherOptions();
if ( null === $lessonId || array() === $teachers ) {
	echo "FAIL предпосылки стенда: нужен преподаватель и пригодный урок inf_oge\n";
	exit( 1 );
}

$timed = static function ( string $label, callable $fn ): void {
	$t0     = microtime( true );
	$result = $fn();
	printf( "ЗАМЕР %-46s %6.2f с  (%s)\n", $label, microtime( true ) - $t0, $result );
};

$mc = null;
try {
	$day = gmdate( 'Y-m-d', time() + 86400 );
	$mc  = $service->create( new MasterclassInputDTO( 'zz_mk Нагрузка', 'inf_oge', $lessonId, $teachers[0]['id'], "$day 09:00:00", "$day 14:00:00" ), 1 );

	$t0      = microtime( true );
	$created = 0;
	while ( $created < $total ) {
		$created += $accounts->createChunk( $mc->id, min( 25, $total - $created ), 1 );
	}
	printf( "Создано аккаунтов: %d за %.1f с\n", $created, microtime( true ) - $t0 );

	$groupId = (int) $mc->groupId;
	$lessonRowId = (int) $mc->groupLessonId;

	$timed( 'GroupRosterService::forGroup', static fn () => 'строк: ' . count( $container->get( GroupRosterService::class )->forGroup( $groupId ) ) );
	$timed( 'JournalService::forGroup', static function () use ( $container, $groupId ): string {
		$r = $container->get( JournalService::class )->forGroup( $groupId );
		return 'ключей: ' . count( $r );
	} );
	$timed( 'AttendanceService::matrixForGroup', static fn () => 'ключей: ' . count( $container->get( AttendanceService::class )->matrixForGroup( $groupId ) ) );
	$timed( 'TaskAttemptReportService::forLesson', static fn () => 'ключей: ' . count( $container->get( TaskAttemptReportService::class )->forLesson( $groupId, $lessonRowId ) ) );
	$timed( 'MasterclassAccountService::page (50 строк)', static fn () => 'строк: ' . count( $accounts->page( $mc->id, 1, 50 )['rows'] ) );
	$timed( 'MasterclassService::overview (воронка)', static fn () => 'строк: ' . count( $service->overview( 1, 20 )['rows'] ) );
	$timed( 'MasterclassAccountService::flyers', static fn () => 'флаеров: ' . count( $accounts->flyers( $mc->id, 1 ) ) );
} finally {
	if ( null !== $mc ) {
		foreach ( $accountRows->listByMasterclass( $mc->id ) as $account ) {
			if ( null !== $account->personId ) {
				$dispatcher->dispatch( new DeleteStudentEvent( $account->personId, 1 ) );
			}
		}
		if ( null !== $mc->groupId ) {
			$dispatcher->dispatch( new DeleteGroupEvent( (int) $mc->groupId, 1 ) );
		}
		$wpdb->delete( MasterclassSchema::accounts(), array( 'masterclass_id' => $mc->id ) );
		$wpdb->delete( MasterclassSchema::log(), array( 'masterclass_id' => $mc->id ) );
		$wpdb->delete( MasterclassSchema::masterclasses(), array( 'id' => $mc->id ) );
	}
}

echo 'Осталось student-%: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'student-%'" ) . "\n";
