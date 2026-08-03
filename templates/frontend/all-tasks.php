<?php
/**
 * Страница «Все задания» (архив CPT {subject}_tasks).
 *
 * SSR-разметка первой страницы + точки монтирования для AllTasksPage (JS):
 * динамические группы фильтров-таксономий, карточки заданий, поиск,
 * infinite-scroll. Данные — AllTasksPageDTO из AllTasksDataBuilder.
 *
 * @var \Inc\DTO\AllTasksPageDTO $page_data
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Ui\Icon;
use Inc\Services\Shared\Pluralizer;
use Inc\Services\Shared\ThemeCompatService;

/** @var \Inc\DTO\AllTasksPageDTO $page_data */
$page_data   = get_query_var( 'fs_all_tasks_data' );
$subject_key = $page_data->subject_key;
$total       = $page_data->total;

// Пришли ли фильтры в URL (клик по тегу на странице задания): от этого зависит
// раскрытие секций сайдбара, бейджи и доступность кнопки «Сбросить».
$has_selected = (bool) array_sum( array_column( $page_data->filters, 'active' ) );

ThemeCompatService::header();
?>

<div class="fs-page-wrapper fs-all-tasks-page"
	data-subject-key="<?php echo esc_attr( $subject_key ); ?>"
	data-nonce="<?php echo esc_attr( $page_data->nonce ); ?>"
	data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
	data-per-page="<?php echo esc_attr( (string) $page_data->per_page ); ?>"
	data-total="<?php echo esc_attr( (string) $total ); ?>"
	data-has-more="<?php echo $page_data->has_more ? '1' : '0'; ?>">

	<div class="shell">
		<div class="layout">

			<!-- ===================== САЙДБАР / ФИЛЬТРЫ ===================== -->
			<aside class="sidebar" aria-label="Фильтры">

				<section class="side-card filters-side">
					<div class="filters-side-head">
						<span class="filters-side-title">Фильтры</span>
						<button class="filters-side-clear js-filters-clear" <?php echo $has_selected ? '' : 'disabled'; ?>>Сбросить</button>
					</div>

					<?php foreach ( $page_data->filters as $group ) : $group_active = (int) ( $group['active'] ?? 0 ); ?>
						<div class="filter-sec js-filter-sec" data-section="<?php echo esc_attr( $group['taxonomy'] ); ?>" <?php echo empty( $group['available'] ) ? 'hidden' : ''; ?>>
							<button class="filter-sec-head" aria-expanded="<?php echo $group_active ? 'true' : 'false'; ?>">
								<span class="filter-sec-title"><?php echo esc_html( $group['name'] ); ?></span>
								<span class="filter-sec-right">
									<?php if ( $group_active ) : ?><span class="filter-sec-badge"><?php echo esc_html( (string) $group_active ); ?></span><?php endif; ?>
									<span class="filter-sec-summary" <?php echo $group_active ? 'hidden' : ''; ?>><?php echo esc_html( $group['summary'] ); ?></span>
									<span class="filter-sec-chev<?php echo $group_active ? ' is-open' : ''; ?>" aria-hidden="true">
										<?php echo Icon::ChevronRight->svg( 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</span>
								</span>
							</button>
							<div class="filter-sec-body" <?php echo $group_active ? '' : 'hidden'; ?>>
								<div class="filter-options">
									<?php foreach ( $group['terms'] as $term ) : ?>
										<button class="filter-option js-filter-option<?php echo ! empty( $term['selected'] ) ? ' is-active' : ''; ?>"
											data-filter="<?php echo esc_attr( $group['taxonomy'] ); ?>"
											data-value="<?php echo esc_attr( $term['slug'] ); ?>"
											<?php echo empty( $term['available'] ) ? 'hidden' : ''; ?>>
											<span class="filter-option-label"><?php echo esc_html( $term['name'] ); ?></span>
											<span class="filter-option-count"><?php echo esc_html( (string) $term['count'] ); ?></span>
											<span class="filter-option-check" aria-hidden="true">
												<?php echo Icon::Check->svg( 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</span>
										</button>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</section>

				<?php
				// Статьи: без выбранного типа задания — случайные, с выбором — по нему
				// (добор свежими до 4). Список перерисовывает JS при смене фильтров.
				$sidebar_articles     = $page_data->articles;
				$sidebar_articles_url = $page_data->articles_url;
				include __DIR__ . '/partials/sidebar-articles.php';
				?>

			</aside>

			<!-- ===================== ОСНОВНОЙ КОНТЕНТ ===================== -->
			<main class="main" id="fs-tasks-main">

				<!-- Хлебные крошки (общий партиал со страницей задания) -->
				<?php
				$crumbs = $page_data->breadcrumbs;
				include __DIR__ . '/partials/breadcrumbs.php';
				?>

				<h1 class="page-title">Все задания</h1>

				<!-- Поиск -->
				<div class="toolbar">
					<div class="toolbar-search">
						<span class="toolbar-search-icon" aria-hidden="true">
							<?php echo Icon::Search->svg( 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</span>
						<input class="js-search-input"
							type="search"
							placeholder="Найти задание по номеру или фрагменту условия…"
							aria-label="Поиск" />
					</div>
				</div>

				<!-- Мета: счётчик + сброс. Форму слова после AJAX пересчитывает JS (common/plural.js). -->
				<div class="results-meta">
					<span>
						<strong class="js-results-count"><?php echo esc_html( (string) $total ); ?></strong>
						<span class="js-results-noun"><?php echo esc_html( Pluralizer::ru( $total, 'задание', 'задания', 'заданий' ) ); ?></span>
					</span>
					<button class="results-clear js-filters-clear" <?php echo $has_selected ? '' : 'hidden'; ?>>Сбросить</button>
				</div>

				<!-- Список карточек заданий -->
				<div class="task-cards js-task-cards">
					<?php foreach ( $page_data->tasks as $task ) : ?>
						<article class="task-card-row" data-task-id="<?php echo esc_attr( (string) $task->id ); ?>">
							<header class="tcr-header">
								<div class="tcr-header-inner">
									<div class="tcr-meta">
										<?php if ( $task->task_number > 0 && $task->task_number_slug ) : ?>
											<button type="button" class="tcr-tag js-tag-filter"
												data-filter="<?php echo esc_attr( $task->task_number_taxonomy ); ?>"
												data-value="<?php echo esc_attr( $task->task_number_slug ); ?>">
												Задание №<?php echo esc_html( (string) $task->task_number ); ?>
											</button>
										<?php endif; ?>
										<?php foreach ( $task->tags as $tag ) : ?>
											<button type="button" class="tcr-tag js-tag-filter"
												data-filter="<?php echo esc_attr( $tag['taxonomy'] ); ?>"
												data-value="<?php echo esc_attr( $tag['slug'] ); ?>">
												<?php echo esc_html( $tag['label'] ); ?>
											</button>
										<?php endforeach; ?>
									</div>
								</div>
							</header>

							<h2 class="tcr-title">
								<a class="tcr-title-link" href="<?php echo esc_url( $task->url ); ?>"><?php echo esc_html( $task->title ); ?></a>
							</h2>

							<?php if ( $task->condition ) : ?>
								<div class="tcr-body">
									<div class="tcr-condition"><?php echo wp_kses_post( $task->condition ); ?></div>
								</div>
							<?php endif; ?>

							<?php if ( ! empty( $task->files ) ) : ?>
								<div class="tcr-files">
									<?php foreach ( $task->files as $file ) : ?>
										<a class="tcr-file" href="<?php echo esc_url( $file['url'] ); ?>">
											<span class="tcr-file-icon" aria-hidden="true">
												<?php echo Icon::File->svg( 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</span>
											<span class="tcr-file-name"><?php echo esc_html( $file['name'] ); ?></span>
											<?php if ( ! empty( $file['size'] ) ) : ?>
												<span class="tcr-file-size"><?php echo esc_html( $file['size'] ); ?></span>
											<?php endif; ?>
										</a>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>

							<footer class="tcr-foot">
								<?php if ( $task->answer ) : ?>
									<button type="button" class="tcr-answer-toggle js-answer-toggle" aria-expanded="false">Ответ</button>
								<?php endif; ?>
								<div class="tcr-actions">
									<a class="tcr-btn tcr-btn-primary" href="<?php echo esc_url( $task->url ); ?>">
										<span>Смотреть решение</span>
										<?php echo Icon::ArrowRight->svg( 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</a>
								</div>
							</footer>

							<?php if ( $task->answer ) : ?>
								<div class="tcr-answer js-answer-panel" hidden>
									<div class="tcr-answer-label">Правильный ответ</div>
									<div class="tcr-answer-value"><?php echo esc_html( $task->answer ); ?></div>
								</div>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>

				<!-- Пустое состояние -->
				<div class="tasks-empty js-tasks-empty" <?php echo empty( $page_data->tasks ) ? '' : 'hidden'; ?>>
					<h3>Ничего не нашли</h3>
					<p>Попробуйте сбросить фильтры или поискать по другому ключу.</p>
					<button class="results-clear js-filters-clear">Сбросить</button>
				</div>

				<!-- Infinite scroll -->
				<div class="js-infinite-sentinel" <?php echo $page_data->has_more ? '' : 'hidden'; ?>>
					<div class="infinite-sentinel">
						<span class="infinite-spinner" aria-hidden="true"></span>
						<span>Прокрутите для загрузки ещё</span>
					</div>
				</div>

				<div class="infinite-end js-infinite-end" <?php echo $page_data->has_more ? 'hidden' : ''; ?>>
					Конец списка
				</div>

			</main>

		</div>
	</div>

</div>

<?php ThemeCompatService::footer(); ?>