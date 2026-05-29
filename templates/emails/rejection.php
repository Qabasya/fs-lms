<?php
/**
 * Email: Отклонение заявки.
 *
 * @var string $reason Причина отклонения
 */
defined( 'ABSPATH' ) || exit;

return array(
	'subject' => 'Заявка отклонена — FS LMS',
	'body'    => sprintf(
		'<p>К сожалению, ваша заявка была отклонена.</p>'
		. '<p><strong>Причина:</strong> %s</p>'
		. '<p>Если у вас есть вопросы, обратитесь к администратору.</p>',
		esc_html( $reason ?? '' )
	),
);