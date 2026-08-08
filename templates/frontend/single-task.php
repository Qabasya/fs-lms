<?php
/**
 * Страница одного задания (CPT {subject}_tasks).
 *
 * Данные — TaskPageDTO из TaskDataBuilder. Крошки, чипы, карточка и чипы файлов
 * общие со страницей «Все задания»: разметка крошек — партиал partials/breadcrumbs.php,
 * геометрия — примитивы из `src/scss/frontend/_mixins.scss`.
 *
 * @var \Inc\DTO\Task\TaskPageDTO $task_data
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Ui\Icon;
use Inc\Services\Shared\ThemeCompatService;

/** @var \Inc\DTO\Task\TaskPageDTO $task_data */
$task_data   = get_query_var( 'fs_task_data' );
$task_post   = $task_data->post;
$content     = $task_data->content;
$files       = $task_data->files;
$tags        = $task_data->tags;
$articles    = $task_data->articles;
$navigation  = $task_data->navigation;
$tabs        = $task_data->tabs;
$crumbs      = $navigation->breadcrumbs;
$archive_url = $navigation->archive_url;
$nav_prev    = $navigation->prev;
$nav_next    = $navigation->next;

// Пустой сайдбар (ни курсов, ни статей) не рендерим: контент занимает всю сетку.
$has_sidebar = ! empty( $task_data->courses ) || ! empty( $articles['related'] );

ThemeCompatService::header();
?>

