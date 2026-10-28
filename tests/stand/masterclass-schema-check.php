<?php
/**
 * Проверка схемы и репозиториев модуля Masterclass на настоящей MariaDB (этап 1).
 *
 * Запуск (внутри контейнера WordPress, код плагина смонтирован томом):
 *   docker exec wp_app php /var/www/html/wp-content/plugins/fs-lms/tests/stand/masterclass-schema-check.php
 *
 * Таблицы создаются с временным префиксом `zzmk_` и сносятся в конце: настоящие таблицы `wp_fs_lms_masterclass*` не
 * трогаются (скрипт сверяет число их строк до и после). Печатает PASS/FAIL по каждой проверке, код выхода 1 — если есть FAIL.
 * Только dev: скрипт создаёт и удаляет таблицы.
 */

declare( strict_types=1 );

use Inc\Modules\Masterclass\DTO\MasterclassLogInputDTO;
use Inc\Modules\Masterclass\Enums\AccountDeleteReason;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassLogRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Modules\Masterclass\Schema\MasterclassSchema;
use Inc\Repositories\WPDBRepositories\DuplicateKeyException;

require dirname( __DIR__, 5 ) . '/wp-load.php';

global $wpdb;

$failures = 0;

function ok( string $name, bool $condition, string $detail = '' ): void {
	global $failures;
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $name . ( ! $condition && '' !== $detail ? ' — ' . $detail : '' ) . "\n";
	if ( ! $condition ) {
		++$failures;
	}
}

/** Бросает ли `$fn` исключение класса `$class`; ожидаемую ошибку БД (дубль ключа) wpdb в консоль не печатает. */
function throws( callable $fn, string $class ): bool {
	global $wpdb;
	$previous = $wpdb->suppress_errors( true );
	try {
		$fn();
	} catch ( \Throwable $e ) {
		return $e instanceof $class;
	} finally {
		$wpdb->suppress_errors( $previous );
	}
	return false;
}

/** @return array<string, int> Число строк настоящих таблиц модуля. */
function realCounts( string $prefix ): array {
	global $wpdb;
	$counts = array();
	foreach ( array( 'fs_lms_masterclasses', 'fs_lms_masterclass_accounts', 'fs_lms_masterclass_log' ) as $table ) {
		$counts[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$prefix}{$table}`" );
	}
	return $counts;
}

$realPrefix = $wpdb->prefix;
$tmpPrefix  = 'zzmk_';
$tables     = array( 'fs_lms_masterclasses', 'fs_lms_masterclass_accounts', 'fs_lms_masterclass_log' );

// ── A. Плагин при загрузке создал настоящие таблицы ────────────────────────────────────────────────────────────────
foreach ( $tables as $table ) {
	ok( "настоящая таблица $table создана при загрузке плагина", $realPrefix . $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $realPrefix . $table ) ) );
}
$realBefore = realCounts( $realPrefix );

// ── B. Схема на чистой базе ────────────────────────────────────────────────────────────────────────────────────────
$dropTmp = static function () use ( $wpdb, $tmpPrefix, $tables ): void {
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$tmpPrefix}{$table}`" );
	}
};

$wpdb->prefix = $tmpPrefix;
$dropTmp();
delete_option( MasterclassSchema::VERSION_OPTION );

$schema = new MasterclassSchema();
$schema->ensure();

foreach ( $tables as $table ) {
	ok( "ensure() создал $table", $tmpPrefix . $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tmpPrefix . $table ) ) );
}
ok( 'ensure() записал версию схемы', MasterclassSchema::VERSION === get_option( MasterclassSchema::VERSION_OPTION ) );

$show = static function () use ( $wpdb, $tmpPrefix, $tables ): array {
	$out = array();
	foreach ( $tables as $table ) {
		$row           = $wpdb->get_row( "SHOW CREATE TABLE `{$tmpPrefix}{$table}`", ARRAY_N );
		$out[ $table ] = preg_replace( '/AUTO_INCREMENT=\d+ /', '', (string) $row[1] );
	}
	return $out;
};

$createdOnce = $show();
delete_option( MasterclassSchema::VERSION_OPTION );
$schema->ensure();
ok( 'повторный ensure() (dbDelta) ничего не меняет — SHOW CREATE TABLE тот же', $createdOnce === $show() );

