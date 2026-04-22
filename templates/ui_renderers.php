<?php
function render_fs_toggle( $name, $value = false, $args = array() ) {
	$template_data = array(
		'name'  => $name,
		'value' => $value,
		'args'  => $args,
	);

	$template_path = plugin_dir_path( __FILE__ ) . '../templates/components/UI/toggle.php';

	if ( file_exists( $template_path ) ) {
		include $template_path;
	}
}

function render_fs_badge( $text, $color = 'gray', $class = '' ) {
	// Переменная, которая будет доступна внутри шаблона
	$template_data = compact( 'text', 'color', 'class' );

	// Путь к файлу шаблона
	$template_path = plugin_dir_path( __FILE__ ) . '../templates/components/UI/badge.php';

	if ( file_exists( $template_path ) ) {
		include $template_path;
	}
}
