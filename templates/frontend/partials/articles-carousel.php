<?php
/**
 * Карусель статей публичных страниц предмета.
 *
 * Общий партиал страницы задания («Рекомендуемые статьи») и страницы статьи
 * («Читать далее»): одна разметка — одни стили (`components/_carousel.scss`)
 * и один скрипт (`components/article-carousel.js`, хук `.fs-task-carousel`).
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
						<a href="<?php echo esc_url( $article['url'] ); ?>">
							<?php // Нет обложки — заглушка на её месте: карточки карусели равны по высоте. ?>
							<?php if ( ! empty( $article['thumbnail'] ) ) : ?>
								<img class="fs-carousel-thumb"
									src="<?php echo esc_url( $article['thumbnail'] ); ?>"
									alt="" loading="lazy" decoding="async" />
							<?php else : ?>
								<span class="fs-carousel-thumb fs-carousel-thumb--empty" aria-hidden="true"></span>
							<?php endif; ?>
							<?php // Текст в своей обёртке: у ссылки отступов нет, чтобы миниатюра доходила до рамок. ?>
							<span class="fs-carousel-body">
								<strong><?php echo esc_html( $article['title'] ); ?></strong>
								<?php if ( ! empty( $article['excerpt'] ) ) : ?>
									<p><?php echo esc_html( $article['excerpt'] ); ?></p>
								<?php endif; ?>
								<?php // Стрелку рисует CSS (миксин fs-arrow-reveal) на ховере карточки. ?>
								<span class="fs-carousel-read">Читать</span>
							</span>
						</a>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<button type="button" class="fs-carousel-btn fs-carousel-btn--next" aria-label="Вперёд"><?php echo Icon::ChevronRight->svg( 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
	</div>
</div>