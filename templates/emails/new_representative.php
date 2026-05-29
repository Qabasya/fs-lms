<?php
/**
 * Email: Уведомление родителю о добавлении нового подопечного.
 *
 * @var string $display_name Имя пользователя
 * @var string $link         Ссылка для входа (может быть пустой если юзер уже зарегистрирован)
 */
defined( 'ABSPATH' ) || exit;

$link_block = '';
if ( ! empty( $link ) ) {
	$link_block = sprintf(
		'<p>Для входа в личный кабинет перейдите по ссылке:<br><a href="%s">%s</a></p>',
		esc_url( $link ),
		esc_html( $link )
	);
}

return array(
	'subject' => 'В вашем профиле появился новый подопечный — FS LMS',
	'body'    => sprintf(
		'<p>Здравствуйте, %s!</p>'
		. '<p>В вашем профиле FS LMS был добавлен новый подопечный.</p>'
		. '%s',
		esc_html( $display_name ?? '' ),
		$link_block
	),
);