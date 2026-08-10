<?php
/**
 * Блок-призыв «Тренажёр заданий» в сайдбаре публичных страниц предмета.
 *
 * Разметка та же, что у остальных блоков сайдбара (`_sidebar.scss`)
 *
 * @var string $sidebar_trainer_url   Раздел заданий предмета; '' — блока нет.
 * @var int    $sidebar_trainer_total Сколько заданий опубликовано в банке.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Services\Shared\Pluralizer;

$sidebar_trainer_url   = (string) ( $sidebar_trainer_url ?? '' );
$sidebar_trainer_total = (int) ( $sidebar_trainer_total ?? 0 );

if ( '' === $sidebar_trainer_url || $sidebar_trainer_total < 1 ) {
	return;
}

$sidebar_trainer_text = sprintf(
	'После теории — %s с фильтрами.',
	Pluralizer::withNumber( $sidebar_trainer_total, 'задание', 'задания', 'заданий' )
);
?>
<section class="fs-sidebar-block fs-sidebar-trainer">
	<div class="fs-sidebar-head">
		<span class="fs-sidebar-title">Тренажёр заданий</span>
	</div>

	<div class="fs-sidebar-trainer__body">
		<p class="fs-sidebar-trainer__text"><?php echo esc_html( $sidebar_trainer_text ); ?></p>
		<a href="<?php echo esc_url( $sidebar_trainer_url ); ?>" class="fs-sidebar-trainer__btn">Перейти к заданиям</a>
	</div>
</section>
