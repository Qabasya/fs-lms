<?php
/**
 * Раздел «Учебник» лендинга предмета (шорткод [fs_lms_subject_articles]).
 *
 * Каталог статей: секции по номерам заданий, фильтры и поиск в сайдбаре.
 * Оболочка, крошки, поиск и фильтры — те же компоненты, что у тренажёра
 * (`partials/all-tasks-body.php`), поэтому стили общие: `.fs-articles-page`
 * подмешана к скоупу `all-tasks/*`. Фильтрует список на клиенте
 * `components/article-catalog.js`.
 *
 * @var \Inc\DTO\Article\ArticlesPageDTO $page_data
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Ui\Icon;
use Inc\Services\Shared\Pluralizer;
?>
<div class="fs-page-wrapper fs-articles-page">
	<div class="shell">

		<div class="crumbs-row">
			<?php
			$crumbs = $page_data->breadcrumbs;
			include __DIR__ . '/../partials/breadcrumbs.php';
			?>

			<div class="toolbar-search js-search">
				<button type="button" class="toolbar-search-toggle js-search-toggle" aria-expanded="false" aria-label="Поиск">
					<span class="toolbar-search-icon" aria-hidden="true">
						<?php echo Icon::Search->svg( 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>
					<span class="toolbar-search-hint">Поиск</span>
				</button>
				<input class="toolbar-search-input js-search-input"
					type="search"
					placeholder="Название или фрагмент описания…"
					aria-label="Поиск по статьям" />
				<button type="button" class="toolbar-search-clear js-search-clear" aria-label="Очистить поиск" hidden>
					<?php echo Icon::Cross->svg( 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>
		</div>

		<div class="layout">

			<!-- ===================== САЙДБАР / ФИЛЬТРЫ ===================== -->
			<aside class="sidebar" aria-label="Фильтры">

				<?php if ( ! empty( $page_data->filters ) ) : ?>
					<section class="side-card filters-side">
						<div class="filters-side-head">
							<span class="filters-side-title">Фильтры</span>
							<button class="filters-side-clear js-filters-clear" disabled>Сбросить</button>
						</div>

						<?php // Недоступные под текущий срез опции остаются в разметке скрытыми: их возвращает JS при снятии фильтра. ?>
						<?php foreach ( $page_data->filters as $group ) : ?>
							<div class="filter-sec js-filter-sec"
								data-section="<?php echo esc_attr( $group['taxonomy'] ); ?>"
								<?php echo ! empty( $group['is_type'] ) ? 'data-is-type="1"' : ''; ?>>
								<button class="filter-sec-head" aria-expanded="false">
									<span class="filter-sec-title"><?php echo esc_html( $group['name'] ); ?></span>
									<span class="filter-sec-right">
										<span class="filter-sec-summary"><?php echo esc_html( $group['summary'] ); ?></span>
										<span class="filter-sec-chev" aria-hidden="true">
											<?php echo Icon::ChevronRight->svg( 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</span>
									</span>
								</button>
								<div class="filter-sec-body" hidden>
									<div class="filter-options">
										<?php foreach ( $group['terms'] as $term ) : ?>
											<button class="filter-option js-filter-option"
												data-filter="<?php echo esc_attr( $group['taxonomy'] ); ?>"
												data-value="<?php echo esc_attr( $term['slug'] ); ?>"
												<?php echo empty( $term['available'] ) ? 'hidden' : ''; ?>>
												<span class="filter-option-label"><?php echo esc_html( $term['name'] ); ?></span>
												<span class="filter-option-count"><?php echo esc_html( (string) $term['count'] ); ?></span>
												<span class="filter-option-check" aria-hidden="true">
													<?php echo Icon::Check->svg( 10 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												</span>
											</button>
										<?php endforeach; ?>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</section>
				<?php endif; ?>

				<?php
				// Блок-призыв в тренажёр — общий партиал со страницей статьи.
				$sidebar_trainer_url   = $page_data->trainer_url;
				$sidebar_trainer_total = $page_data->tasks_total;
				include __DIR__ . '/../partials/sidebar-trainer.php';
				?>

			</aside>

			<!-- ===================== КАТАЛОГ СТАТЕЙ ===================== -->
			<main class="main">

				<?php foreach ( $page_data->sections as $section ) : ?>
					<section class="fs-articles-sec js-articles-sec" id="fs-articles-<?php echo esc_attr( $section->anchor ); ?>">
						<div class="fs-articles-sec__head">
							<h2 class="fs-articles-sec__title"><?php echo esc_html( $section->label ); ?></h2>
							<span class="fs-articles-sec__count js-articles-count">
								<?php echo esc_html( Pluralizer::withNumber( $section->total(), 'статья', 'статьи', 'статей' ) ); ?>
							</span>
						</div>

						<div class="fs-articles-grid">
							<?php foreach ( $section->articles as $article ) : ?>
								<article class="fs-subject-card fs-articles-card js-article-card"
									data-terms="<?php echo esc_attr( $article->termTokens() ); ?>">
									<a class="fs-subject-card-link" href="<?php echo esc_url( $article->url ); ?>">
										<?php // Нет обложки — заглушка на её месте: карточки ряда равны по высоте. ?>
										<?php if ( '' !== $article->thumbnail ) : ?>
											<img class="fs-subject-card-thumb"
												src="<?php echo esc_url( $article->thumbnail ); ?>"
												alt="" loading="lazy" decoding="async" />
										<?php else : ?>
											<span class="fs-subject-card-thumb fs-subject-card-thumb--empty" aria-hidden="true"></span>
										<?php endif; ?>

										<span class="fs-subject-card-body">
											<strong class="fs-subject-card-title"><?php echo esc_html( $article->title ); ?></strong>

											<?php if ( '' !== $article->excerpt ) : ?>
												<span class="fs-subject-card-text"><?php echo esc_html( $article->excerpt ); ?></span>
											<?php endif; ?>

											<span class="fs-articles-card__meta">
												<?php if ( $article->minutes > 0 ) : ?>
													<span class="fs-subject-card-meta"><?php echo esc_html( $article->minutes . ' мин' ); ?></span>
												<?php endif; ?>
												<?php // Стрелку рисует CSS (миксин fs-arrow-reveal) на ховере карточки. ?>
												<span class="fs-subject-card-more">Читать</span>
											</span>
										</span>
									</a>
								</article>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endforeach; ?>

				<?php if ( 0 === $page_data->total ) : ?>
					<p class="fs-subject-empty">Статей пока нет — они появятся здесь.</p>
				<?php endif; ?>

				<?php // Пустой результат фильтра — не то же, что пустой учебник: показывает JS. ?>
				<div class="fs-articles-empty js-articles-empty" hidden>
					<h3>Ничего не нашли</h3>
					<p>Измените запрос или снимите фильтр.</p>
					<button class="results-clear js-filters-clear">Сбросить</button>
				</div>

				<?php ?>
				<div class="js-infinite-sentinel" hidden>
					<div class="infinite-sentinel">
						<span class="infinite-spinner" aria-hidden="true"></span>
						<span>Прокрутите для загрузки ещё</span>
					</div>
				</div>

				<div class="infinite-end js-infinite-end" hidden>Конец списка</div>

			</main>

		</div>
	</div>

	<?php // Кнопка «наверх» — показывает и прячет components/scroll-top.js. ?>
	<button type="button" class="fs-to-top js-to-top" aria-label="Наверх" hidden>
		<?php echo Icon::ChevronDown->svg( 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</button>
</div>