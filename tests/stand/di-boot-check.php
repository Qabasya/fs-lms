<?php
/**
 * Загрузка DI: каждый сервис из `Init::getServices()` собирается контейнером без исключений.
 *
 * Запуск (внутри контейнера WordPress):
 *   docker exec wp_app php /var/www/html/wp-content/plugins/fs-lms/tests/stand/di-boot-check.php [on|off]
 * Аргумент `on`/`off` задаёт константу `FS_LMS_MASTERCLASS` ДО загрузки WordPress, поэтому плагин стартует с модулем
 * мастер-классов включённым или выключенным (по умолчанию — как настроено в опции). Дополнительно проверяется, что
 * зарегистрировалось в каждом режиме: страница модуля и вкладка настроек есть только при включённом, служебные группы — всегда.
 */

declare( strict_types=1 );

$mode = $argv[1] ?? '';
if ( 'on' === $mode || 'off' === $mode ) {
	define( 'FS_LMS_MASTERCLASS', 'on' === $mode );
}

require dirname( __DIR__, 5 ) . '/wp-load.php';

use Inc\Core\Container;
use Inc\Init;

$failures = 0;

function ok( string $name, bool $condition, string $detail = '' ): void {
	global $failures;
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $name . ( ! $condition && '' !== $detail ? ' — ' . $detail : '' ) . "\n";
	if ( ! $condition ) {
		++$failures;
	}
}

$container = new Container();
$container->bind( \Inc\Contracts\ClockInterface::class, \Inc\Services\Shared\WpClock::class );
$container->bind( \Inc\Contracts\LogEventDispatcherInterface::class, \Inc\Services\Log\LogEventDispatcher::class );

$resolved = 0;
foreach ( Init::getServices() as $class ) {
	try {
		$service = $container->get( $class );
		ok( "DI: $class", $service instanceof $class );
		++$resolved;
	} catch ( \Throwable $e ) {
		ok( "DI: $class", false, get_class( $e ) . ': ' . $e->getMessage() );
	}
}
echo "Собрано сервисов: $resolved\n";

$config = $container->get( \Inc\Modules\Masterclass\Config\MasterclassConfig::class );
$enabled = $config->isEnabled();
echo 'Модуль мастер-классов: ' . ( $enabled ? 'ВКЛЮЧЁН' : 'выключен' ) . "\n";

// Что реально зарегистрировалось при загрузке плагина.
ok( 'служебные группы: фильтр fs_lms_service_group_ids подписан всегда', has_filter( 'fs_lms_service_group_ids' ) !== false );
ok( 'настройки: AJAX сохранения подписан всегда', has_action( 'wp_ajax_' . \Inc\Modules\Masterclass\Controllers\MasterclassSettingsController::SAVE_ACTION ) !== false );
ok( 'настройки: карточка Dashboard есть всегда', array_search( 'masterclass', array_column( apply_filters( 'fs_lms_dashboard_modules', array() ), 'id' ), true ) !== false );
ok( 'настройки: обработчик тумблера подписан всегда', has_action( 'fs_lms_module_toggle_masterclass' ) !== false );

$tabs = apply_filters( 'fs_lms_settings_tabs', array() );
ok( 'вкладка настроек есть только при включённом модуле', $enabled === isset( $tabs['tab-masterclasses'] ), json_encode( array_keys( $tabs ) ) );

global $wp_filter;
$adminHooked = false;
foreach ( $wp_filter['admin_menu']->callbacks ?? array() as $callbacks ) {
	foreach ( $callbacks as $cb ) {
		if ( $cb['function'] instanceof \Closure ) {
			$bound = ( new \ReflectionFunction( $cb['function'] ) )->getStaticVariables();
			foreach ( $bound['subpages'] ?? array() as $subpage ) {
				if ( \Inc\Modules\Masterclass\Controllers\MasterclassAdminController::PAGE_SLUG === ( $subpage['menu_slug'] ?? '' ) ) {
					$adminHooked = true;
				}
			}
		}
	}
}
ok( 'пункт меню «Мастер-классы» есть только при включённом модуле', $enabled === $adminHooked );

ok( 'служебные группы читаются без ошибок', is_array( $container->get( \Inc\Services\Group\ServiceGroupResolver::class )->ids() ) );

echo "\n" . ( 0 === $failures ? 'ВСЕ ПРОВЕРКИ ПРОШЛИ' : "ПРОВАЛЕНО: $failures" ) . "\n";
exit( 0 === $failures ? 0 : 1 );
