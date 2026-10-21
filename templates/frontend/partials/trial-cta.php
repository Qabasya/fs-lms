<?php
/**
 * Блок-призыв «Бесплатное пробное занятие» под заданием и в конце статьи.
 *
 * Информационный трафик (школьник пришёл с условием задачи) не готов записываться
 * на курс, поэтому призыв называет конкретный следующий шаг, а не общий «Запишитесь».
 * Разметка и стили — те же, что у блока «Тренажёр заданий» сайдбара (`_sidebar.scss`).
 *
 * @var string $trial_cta_kind  'task' | 'article' — от этого зависит заголовок.
 * @var string $trial_cta_url   Страница направления предмета; '' — блока нет.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$trial_cta_kind = (string) ( $trial_cta_kind ?? 'task' );
$trial_cta_url  = (string) ( $trial_cta_url ?? '' );

if ( '' === $trial_cta_url ) {
	return;
}

$trial_cta_title = 'task' === $trial_cta_kind
	? 'Разберём это задание на бесплатном пробном занятии'
	: 'Разберём эту тему на бесплатном пробном занятии';
?>
<section class="fs-sidebar-block fs-sidebar-trainer fs-trial-cta">
	<div class="fs-sidebar-head">
		<span class="fs-sidebar-title"><?php echo esc_html( $trial_cta_title ); ?></span>
	</div>

	<div class="fs-sidebar-trainer__body">
		<p class="fs-sidebar-trainer__text">Преподаватель покажет, как решать такие задачи, и подскажет, с чего начать подготовку. Группы до 8 человек, очно в Калининграде и онлайн.</p>
		<a href="<?php echo esc_url( $trial_cta_url ); ?>" class="fs-sidebar-trainer__btn">Записаться на пробное занятие</a>
	</div>
</section>
