<?php
/**
 * Проверка воронки мастер-класса на настоящей MariaDB (этап 6.2).
 *
 * Запуск (внутри контейнера WordPress, код плагина смонтирован томом):
 *   docker exec wp_app php /var/www/html/wp-content/plugins/fs-lms/tests/stand/masterclass-funnel-db.php
 *
 * Сценарий критерия «Готово, когда» из tasks/stage-06.md §6.2: цифры `MasterclassAccountRepository::funnel()`
 * совпадают с ручным SQL по тому же мастер-классу и не «худеют» после удаления неиспользованных аккаунтов —
 * мягко удалённые строки остаются в таблице, воронка по колонкам их продолжает считать.
 *
 * Таблицы создаются с временным префиксом `zzmkf_` и сносятся в конце: настоящие таблицы не трогаются.
 * Только dev: скрипт создаёт и удаляет таблицы.
 */

declare( strict_types=1 );

use Inc\Modules\Masterclass\Enums\AccountDeleteReason;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Modules\Masterclass\Schema\MasterclassSchema;

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

$realPrefix = $wpdb->prefix;
$tmpPrefix  = 'zzmkf_';
$tables     = array( 'fs_lms_masterclasses', 'fs_lms_masterclass_accounts', 'fs_lms_masterclass_log' );

$dropTmp = static function () use ( $wpdb, $tmpPrefix, $tables ): void {
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$tmpPrefix}{$table}`" );
	}
};

$realBefore = array();
foreach ( $tables as $table ) {
	$realBefore[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$realPrefix}{$table}`" );
}

$wpdb->prefix = $tmpPrefix;
$dropTmp();
delete_option( MasterclassSchema::VERSION_OPTION );
( new MasterclassSchema() )->ensure();

$mcs = new MasterclassRepository( $wpdb );
$acc = new MasterclassAccountRepository( $wpdb );

$masterclassId = $mcs->insert(
	array(
		'school_name'     => 'Школа воронки',
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
	)
);

$created = '2026-11-01 10:00:00';

// Пять выданных аккаунтов: полный путь, только вход, вход→дома→связался, зачисленный и будущий неиспользованный.
$full    = $acc->insertPending( $masterclassId, $created );
$logged  = $acc->insertPending( $masterclassId, $created );
$home    = $acc->insertPending( $masterclassId, $created );
$enrolled = $acc->insertPending( $masterclassId, $created );
$unused  = $acc->insertPending( $masterclassId, $created );
// Сожжённый номер без учётки — не выдача, в воронку не идёт.
$burned  = $acc->insertPending( $masterclassId, $created );

foreach ( array( $full => 990201, $logged => 990202, $home => 990203, $enrolled => 990204, $unused => 990205 ) as $id => $user ) {
	$acc->attach( $id, $user, 88500 + $id, 77500 + $id );
}
$acc->markDeleted( $burned, AccountDeleteReason::CreateFailed, '2026-11-01 10:00:05' );

$acc->markFirstLogin( $full, '2026-11-10 06:05:00' );
$acc->markHome( $full, '2026-11-10 14:00:00' );
$acc->markContact( $full, '2026-11-10 14:05:00' );
$acc->setState( $full, MasterclassAccountState::Applied, 61 );

$acc->markFirstLogin( $logged, '2026-11-10 06:06:00' );

$acc->markFirstLogin( $home, '2026-11-10 06:07:00' );
$acc->markHome( $home, '2026-11-10 15:00:00' );
$acc->markContact( $home, '2026-11-10 15:05:00' );

$acc->markFirstLogin( $enrolled, '2026-11-10 06:08:00' );
$acc->setState( $enrolled, MasterclassAccountState::Enrolled, 65 );

$acc->markFirstLogin( $unused, '2026-11-10 06:09:00' );

$expected = array(
	'created'   => 5,
	'loggedIn'  => 5,
	'atHome'    => 2,
	'contacted' => 2,
	'applied'   => 2, // Applied + Enrolled.
	'enrolled'  => 1,
);

$funnel = $acc->funnel( $masterclassId )->toArray();
ok( 'funnel() до чистки: все шаги воронки', $expected === $funnel, json_encode( $funnel ) );

// Ручной SQL по тому же мастер-классу — независимая сверка цифр страницы.
$manual = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT COUNT(*) AS created,
			COALESCE( SUM( first_login_at IS NOT NULL ), 0 ) AS logged_in,
			COALESCE( SUM( home_at IS NOT NULL ), 0 ) AS at_home,
			COALESCE( SUM( contact_at IS NOT NULL ), 0 ) AS contacted,
			COALESCE( SUM( state IN ( %s, %s ) ), 0 ) AS applied,
			COALESCE( SUM( state = %s ), 0 ) AS enrolled
		FROM {$tmpPrefix}fs_lms_masterclass_accounts
		WHERE masterclass_id = %d AND ( delete_reason IS NULL OR delete_reason <> %s )",
		MasterclassAccountState::Applied->value,
		MasterclassAccountState::Enrolled->value,
		MasterclassAccountState::Enrolled->value,
		$masterclassId,
		AccountDeleteReason::CreateFailed->value
	),
	ARRAY_A
);
$manualNormalized = array(
	'created'   => (int) $manual['created'],
	'loggedIn'  => (int) $manual['logged_in'],
	'atHome'    => (int) $manual['at_home'],
	'contacted' => (int) $manual['contacted'],
	'applied'   => (int) $manual['applied'],
	'enrolled'  => (int) $manual['enrolled'],
);
ok(
	'ручной SQL даёт те же цифры, что funnel()',
	$expected === $manualNormalized,
	json_encode( $manual )
);

// Тик удаляет неиспользованные: вошедший, но «просто посмотревший» аккаунт уходит мягким удалением.
ok( 'markDeleted() неиспользованного (входившего!) — true', true === $acc->markDeleted( $unused, AccountDeleteReason::Unused, '2026-11-18 00:00:00' ) );

$afterPurge = $acc->funnel( $masterclassId )->toArray();
ok(
	'после удаления неиспользованных «создано» и «входили» сохранились (мягко удалённые считаются)',
	5 === $afterPurge['created'] && 5 === $afterPurge['loggedIn'],
	json_encode( $afterPurge )
);
ok(
	'остальные шаги воронки после чистки прежние',
	array( 'atHome' => 2, 'contacted' => 2, 'applied' => 2, 'enrolled' => 1 ) === array_intersect_key( $afterPurge, array_flip( array( 'atHome', 'contacted', 'applied', 'enrolled' ) ) ),
	json_encode( $afterPurge )
);

// ── Уборка ───────────────────────────────────────────────────────────────────────────
$dropTmp();
foreach ( $tables as $table ) {
	ok( "временная таблица $table снесена", null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tmpPrefix . $table ) ) );
}
$wpdb->prefix = $realPrefix;
foreach ( $tables as $table ) {
	ok( "настоящая таблица $table не тронута", $realBefore[ $table ] === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$realPrefix}{$table}`" ) );
}

echo "\n" . ( 0 === $failures ? 'ВСЕ ПРОВЕРКИ ПРОШЛИ' : "ПРОВАЛЕНО: $failures" ) . "\n";
exit( 0 === $failures ? 0 : 1 );
