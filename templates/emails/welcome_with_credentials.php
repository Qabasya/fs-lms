<?php
/**
 * Email: Данные для входа после зачисления.
 *
 * @var string $display_name Имя пользователя
 * @var string $login        Логин (email)
 * @var string $password     Пароль в открытом виде
 * @var string $login_url    URL страницы входа
 */
defined( 'ABSPATH' ) || exit;

return array(
	'subject' => 'Добро пожаловать в FS LMS — данные для входа',
	'body'    => sprintf(
		'<p>Здравствуйте, %s!</p>'
		. '<p>Для вас создана учётная запись в системе FS LMS.</p>'
		. '<p><strong>Логин:</strong> %s<br>'
		. '<strong>Пароль:</strong> %s</p>'
		. '<p><a href="%s">Войти в личный кабинет</a></p>',
		esc_html( $display_name ?? '' ),
		esc_html( $login ?? '' ),
		esc_html( $password ?? '' ),
		esc_url( $login_url ?? wp_login_url() )
	),
);
