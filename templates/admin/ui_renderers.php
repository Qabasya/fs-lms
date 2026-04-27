<?php
function render_fs_toggle( $name, $value = false, $args = array() ) {
	$template_data = array(
		'name'  => $name,
		'value' => $value,
		'args'  => $args,
	);

	include __DIR__ . '/components/UI/toggle.php';
}

function render_fs_badge( $text, $color = 'gray', $class = '' ) {
	// Переменная, которая будет доступна внутри шаблона
	$template_data = compact( 'text', 'color', 'class' );

	// Путь к файлу шаблона
	include __DIR__ . '/components/UI/badge.php';
}
