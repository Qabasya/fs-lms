<?php
/**
 * Email: OTP-код подтверждения email.
 *
 * @var string $code Шестизначный код
 */
defined( 'ABSPATH' ) || exit;

$safe_code = esc_html( $code ?? '' );

$body = str_replace(
	'{code}',
	$safe_code,
	(string) require __DIR__ . '/bodies/otp_code.php'
);


return array(
	'subject' => 'Код подтверждения — FS LMS',
	'body'    => $body,
);
