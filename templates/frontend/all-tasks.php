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
use Inc\Services\Shared\ThemeCompatService;

/** @var \Inc\DTO\AllTasksPageDTO $page_data */
$page_data   = get_query_var( 'fs_all_tasks_data' );
$subject_key = $page_data->subject_key;
$total       = $page_data->total;

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
						<button class="filters-side-clear js-filters-clear" hidden>Сбросить</button>
					</div>

					<?php foreach ( $page_data->filters as $group ) : ?>
						<div class="filter-sec js-filter-sec" data-section="<?php echo esc_attr( $group['taxonomy'] ); ?>">
							<button class="filter-sec-head" aria-expanded="false">
								<span class="filter-sec-title"><?php echo esc_html( $group['name'] ); ?></span>
								<span class="filter-sec-right">
									<span class="filter-sec-summary"><?php echo esc_html( (string) count( $group['terms'] ) ); ?></span>
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
											data-value="<?php echo esc_attr( $term['slug'] ); ?>">
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

			</aside>

			<!-- ===================== ОСНОВНОЙ КОНТЕНТ ===================== -->
			<main class="main" id="fs-tasks-main">

				<!-- Хлебные крошки -->
				<nav class="crumbs" aria-label="Хлебные крошки">
					<a href="#" class="crumb"><?php echo esc_html( $page_data->subject_name ); ?></a>
					<span class="crumb-sep">/</span>
					<span class="crumb crumb--current">Все задания</span>
				</nav>

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

				<!-- Мета: счётчик + сброс -->
				<div class="results-meta">
					<span>
						<strong class="js-results-count"><?php echo esc_html( (string) $total ); ?></strong>
						<?php
						$m10  = $total % 10;
						$m100 = $total % 100;
						if ( 1 === $m10 && 11 !== $m100 ) {
							echo 'задание';
						} elseif ( $m10 >= 2 && $m10 <= 4 && ( $m100 < 12 || $m100 > 14 ) ) {
							echo 'задания';
						} else {
							echo 'заданий';
						}
						?>
					</span>
					<button class="results-clear js-filters-clear" hidden>Сбросить</button>
				</div>

				<!-- Список карточек заданий -->
				<div class="task-cards js-task-cards">
					<?php foreach ( $page_data->tasks as $task ) : ?>
						<article class="task-card-row" data-task-id="<?php echo esc_attr( (string) $task->id ); ?>">
							<header class="tcr-header">
								<div class="tcr-header-inner">
									<div class="tcr-id">№ <?php echo esc_html( (string) $task->id ); ?></div>
									<div class="tcr-meta">
										<?php if ( $task->task_number > 0 && $task->task_number_slug ) : ?>
											<button type="button" class="tcr-tag tcr-tag-mono js-tag-filter"
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

							<h2 class="tcr-title"><?php echo esc_html( $task->title ); ?></h2>

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

							<?php if ( $task->answer ) : ?>
								<div class="tcr-answer js-answer-panel" hidden>
									<div class="tcr-answer-label">Правильный ответ</div>
									<div class="tcr-answer-value"><?php echo esc_html( $task->answer ); ?></div>
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
						</article>
					<?php endforeach; ?>
				</div>

				<!-- Пустое состояние -->
				<div class="tasks-empty js-tasks-empty" <?php echo empty( $page_data->tasks ) ? '' : 'hidden'; ?>>
					<h3>Ничего не нашли</h3>
					<p>Попробуйте ослабить фильтры или поискать по другому ключу.</p>
					<button class="results-clear js-filters-clear">Сбросить фильтры</button>
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