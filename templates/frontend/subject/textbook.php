<?php
/**
 * Раздел «Учебник» лендинга предмета (шорткод [fs_lms_subject_textbook]).
 *
 * Витрина статей предмета: свежие первыми, без догрузки — объём выборки задаёт
 * SubjectLandingController.
 *
 * @var array $articles Статьи: id, title, url, excerpt, task_number, thumbnail.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$articles = (array) ( $articles ?? array() );
?>
<div class="fs-page-wrapper">
	<div class="fs-subject-section">
		<?php if ( empty( $articles ) ) : ?>
			<p class="fs-subject-empty">Статей пока нет — они появятся здесь.</p>
		<?php else : ?>
			<div class="fs-subject-grid">
				<?php foreach ( $articles as $article ) : ?>
					<article class="fs-subject-card">
						<a class="fs-subject-card-link" href="<?php echo esc_url( $article['url'] ); ?>">
							<?php // Нет обложки — заглушка на её месте: карточки ряда равны по высоте. ?>
							<?php if ( ! empty( $article['thumbnail'] ) ) : ?>
								<img class="fs-subject-card-thumb"
									src="<?php echo esc_url( $article['thumbnail'] ); ?>"
									alt="" loading="lazy" decoding="async" />
							<?php else : ?>
								<span class="fs-subject-card-thumb fs-subject-card-thumb--empty" aria-hidden="true"></span>
							<?php endif; ?>

							<span class="fs-subject-card-body">
								<?php if ( ! empty( $article['task_number'] ) ) : ?>
									<span class="fs-subject-card-tag">Задание №<?php echo esc_html( (string) $article['task_number'] ); ?></span>
								<?php endif; ?>

								<strong class="fs-subject-card-title"><?php echo esc_html( $article['title'] ); ?></strong>

								<?php if ( ! empty( $article['excerpt'] ) ) : ?>
									<span class="fs-subject-card-text"><?php echo esc_html( $article['excerpt'] ); ?></span>
								<?php endif; ?>

								<?php // Стрелку рисует CSS (миксин fs-arrow-reveal) на ховере карточки. ?>
								<span class="fs-subject-card-more">Читать</span>
							</span>
						</a>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</div>