$optionBefore = get_option( MasterclassSchema::VERSION_OPTION );
$schema->ensure();
ok( 'ensure() при актуальной версии не трогает опцию', $optionBefore === get_option( MasterclassSchema::VERSION_OPTION ) );

$columnType = static function ( string $table, string $column ) use ( $wpdb, $tmpPrefix ): array {
	return (array) $wpdb->get_row(
		$wpdb->prepare(
			'SELECT COLUMN_TYPE, IS_NULLABLE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			$tmpPrefix . $table,
			$column
		),
		ARRAY_A
	);
};

$groupId = $columnType( 'fs_lms_masterclasses', 'group_id' );
ok( 'masterclasses.group_id — smallint unsigned NULL (тип groups.id)', str_starts_with( $groupId['COLUMN_TYPE'] ?? '', 'smallint' ) && str_contains( $groupId['COLUMN_TYPE'] ?? '', 'unsigned' ) && 'YES' === ( $groupId['IS_NULLABLE'] ?? '' ), json_encode( $groupId ) );

$accountId = $columnType( 'fs_lms_masterclass_accounts', 'id' );
ok( 'accounts.id — unsigned auto_increment', str_contains( $accountId['COLUMN_TYPE'] ?? '', 'unsigned' ) && str_contains( $accountId['EXTRA'] ?? '', 'auto_increment' ), json_encode( $accountId ) );

$unique = static function ( string $table, string $column ) use ( $wpdb, $tmpPrefix ): bool {
	return null !== $wpdb->get_row(
		$wpdb->prepare( "SHOW INDEX FROM `{$tmpPrefix}{$table}` WHERE Column_name = %s AND Non_unique = 0", $column )
	);
};
ok( 'UNIQUE по masterclasses.group_id', $unique( 'fs_lms_masterclasses', 'group_id' ) );
ok( 'UNIQUE по accounts.user_id', $unique( 'fs_lms_masterclass_accounts', 'user_id' ) );

// ── C. Мастер-классы ───────────────────────────────────────────────────────────────────────────────────────────────
$mcs = new MasterclassRepository( $wpdb );
$acc = new MasterclassAccountRepository( $wpdb );
$log = new MasterclassLogRepository( $wpdb );

$ids = static function ( array $dtos ): array {
	$out = array_map( static fn ( $d ): int => $d->id, $dtos );
	sort( $out );
	return $out;
};

$mc = static function ( array $over = array() ) use ( $mcs ): int {
	return $mcs->insert(
		array_merge(
			array(
				'school_name'     => 'Школа №5',
				'subject_key'     => 'inf',
				'lesson_id'       => 321,
				'group_id'        => null,
				'group_lesson_id' => null,
				'teacher_user_id' => 1,
				'starts_at'       => '2026-11-10 06:00:00',
				'ends_at'         => '2026-11-10 11:00:00',
				'freeze_at'       => '2026-11-24 11:00:00',
				'unused_purge_at' => '2026-11-17 11:00:00',
				'purge_at'        => '2026-12-24 11:00:00',
				'created_at'      => '2026-11-01 10:00:00',
				'updated_at'      => '2026-11-01 10:00:00',
			),
			$over
		)
	);
};

$a = $mc( array( 'group_id' => 64001, 'group_lesson_id' => 7001 ) );
$found = $mcs->find( $a );
ok( 'insert()/find(): поля и кириллица доехали', null !== $found && 'Школа №5' === $found->schoolName && 64001 === $found->groupId && 7001 === $found->groupLessonId && 321 === $found->lessonId, json_encode( $found ) );
ok( 'find() несуществующего — null', null === $mcs->find( 999999999 ) );
ok( 'findByGroupId()', $a === $mcs->findByGroupId( 64001 )?->id );

ok( 'второй мастер-класс на ту же группу — DuplicateKeyException', throws( static fn() => $mc( array( 'group_id' => 64001 ) ), DuplicateKeyException::class ) );
$n1 = $mc();
$n2 = $mc();
ok( 'несколько мастер-классов без группы (NULL) допустимы', $n1 > 0 && $n2 > $n1 );

