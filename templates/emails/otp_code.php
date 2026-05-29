<?php
/**
 * Email: OTP-код подтверждения email.
 *
 * @var string $code Шестизначный код
 */
defined( 'ABSPATH' ) || exit;

return array(
	'subject' => 'Код подтверждения — FS LMS',
	'body'    => sprintf(
		'<p>Ваш код подтверждения: <strong style="font-size:24px;letter-spacing:4px">%s</strong></p>'
		. '<p>Код действителен <strong>10 минут</strong>. Не передавайте его третьим лицам.</p>',
		esc_html( $code ?? '' )
	),
);