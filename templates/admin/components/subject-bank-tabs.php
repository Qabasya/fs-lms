<?php

declare( strict_types=1 );

/**
 * Таб-бар предметов над таблицей заданий/статей.
 *
 * Выводится через хук admin_notices в LearningMenuController при 2+ предметах.
 * Каждая вкладка ведёт на нативную таблицу CPT соответствующего предмета.
 *
 * @var array<int, array{name: string, url: string, active: bool}> $tabs
 *
 * @package Inc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h2 class="nav-tab-wrapper">
	<?php foreach ( $tabs as $tab ) : ?>
		<a
			class="nav-tab<?php echo $tab['active'] ? ' nav-tab-active' : ''; ?>"
			href="<?php echo esc_url( $tab['url'] ); ?>"
		><?php echo esc_html( $tab['name'] ); ?></a>
	<?php endforeach; ?>
</h2>