ok( 'activeGroupIds(): группа есть, NULL нет', in_array( 64001, $mcs->activeGroupIds(), true ) && ! in_array( 0, $mcs->activeGroupIds(), true ) );

$mcs->markPurged( $a, '2026-12-24 11:05:00' );
$purged = $mcs->find( $a );
ok( 'markPurged(): отметка, группа и занятие сброшены', null !== $purged && $purged->isPurged() && null === $purged->groupId && null === $purged->groupLessonId && '2026-12-24 11:05:00' === $purged->purgedAt );
$mcs->markPurged( $a, '2027-01-01 00:00:00' );
ok( 'markPurged() повторно отметку не двигает', '2026-12-24 11:05:00' === $mcs->find( $a )?->purgedAt );
ok( 'activeGroupIds() после очистки без этой группы', ! in_array( 64001, $mcs->activeGroupIds(), true ) );
ok( 'номер группы после очистки можно занять снова', $mc( array( 'group_id' => 64001 ) ) > 0 );

// Заморозка
$f1 = $mc( array( 'freeze_at' => '2026-11-24 11:00:00' ) );
$f2 = $mc( array( 'freeze_at' => '2099-01-01 00:00:00' ) );
$f3 = $mc( array( 'freeze_at' => '2026-11-20 11:00:00', 'purged_at' => '2026-12-01 00:00:00' ) );
$now = '2026-11-24 12:00:00';
$due = $ids( $mcs->listDueForFreeze( $now, 1000 ) );
ok( 'listDueForFreeze(): подошедший есть, будущий и вычищенный — нет', in_array( $f1, $due, true ) && ! in_array( $f2, $due, true ) && ! in_array( $f3, $due, true ), json_encode( $due ) );
ok( 'markFrozen(): первый вызов true', true === $mcs->markFrozen( $f1, $now ) );
ok( 'markFrozen(): второй вызов false (тик не запишет заморозку дважды)', false === $mcs->markFrozen( $f1, $now ) );
ok( 'listDueForFreeze() не выбирает уже отмеченное', ! in_array( $f1, $ids( $mcs->listDueForFreeze( $now, 1000 ) ), true ) );
$mcs->update( $f1, array( 'frozen_at' => null, 'freeze_at' => '2026-12-08 11:00:00' ) );
ok( 'продление: update() сбросил frozen_at в NULL, срок сдвинут', null === $mcs->find( $f1 )?->frozenAt && '2026-12-08 11:00:00' === $mcs->find( $f1 )?->freezeAt );
ok( 'после продления до нового срока в очередь заморозки не попадает', ! in_array( $f1, $ids( $mcs->listDueForFreeze( $now, 1000 ) ), true ) );
ok( 'markFrozen() вычищенного — false', false === $mcs->markFrozen( $f3, $now ) );
ok( 'listDueForFreeze() уважает limit', 1 >= count( $mcs->listDueForFreeze( '2099-12-31 00:00:00', 1 ) ) );

// Неиспользованные
$u1 = $mc( array( 'unused_purge_at' => '2026-11-17 11:00:00' ) );
$u2 = $mc( array( 'unused_purge_at' => '2099-01-01 00:00:00' ) );
$dueUnused = $ids( $mcs->listDueForUnusedPurge( '2026-11-18 00:00:00', 1000 ) );
ok( 'listDueForUnusedPurge(): подошедший есть, будущий нет', in_array( $u1, $dueUnused, true ) && ! in_array( $u2, $dueUnused, true ) );
$mcs->markUnusedPurged( $u1, '2026-11-18 00:00:00' );
ok( 'после markUnusedPurged() мастер-класс из очереди ушёл', ! in_array( $u1, $ids( $mcs->listDueForUnusedPurge( '2026-11-18 00:00:00', 1000 ) ), true ) );

