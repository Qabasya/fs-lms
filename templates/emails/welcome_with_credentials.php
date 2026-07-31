<?php
/**
 * Email: Данные для входа после зачисления (только родителю).
 *
 * @var string $display_name       Имя пользователя (родителя)
 * @var string $login              Логин (email)
 * @var string $password           Пароль в открытом виде
 * @var string $login_url          URL страницы входа
 * @var string $student_full_name  Фамилия Имя Отчество ученика
 * @var string $parent_first_name  Имя родителя
 * @var string $parent_middle_name Отчество родителя
 */
defined( 'ABSPATH' ) || exit;

$safe_parent_first_name  = esc_html( $parent_first_name ?? $display_name ?? '' );
$safe_parent_middle_name = esc_html( $parent_middle_name ?? '' );
$safe_student_full_name  = esc_html( $student_full_name ?? '' );
$safe_login              = esc_html( $login ?? '' );
$safe_password           = esc_html( $password ?? '' );
$safe_login_url          = esc_url( $login_url ?? wp_login_url() );

$body = strtr(
	(string) require __DIR__ . '/bodies/welcome_with_credentials.php',
	array(
		'{parent_first_name}'  => $safe_parent_first_name,
		'{parent_middle_name}' => $safe_parent_middle_name,
		'{student_full_name}'  => $safe_student_full_name,
		'{login}'              => $safe_login,
		'{password}'           => $safe_password,
		'{login_url}'          => $safe_login_url,
	)
);


return array(
	'subject' => 'Добро пожаловать в FS LMS — данные для входа',
	'body'    => $body,
);
