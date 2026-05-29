<?php
/**
 * Email: Ссылка установки пароля.
 *
 * @var string $link         URL для установки пароля
 * @var string $display_name Имя пользователя
 */
defined( 'ABSPATH' ) || exit;

return array(
	'subject' => 'Установите пароль для входа в FS LMS',
	'body'    => sprintf(
		'<p>Здравствуйте, %s!</p>'
		. '<p>Для вас создана учётная запись в системе FS LMS.</p>'
		. '<p>Перейдите по ссылке ниже, чтобы установить пароль:</p>'
		. '<p><a href="%s" style="font-size:16px">Установить пароль</a></p>'
		. '<p>Ссылка действительна <strong>48 часов</strong>. Если вы не запрашивали эту ссылку — проигнорируйте письмо.</p>',
		esc_html( $display_name ?? '' ),
		esc_url( $link ?? '' )
	),
);