// Вычистка
$px = $mc( array( 'purge_at' => '2026-12-24 11:00:00' ) );
$py = $mc( array( 'purge_at' => '2099-01-01 00:00:00' ) );
$pz = $mc( array( 'purge_at' => '2099-01-01 00:00:00' ) );
$pw = $mc( array( 'purge_at' => '2026-12-01 00:00:00', 'purged_at' => '2026-12-02 00:00:00' ) );
$mcs->markDeleteRequested( $py, '2026-11-11 09:00:00' );
$mcs->markDeleteRequested( $py, '2026-11-30 09:00:00' );
ok( 'markDeleteRequested() повторно время запроса не двигает', '2026-11-11 09:00:00' === $mcs->find( $py )?->deleteRequestedAt );
$duePurge = $mcs->listDueForPurge( '2026-12-25 00:00:00', 1000 );
$duePurgeIds = array_map( static fn ( $d ): int => $d->id, $duePurge );
ok( 'listDueForPurge(): по сроку и по запросу — есть; нетронутый и вычищенный — нет', in_array( $px, $duePurgeIds, true ) && in_array( $py, $duePurgeIds, true ) && ! in_array( $pz, $duePurgeIds, true ) && ! in_array( $pw, $duePurgeIds, true ), json_encode( $duePurgeIds ) );
ok( 'listDueForPurge(): запрос на удаление (11.11) идёт раньше срока (24.12)', array_search( $py, $duePurgeIds, true ) < array_search( $px, $duePurgeIds, true ) );
$mcs->markDeleteRequested( $pw, '2026-12-05 00:00:00' );
ok( 'markDeleteRequested() вычищенного ничего не меняет', null === $mcs->find( $pw )?->deleteRequestedAt );

// Окно для Ш6
$w = $mc( array( 'starts_at' => '2026-12-10 06:00:00', 'ends_at' => '2026-12-10 11:00:00', 'freeze_at' => '2099-01-01 00:00:00', 'purge_at' => '2099-01-01 00:00:00' ) );
$wp = $mc( array( 'starts_at' => '2026-12-10 06:00:00', 'ends_at' => '2026-12-10 11:00:00', 'purged_at' => '2026-12-12 00:00:00' ) );
ok( 'runningFor(): за 30 минут до начала при запасе 60 — идёт', in_array( $w, $ids( $mcs->runningFor( '2026-12-10 05:30:00', 60 ) ), true ) );
ok( 'runningFor(): за 30 минут до начала при запасе 15 — ещё нет', ! in_array( $w, $ids( $mcs->runningFor( '2026-12-10 05:30:00', 15 ) ), true ) );
ok( 'runningFor(): в момент конца — идёт', in_array( $w, $ids( $mcs->runningFor( '2026-12-10 11:00:00', 0 ) ), true ) );
ok( 'runningFor(): через секунду после конца — нет', ! in_array( $w, $ids( $mcs->runningFor( '2026-12-10 11:00:01', 60 ) ), true ) );
ok( 'runningFor(): вычищенный не выбирается', ! in_array( $wp, $ids( $mcs->runningFor( '2026-12-10 08:00:00', 60 ) ), true ) );

$page = $mcs->page( 1, 3 );
ok( 'page(): порция и общее число', 3 === count( $page['rows'] ) && $page['total'] >= 10, json_encode( array( count( $page['rows'] ), $page['total'] ) ) );
ok( 'page(): новые сверху (starts_at DESC)', $page['rows'][0]->startsAt >= $page['rows'][2]->startsAt );

// ── D. Аккаунты ────────────────────────────────────────────────────────────────────────────────────────────────────
$m = $mc();
$created = '2026-11-01 10:00:00';

$p1 = $acc->insertPending( $m, $created );
$p2 = $acc->insertPending( $m, $created );
$p3 = $acc->insertPending( $m, $created );
ok( 'insertPending(): номера возрастают', $p1 > 0 && $p2 > $p1 && $p3 > $p2, json_encode( array( $p1, $p2, $p3 ) ) );
ok( 'новая строка — Active без учётки', MasterclassAccountState::Active === $acc->find( $p1 )?->state && null === $acc->find( $p1 )?->userId );

ok( 'markDeleted(): первый вызов true', true === $acc->markDeleted( $p2, AccountDeleteReason::CreateFailed, '2026-11-01 10:00:01' ) );
ok( 'markDeleted(): второй вызов false', false === $acc->markDeleted( $p2, AccountDeleteReason::Unused, '2026-11-01 10:00:02' ) );
ok( 'причина первого удаления не перезаписана', AccountDeleteReason::CreateFailed === $acc->find( $p2 )?->deleteReason );
$p4 = $acc->insertPending( $m, $created );
ok( 'номер удалённого аккаунта не повторяется', $p4 > $p3 && $p4 !== $p2 );
ok( 'удалённая строка осталась в таблице', null !== $acc->find( $p2 ) && $acc->find( $p2 )->isDeleted() );