<div class="fs-page-wrapper">
	<div class="fs-page-shell">
		<div class="fs-task-page<?php echo $has_sidebar ? '' : ' fs-task-page--solo'; ?>">

			<!-- ===================== ЛЕВЫЙ САЙДБАР ===================== -->
			<?php if ( $has_sidebar ) : ?>
			<aside class="fs-task-sidebar">

				<?php
				// Курсы предмета — общий партиал; нет опубликованных курсов, нет блока.
				$sidebar_courses     = $task_data->courses;
				$sidebar_courses_url = $task_data->courses_url;
				include __DIR__ . '/partials/sidebar-courses.php';
				?>

				<?php
				// Статьи по типу задания — общий с «Все задания» партиал сайдбара.
				$sidebar_articles     = $articles['related'];
				$sidebar_articles_url = $articles['archive_url'] ?? '';
				include __DIR__ . '/partials/sidebar-articles.php';
				?>

			</aside>
			<?php endif; ?>

			<!-- ===================== ОСНОВНОЙ КОНТЕНТ ===================== -->
			<main class="fs-task-main">

				<!-- Хлебные крошки (общий партиал со страницей «Все задания») -->
				<?php include __DIR__ . '/partials/breadcrumbs.php'; ?>

				<!-- Заголовок задания -->
				<h1 class="fs-task-title"><?php echo esc_html( $task_post?->title ?? '' ); ?></h1>

				<!-- Навигация: предыдущее / все задания / следующее -->
				<nav class="fs-task-nav">
					<?php if ( $nav_prev ) : ?>
					<a href="<?php echo esc_url( $nav_prev->url ); ?>" class="fs-task-nav__side fs-task-nav__side--prev" aria-label="Предыдущее задание">
					<?php else : ?>
					<div class="fs-task-nav__side fs-task-nav__side--prev">
					<?php endif; ?>
						<span class="fs-task-nav__arrow<?php echo ! $nav_prev ? ' fs-task-nav__arrow--disabled' : ''; ?>" aria-hidden="true"><?php echo Icon::ChevronLeft->svg( 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div class="fs-task-nav__info">
							<span class="fs-task-nav__label">Предыдущее</span>
							<span class="fs-task-nav__title">
								<?php echo $nav_prev ? esc_html( $nav_prev->title ) : '&mdash;'; ?>
							</span>
						</div>
					<?php if ( $nav_prev ) : ?>
					</a>
					<?php else : ?>
					</div>
					<?php endif; ?>

					<?php // Центр кликабелен целиком: точки — маркер «списка», как в макете. ?>
					<?php if ( '' !== $archive_url ) : ?>
						<a href="<?php echo esc_url( $archive_url ); ?>" class="fs-task-nav__center">
							<span class="fs-task-nav__dots" aria-hidden="true"><i></i><i></i><i></i></span>
							<span class="fs-task-nav__all">Все задания</span>
						</a>
					<?php else : ?>
						<div class="fs-task-nav__center">
							<span class="fs-task-nav__dots" aria-hidden="true"><i></i><i></i><i></i></span>
							<span class="fs-task-nav__all fs-task-nav__all--plain">Все задания</span>
						</div>
					<?php endif; ?>

					<?php if ( $nav_next ) : ?>
					<a href="<?php echo esc_url( $nav_next->url ); ?>" class="fs-task-nav__side fs-task-nav__side--next" aria-label="Следующее задание">
					<?php else : ?>
					<div class="fs-task-nav__side fs-task-nav__side--next">
					<?php endif; ?>
						<span class="fs-task-nav__arrow<?php echo ! $nav_next ? ' fs-task-nav__arrow--disabled' : ''; ?>" aria-hidden="true"><?php echo Icon::ChevronRight->svg( 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div class="fs-task-nav__info fs-task-nav__info--right">
							<span class="fs-task-nav__label">Следующее</span>
							<span class="fs-task-nav__title">
								<?php echo $nav_next ? esc_html( $nav_next->title ) : '&mdash;'; ?>
							</span>
						</div>
					<?php if ( $nav_next ) : ?>
					</a>
					<?php else : ?>
					</div>
					<?php endif; ?>
				</nav>

				<!-- Карточка задания -->
				<div class="fs-task-card">
					<div class="fs-task-card__body">
						<!-- Теги -->
						<?php if ( ! empty( $tags ) ) : ?>
							<div class="fs-task-tags">
								<?php foreach ( $tags as $tag ) : ?>
									<?php
									// Цвет чипа закреплён за таксономией (TagPaletteService);
									// ступень палитры печатаем классом, значения — в SCSS.
									$class = 'fs-tag fs-tag--' . $tag->type;

									if ( $tag->color > 0 ) {
										$class .= ' fs-tag--c' . $tag->color;
									}
									?>
									<?php if ( '' !== $tag->url ) : ?>
										<a href="<?php echo esc_url( $tag->url ); ?>" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $tag->label ); ?></a>
									<?php else : ?>
										<span class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $tag->label ); ?></span>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<!-- Условие задачи -->
						<div class="fs-task-condition">
							<?php echo wp_kses_post( $content->condition ); ?>
						</div>

						<!-- Файлы -->
						<?php if ( ! empty( $files ) ) : ?>
							<div class="fs-task-files">
								<?php foreach ( $files as $file ) : ?>
									<a href="<?php echo esc_url( $file['url'] ); ?>" class="fs-file-link">
										<span class="fs-file-icon" aria-hidden="true">
											<?php echo Icon::File->svg( 17 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</span>
										<span class="fs-file-name"><?php echo esc_html( $file['name'] ); ?></span>
										<?php if ( ! empty( $file['size'] ) ) : ?>
											<span class="fs-file-size"><?php echo esc_html( $file['size'] ); ?></span>
										<?php endif; ?>
										<span class="fs-file-dl" aria-hidden="true">
											<?php echo Icon::Download->svg( 17 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</span>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

					</div>

					<!-- Табы -->
					<?php if ( ! empty( $tabs ) || '' !== $content->answer ) : ?>
						<?php ?>
						<div class="fs-task-tabs">
							<div class="fs-tabs-toolbar">
								<?php ?>
								<?php if ( '' !== $content->answer ) : ?>
									<button type="button" class="fs-answer-toggle js-answer-toggle"
										aria-expanded="false" aria-controls="fs-answer-<?php echo esc_attr( (string) ( $task_post?->id ?? 0 ) ); ?>">Показать ответ</button>
								<?php endif; ?>
								<?php if ( ! empty( $tabs ) ) : ?>
									<div class="fs-tabs-nav" role="tablist" aria-label="Материалы задания">
									<?php foreach ( $tabs as $tab ) : ?>
										<button
											type="button"
											id="fs-tab-<?php echo esc_attr( $tab->id ); ?>"
											class="fs-tab-btn"
											role="tab"
											aria-selected="false"
											aria-controls="fs-panel-<?php echo esc_attr( $tab->id ); ?>"
											data-tab="<?php echo esc_attr( $tab->id ); ?>">
											<?php echo esc_html( $tab->label ); ?>
										</button>
									<?php endforeach; ?>
								</div>
								<?php endif; ?>
							</div>
							<?php if ( '' !== $content->answer ) : ?>
								<div id="fs-answer-<?php echo esc_attr( (string) ( $task_post?->id ?? 0 ) ); ?>" class="fs-answer js-answer-panel" hidden>
									<div class="fs-answer-label">Правильный ответ:</div>
									<div class="fs-answer-value"><?php echo esc_html( $content->answer ); ?></div>
								</div>
							<?php endif; ?>

							<?php if ( ! empty( $tabs ) ) : ?>
							<div class="fs-tabs-content">
								<?php foreach ( $tabs as $tab ) : ?>
									<div
										id="fs-panel-<?php echo esc_attr( $tab->id ); ?>"
										class="fs-tab-panel"
										role="tabpanel"
										aria-labelledby="fs-tab-<?php echo esc_attr( $tab->id ); ?>"
										data-panel="<?php echo esc_attr( $tab->id ); ?>">
										<?php if ( $tab->is_code ) : ?>
											<?php ?>
											<pre><code class="js-code" data-lang="<?php echo esc_attr( $tab->lang ); ?>"><?php echo esc_html( $tab->content ); ?></code></pre>
										<?php else : ?>
											<?php echo wp_kses_post( $tab->content ); ?>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
							<?php endif; ?>
						</div>
					<?php endif; ?>

				</div>

			</main>

		</div>
	</div>

	<!-- Карусель рекомендуемых статей (общий партиал со страницей статьи) -->
	<?php if ( ! empty( $articles['recommended'] ) ) : ?>
		<hr class="fs-task-divider fs-carousel-divider">
		<?php
		$carousel_articles = $articles['recommended'];
		$carousel_title    = 'Рекомендуемые статьи';
		include __DIR__ . '/partials/articles-carousel.php';
		?>
	<?php endif; ?>

</div>

<?php ThemeCompatService::footer(); ?>