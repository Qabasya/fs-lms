<?php
/**
 * Проверка сервисов этапа 2 модуля Masterclass на настоящей MariaDB (dev-стенд).
 *
 * Запуск (внутри контейнера WordPress, код плагина смонтирован томом):
 *   docker exec wp_app php /var/www/html/wp-content/plugins/fs-lms/tests/stand/masterclass-stage2-check.php
 *
 * Что проверяет (всё на настоящих таблицах dev-стека — группы, занятия, физлица, учётки, записи, таблицы модуля):
 *  - `MasterclassService::create()` оставляет ровно группу + занятие + строку; при сбое вставки строки — ничего (откат);
 *  - `createChunk()`: номера аккаунтов = id строк, логины `student-N`, физлицо «Ученик#N», временная запись в служебной группе;
 *    время порции из 25 аккаунтов; параллельные порции двух процессов — без дублей и «зависших» строк;
 *  - флаеры, список аккаунтов (50 на страницу), снятие блокировок, удаление аккаунта, запрос удаления мастер-класса;
 *  - служебная группа (Ш4) читается резолвером ядра.
 * Все созданные данные удаляются в конце по точным ID (учётки `student-N`, физлица, записи, группы, занятия, строки модуля).
 * Только dev: скрипт пишет в живую БД стека. Печатает PASS/FAIL, код выхода 1 — если есть FAIL.
 *
 * Внутренний режим `worker <id> <count>` — параллельный процесс, создающий порцию аккаунтов.
 */

declare( strict_types=1 );

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Controllers\Deletion\DeletionController;
use Inc\Core\Container;
use Inc\Modules\Masterclass\DTO\MasterclassInputDTO;
use Inc\Modules\Masterclass\Enums\AccountDeleteReason;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Modules\Masterclass\Schema\MasterclassSchema;
use Inc\Modules\Masterclass\Services\MasterclassAccountService;
use Inc\Modules\Masterclass\Services\MasterclassService;
use Inc\Services\Deletion\DeleteGroupEvent;
use Inc\Services\Deletion\DeleteStudentEvent;
use Inc\Services\Deletion\DeletionEventDispatcher;
use Inc\Services\Group\ServiceGroupResolver;
use Inc\Services\Shared\WpClock;

require dirname( __DIR__, 5 ) . '/wp-load.php';

global $wpdb;

$container = new Container();
$container->bind( ClockInterface::class, WpClock::class );
$container->bind( LogEventDispatcherInterface::class, \Inc\Services\Log\LogEventDispatcher::class );
// Слушатели удаления ставит контроллер удаления; без него `DeleteStudentEvent` ничего не делает.
$container->get( DeletionController::class )->register();

/** @var MasterclassAccountService $accounts */
$accounts = $container->get( MasterclassAccountService::class );

// ── Режим параллельного процесса ──────────────────────────────────────────────────────────────────────────────────
if ( 'worker' === ( $argv[1] ?? '' ) ) {
	echo (int) $accounts->createChunk( (int) $argv[2], (int) $argv[3], 1 ) . "\n";
	exit( 0 );
}

$failures = 0;

function ok( string $name, bool $condition, string $detail = '' ): void {
	global $failures;
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $name . ( ! $condition && '' !== $detail ? ' — ' . $detail : '' ) . "\n";
	if ( ! $condition ) {
		++$failures;
	}
}