$acc->attach( $p1, 990001, 88001, 77001 );
$att = $acc->find( $p1 );
ok( 'attach(): учётка, физлицо и запись привязаны', 990001 === $att?->userId && 88001 === $att->personId && 77001 === $att->studentRecordId );
ok( 'findByUserId()/findByPersonId()', $p1 === $acc->findByUserId( 990001 )?->id && $p1 === $acc->findByPersonId( 88001 )?->id );
ok( 'второй аккаунт на того же пользователя — DuplicateKeyException', throws( static fn() => $acc->attach( $p3, 990001, 88002, 77002 ), DuplicateKeyException::class ) );

$acc->attach( $p3, 990003, 88003, 77003 );
$acc->attach( $p4, 990004, 88004, 77004 );

ok( 'markFirstLogin(): первый вызов true, второй false', true === $acc->markFirstLogin( $p1, '2026-11-10 06:05:00' ) && false === $acc->markFirstLogin( $p1, '2026-11-10 06:06:00' ) );
ok( 'время первого входа — первое', '2026-11-10 06:05:00' === $acc->find( $p1 )?->firstLoginAt );
ok( 'markHome()/markContact(): по разу', true === $acc->markHome( $p1, '2026-11-10 14:00:00' ) && false === $acc->markHome( $p1, '2026-11-10 15:00:00' ) && true === $acc->markContact( $p1, '2026-11-10 14:05:00' ) && false === $acc->markContact( $p1, '2026-11-10 14:06:00' ) );
ok( 'отметки удалённого аккаунта не ставятся', false === $acc->markFirstLogin( $p2, '2026-11-10 06:05:00' ) && false === $acc->markHome( $p2, '2026-11-10 06:05:00' ) && false === $acc->markContact( $p2, '2026-11-10 06:05:00' ) );

ok( 'liveUserIds(): без удалённых и без пустых учёток', array( 990001, 990003, 990004 ) === $acc->liveUserIds( $m ), json_encode( $acc->liveUserIds( $m ) ) );

$acc->setState( $p1, MasterclassAccountState::Applied, 44 );
ok( 'setState() с заявкой', MasterclassAccountState::Applied === $acc->find( $p1 )?->state && 44 === $acc->find( $p1 )?->applicationId );
$acc->setState( $p1, MasterclassAccountState::Enrolled );
ok( 'setState() без заявки номер заявки не стирает', MasterclassAccountState::Enrolled === $acc->find( $p1 )?->state && 44 === $acc->find( $p1 )?->applicationId );

ok( 'nextUnused(): только Active без входа и не удалённые, по возрастанию', array( $p3, $p4 ) === array_map( static fn ( $d ): int => $d->id, $acc->nextUnused( $m, 10 ) ) );
ok( 'nextUnused() уважает limit', 1 === count( $acc->nextUnused( $m, 1 ) ) );
$acc->markFirstLogin( $p3, '2026-11-10 06:10:00' );
ok( 'вошедший из nextUnused() ушёл', array( $p4 ) === array_map( static fn ( $d ): int => $d->id, $acc->nextUnused( $m, 10 ) ) );

ok( 'nextPurgeable(): не удалённые и не зачисленные', array( $p3, $p4 ) === array_map( static fn ( $d ): int => $d->id, $acc->nextPurgeable( $m, 10 ) ) );
ok( 'countLive() = 2, countByMasterclass() = 4 (с удалённым и зачисленным)', 2 === $acc->countLive( $m ) && 4 === $acc->countByMasterclass( $m ) );

ok( 'markDeletedByUserId(): возвращает номер', $p4 === $acc->markDeletedByUserId( 990004, AccountDeleteReason::UserDeleted, '2026-11-20 10:00:00' ) );
ok( 'markDeletedByUserId(): повторно и для чужого — null', null === $acc->markDeletedByUserId( 990004, AccountDeleteReason::UserDeleted, '2026-11-20 10:00:01' ) && null === $acc->markDeletedByUserId( 999999999, AccountDeleteReason::UserDeleted, '2026-11-20 10:00:01' ) );
ok( 'user_id удалённого аккаунта сохранён (для журнала)', 990004 === $acc->find( $p4 )?->userId );

