<?php
/**
 * Тело страницы «Все задания» (тренажёр предмета).
 *
 * SSR-разметка первой страницы + точки монтирования для AllTasksPage (JS):
 * динамические группы фильтров-таксономий, карточки заданий, поиск,
 * infinite-scroll. Данные — AllTasksPageDTO из AllTasksDataBuilder.
 *
 * Выводится разделом «Тренажёр» лендинга предмета (`subject/trainer.php`);
 * JS-зеркало карточки — `components/task-card.js`.
 *
 * @var \Inc\DTO\Task\AllTasksPageDTO $page_data
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Ui\Icon;

$subject_key = $page_data->subject_key;
$total       = $page_data->total;

// Пришли ли фильтры в URL (клик по тегу на странице задания): от этого зависит
// раскрытие секций сайдбара, бейджи и доступность кнопки «Сбросить».
$has_selected = (bool) array_sum( array_column( $page_data->filters, 'active' ) );
?>

<div class="fs-page-wrapper fs-all-tasks-page"
	data-subject-key="<?php echo esc_attr( $subject_key ); ?>"
	data-nonce="<?php echo esc_attr( $page_data->nonce ); ?>"
	data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
	data-per-page="<?php echo esc_attr( (string) $page_data->per_page ); ?>"
	data-total="<?php echo esc_attr( (string) $total ); ?>"
	data-has-more="<?php echo $page_data->has_more ? '1' : '0'; ?>">

	<div class="shell">
		<!-- Крошки (общий партиал со страницей задания) и свёрнутый поиск в одной строке -->
		<div class="crumbs-row">
			<?php
			$crumbs = $page_data->breadcrumbs;
			include __DIR__ . '/breadcrumbs.php';
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
					placeholder="Номер или фрагмент условия…"
					aria-label="Поиск" />
				<button type="button" class="toolbar-search-clear js-search-clear" aria-label="Очистить поиск" hidden>
					<?php echo Icon::Cross->svg( 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>
		</div>

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
												<?php // 10px: бокс галочки 14px с рамкой 1.5 даёт 11px внутри — иконка 14 в него не помещалась. ?>
												<?php echo Icon::Check->svg( 10 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</span>
										</button>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</section>

				<?php
				// Курсы предмета — общий со страницей задания партиал; нет курсов, нет блока.
				$sidebar_courses     = $page_data->courses;
				$sidebar_courses_url = $page_data->courses_url;
				include __DIR__ . '/sidebar-courses.php';
				?>

				<?php
				// Статьи: без выбранного типа задания — случайные, с выбором — по нему
				// (добор свежими до лимита). Список перерисовывает JS при смене фильтров.
				$sidebar_articles     = $page_data->articles;
				$sidebar_articles_url = $page_data->articles_url;
				include __DIR__ . '/sidebar-articles.php';
				?>

			</aside>

			<!-- ===================== ОСНОВНОЙ КОНТЕНТ ===================== -->
			<main class="main" id="fs-tasks-main">

				<!-- Список карточек заданий -->
				<div class="task-cards js-task-cards">
					<?php foreach ( $page_data->tasks as $task ) : ?>
						<article class="task-card-row" data-task-id="<?php echo esc_attr( (string) $task->id ); ?>">
							<h2 class="tcr-title">
								<a class="tcr-title-link" href="<?php echo esc_url( $task->url ); ?>"><?php echo esc_html( $task->title ); ?></a>
							</h2>

							<header class="tcr-header">
								<div class="tcr-header-inner">
									<div class="tcr-meta">
										<?php if ( $task->task_number > 0 && $task->task_number_slug ) : ?>
											<?php // Ступень палитры чипа — по таксономии (TagPaletteService). ?>
											<button type="button" class="tcr-tag js-tag-filter<?php echo $task->task_number_color > 0 ? ' tcr-tag--c' . (int) $task->task_number_color : ''; ?>"
												data-filter="<?php echo esc_attr( $task->task_number_taxonomy ); ?>"
												data-value="<?php echo esc_attr( $task->task_number_slug ); ?>">
												Задание №<?php echo esc_html( (string) $task->task_number ); ?>
											</button>
										<?php endif; ?>
										<?php foreach ( $task->tags as $tag ) : ?>
											<button type="button" class="tcr-tag js-tag-filter<?php echo ! empty( $tag['color'] ) ? ' tcr-tag--c' . (int) $tag['color'] : ''; ?>"
												data-filter="<?php echo esc_attr( $tag['taxonomy'] ); ?>"
												data-value="<?php echo esc_attr( $tag['slug'] ); ?>">
												<?php echo esc_html( $tag['label'] ); ?>
											</button>
										<?php endforeach; ?>
									</div>
								</div>
							</header>

							<?php if ( $task->condition ) : ?>
								<div class="tcr-body js-condition">
									<?php // Кнопку раскрытия показывает task-condition.js — только тем условиям, что не влезли. ?>
									<div class="tcr-condition" id="tcr-cond-<?php echo esc_attr( (string) $task->id ); ?>"><?php echo wp_kses_post( $task->condition ); ?></div>
									<button type="button" class="tcr-condition-toggle js-condition-toggle"
										aria-expanded="false" aria-controls="tcr-cond-<?php echo esc_attr( (string) $task->id ); ?>" hidden>
										<span class="js-condition-toggle-label">Показать полностью</span>
										<span class="tcr-condition-toggle-ico" aria-hidden="true">
											<?php echo Icon::ChevronDown->svg( 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</span>
									</button>
								</div>
							<?php endif; ?>

							<?php if ( ! empty( $task->files ) ) : ?>
								<div class="tcr-files">
									<?php foreach ( $task->files as $file ) : ?>
										<a class="tcr-file" href="<?php echo esc_url( $file['url'] ); ?>">
											<span class="tcr-file-icon" aria-hidden="true">
												<?php echo Icon::File->svg( 17 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</span>
											<span class="tcr-file-name"><?php echo esc_html( $file['name'] ); ?></span>
											<?php if ( ! empty( $file['size'] ) ) : ?>
												<span class="tcr-file-size"><?php echo esc_html( $file['size'] ); ?></span>
											<?php endif; ?>
											<span class="tcr-file-dl" aria-hidden="true">
												<?php echo Icon::Download->svg( 17 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</span>
										</a>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>

							<footer class="tcr-foot">
								<?php if ( $task->answer ) : ?>
									<button type="button" class="fs-answer-toggle js-answer-toggle" aria-expanded="false"
										aria-controls="fs-answer-<?php echo esc_attr( (string) $task->id ); ?>">Показать ответ</button>
								<?php endif; ?>
								<div class="tcr-actions">
									<a class="tcr-btn tcr-btn-primary" href="<?php echo esc_url( $task->url ); ?>">
										<span>Смотреть решение</span>
										<?php echo Icon::ArrowRight->svg( 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</a>
								</div>
							</footer>

							<?php if ( $task->answer ) : ?>
								<div id="fs-answer-<?php echo esc_attr( (string) $task->id ); ?>" class="fs-answer js-answer-panel" hidden>
									<div class="fs-answer-label">Правильный ответ:</div>
									<div class="fs-answer-value"><?php echo esc_html( $task->answer ); ?></div>
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


	<?php // Кнопка «наверх» — показывает и прячет components/scroll-top.js. ?>
	<button type="button" class="fs-to-top js-to-top" aria-label="Наверх" hidden>
		<?php echo Icon::ChevronDown->svg( 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</button>
</div>