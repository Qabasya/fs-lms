<?php
/**
 * Email: Ученику открыт доступ к открытому курсу (Эпик 15).
 *
 * @var string $display_name Имя пользователя
 * @var string $course_title Название курса
 * @var string $profile_url  Ссылка на личный кабинет («Мои курсы»)
 */
defined( 'ABSPATH' ) || exit;

return array(
	'subject' => 'Вам открыт курс «' . esc_html( $course_title ?? '' ) . '» — FS LMS',
	'body'    => sprintf(
		'<p>Здравствуйте, %s!</p>'
		. '<p>Вам открыт доступ к курсу <strong>%s</strong>. Все уроки доступны сразу — проходите в удобном темпе.</p>'
		. '<p>Курс уже ждёт вас в личном кабинете:<br><a href="%s">%s</a></p>',
		esc_html( $display_name ?? '' ),
		esc_html( $course_title ?? '' ),
		esc_url( $profile_url ?? '' ),
		esc_html( $profile_url ?? '' )
	),
);