$acc->clearStudentRecord( $p1 );
ok( 'clearStudentRecord(): запись отвязана, учётка осталась', null === $acc->find( $p1 )?->studentRecordId && 990001 === $acc->find( $p1 )?->userId );

// Зависшие номера
$m2     = $mc();
$old    = $acc->insertPending( $m2, '2026-10-01 00:00:00' );
$fresh  = $acc->insertPending( $m2, '2026-11-01 00:00:00' );
$bound  = $acc->insertPending( $m2, '2026-10-01 00:00:00' );
$gone   = $acc->insertPending( $m2, '2026-10-01 00:00:00' );
$acc->attach( $bound, 990010, 88010, 77010 );
$acc->markDeleted( $gone, AccountDeleteReason::CreateFailed, '2026-10-01 00:05:00' );
$stale = array_map( static fn ( $d ): int => $d->id, $acc->listStalePending( '2026-10-15 00:00:00', 100 ) );
ok( 'listStalePending(): старый без учётки есть; свежий, привязанный и удалённый — нет', in_array( $old, $stale, true ) && ! in_array( $fresh, $stale, true ) && ! in_array( $bound, $stale, true ) && ! in_array( $gone, $stale, true ), json_encode( $stale ) );

// Воронка
$m3 = $mc();
$f_a = $acc->insertPending( $m3, $created );
$f_b = $acc->insertPending( $m3, $created );
$f_c = $acc->insertPending( $m3, $created );
$f_d = $acc->insertPending( $m3, $created );
$f_e = $acc->insertPending( $m3, $created );
foreach ( array( $f_a => 990101, $f_b => 990102, $f_c => 990103, $f_e => 990105 ) as $id => $user ) {
	$acc->attach( $id, $user, 88100 + $id, 77100 + $id );
}
$acc->markFirstLogin( $f_a, '2026-11-10 06:05:00' );
$acc->markHome( $f_a, '2026-11-10 14:00:00' );
$acc->markContact( $f_a, '2026-11-10 14:05:00' );
$acc->setState( $f_a, MasterclassAccountState::Applied, 51 );
$acc->markFirstLogin( $f_b, '2026-11-10 06:06:00' );
$acc->setState( $f_b, MasterclassAccountState::Enrolled, 52 );
$acc->markDeleted( $f_c, AccountDeleteReason::Unused, '2026-11-18 00:00:00' );
$acc->markDeleted( $f_d, AccountDeleteReason::CreateFailed, '2026-11-01 10:00:05' );
$acc->markFirstLogin( $f_e, '2026-11-10 06:07:00' );
$funnel = $acc->funnel( $m3 )->toArray();
ok(
	'funnel(): создано 4 (удалённый неиспользованный считается, несозданная учётка — нет), вошли 3, дома 1, связались 1, заявки 2, зачислен 1',
	array( 'created' => 4, 'loggedIn' => 3, 'atHome' => 1, 'contacted' => 1, 'applied' => 2, 'enrolled' => 1 ) === $funnel,
	json_encode( $funnel )
);
ok( 'funnel() пустого мастер-класса — нули', array_sum( $acc->funnel( $mc() )->toArray() ) === 0 );

// Страницы
$m4  = $mc();
$all = array();
for ( $i = 0; $i < 7; $i++ ) {
	$all[] = $acc->insertPending( $m4, $created );
}
$acc->markDeleted( $all[1], AccountDeleteReason::Unused, '2026-11-18 00:00:00' );
$acc->markDeleted( $all[4], AccountDeleteReason::Unused, '2026-11-18 00:00:00' );
$live = $acc->pageByMasterclass( $m4, 1, 3, false );
ok( 'pageByMasterclass() без удалённых: всего 5, первая страница 3 по возрастанию', 5 === $live['total'] && array( $all[0], $all[2], $all[3] ) === array_map( static fn ( $d ): int => $d->id, $live['rows'] ) );
$last = $acc->pageByMasterclass( $m4, 2, 3, false );
ok( 'pageByMasterclass() вторая страница — остаток', array( $all[5], $all[6] ) === array_map( static fn ( $d ): int => $d->id, $last['rows'] ) );
$withDeleted = $acc->pageByMasterclass( $m4, 1, 50, true );
ok( 'pageByMasterclass() с удалёнными: всего 7', 7 === $withDeleted['total'] && 7 === count( $withDeleted['rows'] ) );
ok( 'listByMasterclass(): все 7 по возрастанию', $all === array_map( static fn ( $d ): int => $d->id, $acc->listByMasterclass( $m4 ) ) );

