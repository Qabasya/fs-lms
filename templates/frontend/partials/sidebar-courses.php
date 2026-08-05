<?php
/**
 * Блок курсов в сайдбаре публичных страниц предмета.
 *
 * Общий партиал страницы задания и «Всех заданий»: одна разметка — одни стили
 * (`src/scss/frontend/components/_sidebar.scss`). Курсов нет — блока нет:
 * заглушку «Скоро появятся курсы» не показываем.
 *
 * @var \Inc\DTO\Course\CourseCardDTO[] $sidebar_courses Опубликованные курсы предмета.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Services\Shared\Pluralizer;

$sidebar_courses = (array) ( $sidebar_courses ?? array() );

if ( empty( $sidebar_courses ) ) {
	return;
}
?>
<section class="fs-sidebar-block">
	<div class="fs-sidebar-head">
		<span class="fs-sidebar-title">Курсы</span>
		<?php // Стрелку рисует CSS (миксин fs-arrow-reveal): в покое её нет, появляется по ховеру. ?>
		<?php // TODO: публичной витрины курсов пока нет — ссылка-заглушка. ?>
		<a href="#" class="fs-sidebar-more">Все курсы</a>
	</div>

	<ul class="fs-sidebar-courses">
		<?php foreach ( $sidebar_courses as $course ) : ?>
			<li>
				<a href="<?php echo esc_url( $course->url ); ?>">
					<span class="fs-sidebar-course-title"><?php echo esc_html( $course->title ); ?></span>
					<?php if ( $course->lessons > 0 ) : ?>
						<span class="fs-sidebar-course-meta">
							<?php echo esc_html( Pluralizer::withNumber( $course->lessons, 'урок', 'урока', 'уроков' ) ); ?>
						</span>
					<?php endif; ?>
					<?php // Не ссылка, а подпись внутри неё: вложенные <a> недопустимы. ?>
					<span class="fs-sidebar-course-go">Записаться</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</section>