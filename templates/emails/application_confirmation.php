<?php
/**
 * Email: Подтверждение заявки ученику.
 *
 * @var string $join_url   JOIN-ссылка для родителя
 * @var string $expires_at Дата истечения (MySQL datetime)
 */
defined( 'ABSPATH' ) || exit;

return array(
	'subject' => 'Ваша заявка принята — FS LMS',
	'body'    => sprintf(
		'<p>Ваша заявка успешно принята!</p>'
		. '<p>Передайте родителю или законному представителю следующую ссылку для заполнения данных:</p>'
		. '<p><a href="%s" style="font-size:16px;word-break:break-all">%s</a></p>'
		. '<p>Ссылка действительна до: <strong>%s</strong>.</p>'
		. '<p>После того как представитель заполнит данные, вы получите уведомление.</p>',
		esc_url( $join_url ?? '' ),
		esc_html( $join_url ?? '' ),
		esc_html( $expires_at ?? '' )
	),
);