// ── E. Журнал ──────────────────────────────────────────────────────────────────────────────────────────────────────
$entry = static fn ( MasterclassLogAction $action, ?int $mcId, ?int $accId, string $when, array $context = array() ): MasterclassLogInputDTO => new MasterclassLogInputDTO(
	action:        $action,
	result:        'success',
	actorType:     'system',
	actorUserId:   null,
	actorLabel:    'Тик',
	masterclassId: $mcId,
	accountId:     $accId,
	subjectLabel:  null !== $accId ? 'Ученик#' . $accId : null,
	actorIp:       '',
	context:       $context,
	createdAt:     $when
);

$l1 = $log->create( $entry( MasterclassLogAction::Frozen, 501, null, '2025-01-01 10:00:00', array( 'причина' => 'срок' ) ) );
$l2 = $log->create( $entry( MasterclassLogAction::LoginAtHome, 501, 15, '2026-11-11 15:00:00' ) );
$l3 = $log->create( $entry( MasterclassLogAction::LoginAtHome, 502, 16, '2026-11-12 15:00:00' ) );
ok( 'create(): возрастающие id', $l1 > 0 && $l2 > $l1 && $l3 > $l2 );

$all501 = $log->list( array( 'masterclass_id' => 501 ), 1, 50 );
ok( 'list(): фильтр по мастер-классу', 2 === $all501['total'] && array( $l2, $l1 ) === array_map( static fn ( $d ): int => $d->id, $all501['rows'] ), json_encode( $all501['total'] ) );
ok( 'list(): контекст с кириллицей читается обратно', array( 'причина' => 'срок' ) === $log->list( array( 'action' => 'frozen', 'masterclass_id' => 501 ), 1, 10 )['rows'][0]->context );
ok( 'list(): фильтры по действию и аккаунту', 1 === $log->list( array( 'action' => 'login_at_home', 'account_id' => 16 ), 1, 10 )['total'] );
ok( 'list(): диапазон дат включительно', 2 === $log->list( array( 'masterclass_id' => 501, 'date_from' => '2025-01-01', 'date_to' => '2026-11-11' ), 1, 10 )['total'] && 1 === $log->list( array( 'date_from' => '2026-11-12', 'date_to' => '2026-11-12' ), 1, 10 )['total'] );
ok( 'list(): постраничность', 1 === count( $log->list( array(), 2, 1 )['rows'] ) && 3 === $log->list( array(), 1, 1 )['total'] );
ok( 'distinctActions()', array( 'frozen', 'login_at_home' ) === $log->distinctActions() );
ok( 'purgeOlderThan(): удаляет только старое', 1 === $log->purgeOlderThan( '2026-01-01 00:00:00' ) && 2 === $log->list( array(), 1, 10 )['total'] );

// ── F. Уборка ──────────────────────────────────────────────────────────────────────────────────────────────────────
$dropTmp();
foreach ( $tables as $table ) {
	ok( "временная таблица $table снесена", null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tmpPrefix . $table ) ) );
}
$wpdb->prefix = $realPrefix;
ok( 'настоящие таблицы модуля не тронуты (число строк прежнее)', $realBefore === realCounts( $realPrefix ), json_encode( array( $realBefore, realCounts( $realPrefix ) ) ) );
ok( 'версия схемы осталась актуальной', MasterclassSchema::VERSION === get_option( MasterclassSchema::VERSION_OPTION ) );

echo "\n" . ( 0 === $failures ? 'ВСЕ ПРОВЕРКИ ПРОШЛИ' : "ПРОВАЛЕНО: $failures" ) . "\n";
exit( 0 === $failures ? 0 : 1 );
