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

	<!-- Полоса крошек с поиском липкая и лежит вне .shell — её фон тянется
		на всю ширину окна. -->
	<div class="crumbs-row">
		<div class="crumbs-row__inner">
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
	</div>

	<div class="shell">
		<div class="layout">

			<!-- ===================== САЙДБАР / ФИЛЬТРЫ ===================== -->
			<aside class="sidebar js-scroll-sticky" aria-label="Фильтры">

				<?php if ( ! empty( $page_data->filters ) ) : ?>
					<?php
					$filters_groups   = $page_data->filters;
					$filters_selected = false;
					include __DIR__ . '/../partials/filters-side.php';
					?>
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