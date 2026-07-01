<?php
/**
 * Шаблон страницы настроек с вкладками.
 *
 * @var string $active_tab Текущая активная вкладка
 */

// Определение активной вкладки, по умолчанию tab-1
$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'tab-1';

// Базовый массив вкладок; модули добавляют свои через фильтр fs_lms_settings_tabs.
// Поддерживается ключ 'path' (абсолютный путь к файлу) или 'file' (относительно templates/admin/).
$tabs = apply_filters(
	'fs_lms_settings_tabs',
	array(
		'tab-1' => array(
			'title' => 'Предметы',
			'file'  => '/components/tabs/settings-tabs/settings-1-subjects-manager.php',
		),
		// tab-2 «Авторизация» — вносится модулем SocialAuth через фильтр выше
		'tab-3' => array(
			'title' => 'Периоды',
			'file'  => '/components/tabs/settings-tabs/settings-3-periods.php',
		),
		'tab-rooms' => array(
			'title' => 'Кабинеты',
			'file'  => '/components/tabs/settings-tabs/settings-9-rooms.php',
		),
		'tab-4' => array(
			'title' => 'Шаблоны писем',
			'file'  => '/components/tabs/settings-tabs/settings-4-email-templates.php',
		),
		'tab-5' => array(
			'title' => 'Согласия',
			'file'  => '/components/tabs/settings-tabs/settings-5-consents.php',
		),
		'tab-6' => array(
			'title' => 'Импорт',
			'file'  => '/components/tabs/settings-tabs/settings-6-import.php',
		),
		'tab-7' => array(
			'title' => 'Конфигурация',
			'file'  => '/components/tabs/settings-tabs/settings-7-config.php',
		),
	)
);
?>

	<div class="wrap">
		<!-- Навигация по вкладкам -->
		<h2 class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_id => $tab ) : ?>
				<a href="?page=<?php echo esc_attr( $_GET['page'] ?? '' ); ?>&tab=<?php echo esc_attr( $tab_id ); ?>"
					class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $tab['title'] ); ?>
				</a>
			<?php endforeach; ?>
		</h2>

		<!-- Содержимое активной вкладки -->
		<div class="tab-content">
			<?php
			if ( isset( $tabs[ $active_tab ] ) ) {
				// Поддерживаем 'path' (абсолютный, от модуля) и 'file' (относительный, от templates/admin/)
				if ( isset( $tabs[ $active_tab ]['path'] ) ) {
					$file_path = $tabs[ $active_tab ]['path'];
				} else {
					$file_path = rtrim( plugin_dir_path( __FILE__ ), '/' ) . $tabs[ $active_tab ]['file'];
				}

				if ( file_exists( $file_path ) ) {
					include $file_path;
				} else {
					echo "<div class='notice notice-error'><p>Файл не найден: <code>" . esc_html( $file_path ) . "</code></p></div>";
				}
			}
			?>
		</div>
	</div>

<?php
$modal_path = rtrim( plugin_dir_path( __FILE__ ), '/' ) . '/components/modals/subject-modal.php';
if ( file_exists( $modal_path ) ) {
	include $modal_path;
}

$period_modal_path = rtrim( plugin_dir_path( __FILE__ ), '/' ) . '/components/modals/enrollment/academic-period-modal.php';
if ( file_exists( $period_modal_path ) ) {
	include $period_modal_path;
}

$consent_modal_path = rtrim( plugin_dir_path( __FILE__ ), '/' ) . '/components/modals/consent-definition-modal.php';
if ( file_exists( $consent_modal_path ) ) {
	include $consent_modal_path;
}

$room_modal_path = rtrim( plugin_dir_path( __FILE__ ), '/' ) . '/components/modals/enrollment/room-modal.php';
if ( file_exists( $room_modal_path ) ) {
	include $room_modal_path;
}
?>
