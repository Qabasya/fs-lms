<?php
/**
 * Карусель статей публичных страниц предмета.
 *
 * Общий партиал страницы задания («Рекомендуемые статьи») и страницы статьи
 * («Читать далее»): одна разметка — одни стили (`components/_carousel.scss`)
 * и один скрипт (`components/article-carousel.js`, хук `.fs-task-carousel`).
 *
 * Сама карточка — примитив `.fs-subject-card` (components/_subject-page.scss),
 * тот же, что у карточки учебника (`fs-articles-card` в subject/articles.php):
 * одна вёрстка и одни стили карточки для витрины и карусели, `.fs-carousel-item`
 * задаёт только раскладку слайда (ширина в кадре, отступ между карточками).
 *
 * @var array  $carousel_articles Статьи: url, title, excerpt, thumbnail.
 * @var string $carousel_title    Заголовок блока.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Ui\Icon;

$carousel_articles = (array) ( $carousel_articles ?? array() );
$carousel_title    = (string) ( $carousel_title ?? 'Рекомендуемые статьи' );

if ( empty( $carousel_articles ) ) {
	return;
}
?>
<div class="fs-task-carousel">
	<div class="fs-carousel-header">
		<h3 class="fs-carousel-title"><?php echo esc_html( $carousel_title ); ?></h3>
	</div>
	<div class="fs-carousel-row">
		<button type="button" class="fs-carousel-btn fs-carousel-btn--prev" aria-label="Назад"><?php echo Icon::ChevronLeft->svg( 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>

		<div class="fs-carousel-overflow">
			<div class="fs-carousel-track">
				<?php foreach ( $carousel_articles as $article ) : ?>
					<div class="fs-carousel-item">
						<article class="fs-subject-card">
							<a class="fs-subject-card-link" href="<?php echo esc_url( $article['url'] ); ?>">
								<?php // Нет обложки — заглушка на её месте: карточки карусели равны по высоте. ?>
								<?php if ( ! empty( $article['thumbnail'] ) ) : ?>
									<img class="fs-subject-card-thumb"
										src="<?php echo esc_url( $article['thumbnail'] ); ?>"
										alt="" loading="lazy" decoding="async" />
								<?php else : ?>
									<span class="fs-subject-card-thumb fs-subject-card-thumb--empty" aria-hidden="true"></span>
								<?php endif; ?>
								<span class="fs-subject-card-body">
									<strong class="fs-subject-card-title"><?php echo esc_html( $article['title'] ); ?></strong>
									<?php if ( ! empty( $article['excerpt'] ) ) : ?>
										<span class="fs-subject-card-text"><?php echo esc_html( $article['excerpt'] ); ?></span>
									<?php endif; ?>
									<?php // Стрелку рисует CSS (миксин fs-arrow-reveal) на ховере карточки. ?>
									<span class="fs-subject-card-more">Читать</span>
								</span>
							</a>
						</article>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<button type="button" class="fs-carousel-btn fs-carousel-btn--next" aria-label="Вперёд"><?php echo Icon::ChevronRight->svg( 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
	</div>
</div>