/** @return int Число строк таблицы (по имени без префикса). */
function rows( string $table, string $where = '1=1' ): int {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}{$table}` WHERE {$where}" );
}

/** @var MasterclassService $service */
$service       = $container->get( MasterclassService::class );
$masterclasses = $container->get( MasterclassRepository::class );
$accountRows   = $container->get( MasterclassAccountRepository::class );
$dispatcher    = $container->get( DeletionEventDispatcher::class );

$created = array(); // masterclass id => group id (для уборки)

$pickLesson = static function () use ( $service ): ?int {
	foreach ( $service->lessonOptions( 'inf_oge' ) as $option ) {
		if ( array() === $option['problems'] ) {
			return $option['id'];
		}
	}
	return null;
};

$teachers = $service->teacherOptions();
$lessonId = $pickLesson();
if ( array() === $teachers || null === $lessonId ) {
	echo "FAIL предпосылки стенда: нужен хотя бы один преподаватель и один пригодный урок предмета inf_oge\n";
	exit( 1 );
}

$input = static fn ( string $school ): MasterclassInputDTO => new MasterclassInputDTO(
	$school,
	'inf_oge',
	$lessonId,
	$teachers[0]['id'],
	gmdate( 'Y-m-d', time() + 86400 ) . ' 09:00:00',
	gmdate( 'Y-m-d', time() + 86400 ) . ' 14:00:00'
);

$cleanup = static function () use ( &$created, $wpdb, $dispatcher, $accountRows ): void {
	foreach ( $created as $masterclassId => $groupId ) {
		foreach ( $accountRows->listByMasterclass( $masterclassId ) as $account ) {
			if ( null !== $account->personId ) {
				$dispatcher->dispatch( new DeleteStudentEvent( $account->personId, 1 ) );
			}
		}
		if ( $groupId > 0 ) {
			$dispatcher->dispatch( new DeleteGroupEvent( $groupId, 1 ) );
		}
		$wpdb->delete( MasterclassSchema::accounts(), array( 'masterclass_id' => $masterclassId ) );
		$wpdb->delete( MasterclassSchema::log(), array( 'masterclass_id' => $masterclassId ) );
		$wpdb->delete( MasterclassSchema::masterclasses(), array( 'id' => $masterclassId ) );
	}
	$created = array();
};

try {
	$groupsBefore  = rows( 'fs_lms_groups' );
	$lessonsBefore = rows( 'fs_lms_group_lessons' );
	$usersBefore   = rows( 'users', "user_login LIKE 'student-%'" );

	// ── A. create(): ровно группа + занятие + строка ─────────────────────────────────────────────────────────────
	$main = $service->create( $input( 'zz_mk Школа А' ), 1 );
	$created[ $main->id ] = (int) $main->groupId;

	ok( 'create: добавилась одна группа', rows( 'fs_lms_groups' ) === $groupsBefore + 1 );
	ok( 'create: добавилось одно занятие', rows( 'fs_lms_group_lessons' ) === $lessonsBefore + 1 );
	ok( 'create: строка мастер-класса ссылается на группу и занятие', null !== $main->groupId && null !== $main->groupLessonId );

	$group = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fs_lms_groups WHERE id = %d", $main->groupId ) );
	ok( 'create: группа открытая, без расписания и без курса', 'open' === $group->access_mode && '[]' === $group->meetings && empty( $group->course_id ) );
	ok( 'create: имя группы «Мастер-класс · школа · дата»', str_starts_with( (string) $group->name, 'Мастер-класс · zz_mk Школа А · ' ) );

	$lesson = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fs_lms_group_lessons WHERE id = %d", $main->groupLessonId ) );
	ok( 'create: занятие открыто, с расписанием и без opened_at', 'open' === $lesson->visibility && null === $lesson->opened_at && null !== $lesson->scheduled_at && null !== $lesson->ends_at );
	ok( 'create: сроки считаются от конца окна', $main->freezeAt > $main->endsAt && $main->unusedPurgeAt > $main->endsAt && $main->purgeAt > $main->freezeAt );
	ok( 'Ш4: группа читается как служебная', ( new ServiceGroupResolver() )->isService( (int) $main->groupId ) );

	// ── B. create(): откат при сбое вставки строки ───────────────────────────────────────────────────────────────
	$groupsMid  = rows( 'fs_lms_groups' );
	$lessonsMid = rows( 'fs_lms_group_lessons' );
	$rowsMid    = rows( 'fs_lms_masterclasses' );
	$breaker    = static fn ( string $sql ): string => str_contains( $sql, 'INSERT INTO `' . MasterclassSchema::masterclasses() . '`' ) ? 'INSERT INTO `zz_mk_no_such_table` VALUES (1)' : $sql;
	add_filter( 'query', $breaker );
	$previous = $wpdb->suppress_errors( true );
	$failedCleanly = false;
	try {
		$service->create( $input( 'zz_mk Школа Сбой' ), 1 );
	} catch ( \Throwable $e ) {
		$failedCleanly = true;
	}
	$wpdb->suppress_errors( $previous );
	remove_filter( 'query', $breaker );

	ok( 'create: сбой вставки строки бросает исключение', $failedCleanly );
	ok( 'create: после сбоя группа не осталась', rows( 'fs_lms_groups' ) === $groupsMid );
	ok( 'create: после сбоя занятие не осталось', rows( 'fs_lms_group_lessons' ) === $lessonsMid );
	ok( 'create: после сбоя строки мастер-класса нет', rows( 'fs_lms_masterclasses' ) === $rowsMid );

	// ── C. createChunk(): 60 аккаунтов тремя порциями, время порции ──────────────────────────────────────────────
	$timings = array();
	foreach ( array( 25, 25, 10 ) as $chunk ) {
		$t0        = microtime( true );
		$made      = $accounts->createChunk( $main->id, $chunk, 1 );
		$timings[] = round( microtime( true ) - $t0, 2 );
		ok( "createChunk($chunk): создано $chunk", $chunk === $made );
	}
	echo 'ЗАМЕР порций (25, 25, 10), сек: ' . implode( ', ', $timings ) . "\n";

	ok( 'createChunk: 60 живых аккаунтов в строках модуля', 60 === rows( 'fs_lms_masterclass_accounts', "masterclass_id = {$main->id} AND deleted_at IS NULL AND user_id IS NOT NULL" ) );
	ok( 'createChunk: нет «зависших» строк без учётки', 0 === rows( 'fs_lms_masterclass_accounts', "masterclass_id = {$main->id} AND deleted_at IS NULL AND user_id IS NULL" ) );

	$sample = $accountRows->listByMasterclass( $main->id )[0];
	$user   = get_userdata( (int) $sample->userId );
	ok( 'аккаунт: логин student-N, где N — id строки', $user && 'student-' . $sample->id === $user->user_login );
	ok( 'аккаунт: имя учётки «Ученик#N»', $user && 'Ученик#' . $sample->id === $user->display_name );
	$record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fs_lms_student_records WHERE id = %d", $sample->studentRecordId ) );
	ok( 'аккаунт: запись временная, активная, в группе мастер-класса, без родителя', $record && 1 === (int) $record->is_trial && 'active' === $record->status && (int) $record->group_id === (int) $main->groupId && 0 === (int) $record->parent_person_id );
	$person = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fs_lms_persons WHERE id = %d", $sample->personId ) );
	ok( 'аккаунт: физлицо «Ученик#N» — ученик', $person && 'Ученик#' . $sample->id === $person->last_name && 1 === (int) $person->is_student );

	// ── D. Флаеры, список страницы, блокировки ───────────────────────────────────────────────────────────────────
	$flyers = $accounts->flyers( $main->id, 1 );
	ok( 'флаеры: по одному на каждый из 60 аккаунтов', 60 === count( $flyers ) );
	ok( 'флаеры: пароль читается и подходит к учётке', '' !== $flyers[0]->password && wp_check_password( $flyers[0]->password, get_user_by( 'login', $flyers[0]->login )->user_pass ) );
	ok( 'флаеры: логин совпадает с номером', 'student-' . $flyers[0]->number === $flyers[0]->login );

	$page1 = $accounts->page( $main->id, 1, 50 );
	$page2 = $accounts->page( $main->id, 2, 50 );
	ok( 'список: 50 строк на первой странице и 10 на второй, всего 60', 50 === count( $page1['rows'] ) && 10 === count( $page2['rows'] ) && 60 === $page1['total'] );
	ok( 'список: в строках нет паролей', ! preg_match( '/pass|pwd/i', (string) json_encode( $page1['rows'] ) ) );

	ok( 'блокировки: снято у всех 60 живых', 60 === $accounts->unlockLogins( $main->id, 1 ) );

	// ── E. Удаление аккаунта ─────────────────────────────────────────────────────────────────────────────────────
	$victim = $accountRows->listByMasterclass( $main->id )[1];
	$accounts->deleteAccount( $victim, AccountDeleteReason::Requested, 1 );
	ok( 'удаление: учётка исчезла', false === get_userdata( (int) $victim->userId ) );
	ok( 'удаление: физлицо и запись исчезли', 0 === rows( 'fs_lms_persons', "id = {$victim->personId}" ) && 0 === rows( 'fs_lms_student_records', "id = {$victim->studentRecordId}" ) );
	ok( 'удаление: строка модуля осталась с меткой и причиной', 1 === rows( 'fs_lms_masterclass_accounts', "id = {$victim->id} AND deleted_at IS NOT NULL AND delete_reason = 'requested'" ) );
	ok( 'удаление: страница по умолчанию его не показывает, с галочкой — показывает', 59 === $accounts->page( $main->id, 1, 50 )['total'] && 60 === $accounts->page( $main->id, 1, 50, true )['total'] );
	ok( 'удаление: повтор безвреден', null === $accounts->deleteAccount( $victim, AccountDeleteReason::Requested, 1 ) );

	// ── F. Параллельные порции двух процессов ────────────────────────────────────────────────────────────────────
	$second = $service->create( $input( 'zz_mk Школа Б' ), 1 );
	$created[ $second->id ] = (int) $second->groupId;

	$script  = escapeshellarg( __FILE__ );
	$cmd     = 'php ' . $script . ' worker ' . (int) $second->id . ' 10 2>&1';
	$proc    = array();
	$pipes   = array();
	foreach ( array( 0, 1 ) as $i ) {
		$proc[ $i ] = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ) ), $pipes[ $i ] );
	}
	$outputs = array();
	foreach ( array( 0, 1 ) as $i ) {
		$outputs[ $i ] = trim( (string) stream_get_contents( $pipes[ $i ][1] ) );
		fclose( $pipes[ $i ][1] );
		proc_close( $proc[ $i ] );
	}
	ok( 'параллельно: оба процесса вернули по 10', array( '10', '10' ) === $outputs, implode( ' | ', $outputs ) );
	ok( 'параллельно: 20 живых аккаунтов, номера уникальны', 20 === rows( 'fs_lms_masterclass_accounts', "masterclass_id = {$second->id} AND deleted_at IS NULL AND user_id IS NOT NULL" ) );
	ok( 'параллельно: нет лишних и зависших строк', 20 === rows( 'fs_lms_masterclass_accounts', "masterclass_id = {$second->id}" ) );
	ok( 'параллельно: учёток student-N на 20 больше, чем было, плюс 59 первого', rows( 'users', "user_login LIKE 'student-%'" ) === $usersBefore + 59 + 20 );

	// ── G. Запрос удаления мастер-класса ─────────────────────────────────────────────────────────────────────────
	$detached = $service->requestDelete( $second->id, 1 );
	ok( 'запрос удаления: метка стоит, аккаунтов с заявкой нет', 0 === $detached && null !== $masterclasses->find( $second->id )->deleteRequestedAt );
	$refused = false;
	try {
		$accounts->createChunk( $second->id, 1, 1 );
	} catch ( \InvalidArgumentException $e ) {
		$refused = true;
	}
	ok( 'запрос удаления: добавлять аккаунты в такой мастер-класс нельзя', $refused );

	// ── H. Продление ────────────────────────────────────────────────────────────────────────────────────────────
	$extended = $service->extend( $main->id, 5, 1 );
	ok( 'продление: заморозка уехала на 5 дней, остальные сроки — на ту же разницу', 432000 === strtotime( $extended->freezeAt . ' UTC' ) - strtotime( $main->freezeAt . ' UTC' )
		&& 432000 === strtotime( $extended->purgeAt . ' UTC' ) - strtotime( $main->purgeAt . ' UTC' )
		&& 432000 === strtotime( $extended->unusedPurgeAt . ' UTC' ) - strtotime( $main->unusedPurgeAt . ' UTC' ) );

	// ── I. Аудит 2.7: поиск учеников в центре печати (SQL) ───────────────────────────────────────────────────────
	$persons       = $container->get( \Inc\Repositories\WPDBRepositories\PersonRepository::class );
	$personService = $container->get( \Inc\Services\Person\PersonService::class );
	$records       = $container->get( \Inc\Repositories\WPDBRepositories\StudentRecordRepository::class );
	$realGroupId   = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}fs_lms_groups WHERE id NOT IN (" . implode( ',', array_map( 'intval', array_values( $created ) ) ) . ') ORDER BY id LIMIT 1' );
	$serviceIds    = ( new ServiceGroupResolver() )->ids();

	ok( 'поиск: без служебных групп «Ученик» находит временных', array() !== $persons->searchStudents( 'Ученик#', 20 ) );
	ok( 'поиск: со служебными группами «Ученик#» не находит никого', array() === $persons->searchStudents( 'Ученик#', 20, $serviceIds ) );

	$now    = gmdate( 'Y-m-d H:i:s' );
	$record = static fn ( int $personId, int $groupId ): \Inc\DTO\Enrollment\StudentRecordInputDTO => new \Inc\DTO\Enrollment\StudentRecordInputDTO(
		studentPersonId:   $personId,
		parentPersonId:    0,
		status:            'active',
		enrolledAt:        $now,
		createdAt:         $now,
		updatedAt:         $now,
		groupId:           $groupId,
		snapshotLastName:  'zz_mk',
		snapshotFirstName: 'Ученик',
	);
	$mixed = $personService->createOrFindBy( new \Inc\DTO\Person\PersonInputDTO( lastName: 'zz_mk_Смешанный', firstName: 'Ученик', docNumber: '' ) );
	foreach ( array( (int) $main->groupId, $realGroupId ) as $gid ) {
		$records->create( $record( $mixed, $gid ) );
	}
	$onlyService = $personService->createOrFindBy( new \Inc\DTO\Person\PersonInputDTO( lastName: 'zz_mk_Служебный', firstName: 'Ученик', docNumber: '' ) );
	$records->create( $record( $onlyService, (int) $main->groupId ) );
	$archived = $personService->createOrFindBy( new \Inc\DTO\Person\PersonInputDTO( lastName: 'zz_mk_Архивный', firstName: 'Ученик', docNumber: '' ) );

	$found = static fn ( string $q ): array => array_map( static fn ( $p ): int => $p->id, $persons->searchStudents( $q, 20, $serviceIds ) );
	ok( 'поиск: ученик и в служебной, и в обычной группе находится', in_array( $mixed, $found( 'zz_mk_Смешанный' ), true ) );
	ok( 'поиск: ученик только в служебной группе скрыт', ! in_array( $onlyService, $found( 'zz_mk_Служебный' ), true ) );
	ok( 'поиск: ученик без записей (архивный) находится', in_array( $archived, $found( 'zz_mk_Архивный' ), true ) );

	foreach ( array( $mixed, $onlyService, $archived ) as $personId ) {
		$dispatcher->dispatch( new DeleteStudentEvent( $personId, 1 ) );
	}
	ok( 'поиск: тестовые физлица убраны', 0 === rows( 'fs_lms_persons', "last_name LIKE 'zz_mk_%'" ) );
} finally {
	$cleanup();
}

// ── Уборка проверена: ничего после себя не оставили ───────────────────────────────────────────────────────────────
ok( 'уборка: групп столько же, сколько было', rows( 'fs_lms_groups' ) === $groupsBefore );
ok( 'уборка: занятий столько же, сколько было', rows( 'fs_lms_group_lessons' ) === $lessonsBefore );
ok( 'уборка: учёток student-N столько же, сколько было', rows( 'users', "user_login LIKE 'student-%'" ) === $usersBefore );
ok( 'уборка: в таблицах модуля нет строк стенда', 0 === rows( 'fs_lms_masterclasses', "school_name LIKE 'zz_mk%'" ) );

echo $failures > 0 ? "\nПРОВАЛЕНО: $failures\n" : "\nВСЕ ПРОВЕРКИ ПРОШЛИ\n";
exit( $failures > 0 ? 1 : 0 );
