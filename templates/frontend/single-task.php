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
$crumbs      = $navigation['breadcrumbs'] ?? array();
$archive_url = $navigation['archive_url'] ?? '';
$nav_prev    = $navigation['prev'] ?? null;
$nav_next    = $navigation['next'] ?? null;

ThemeCompatService::header();
?>

<div class="fs-page-wrapper">
	<div class="fs-page-shell">
		<div class="fs-task-page">

			<!-- ===================== ЛЕВЫЙ САЙДБАР ===================== -->
			<aside class="fs-task-sidebar">

				<div class="fs-sidebar-block">
					<div class="fs-sidebar-title">Курсы</div>
					<p class="fs-sidebar-stub">Скоро здесь появятся курсы</p>
					<a href="#" class="fs-sidebar-more">Узнать о курсах →</a>
				</div>

				<?php
				// Статьи по типу задания — общий с «Все задания» партиал сайдбара.
				$sidebar_articles     = $articles['related'];
				$sidebar_articles_url = $articles['archive_url'] ?? '';
				include __DIR__ . '/partials/sidebar-articles.php';
				?>

				<div class="fs-sidebar-block">
                    <div class="fs-sidebar-title">Реклама</div>
				</div>

			</aside>

			<!-- ===================== ОСНОВНОЙ КОНТЕНТ ===================== -->
			<main class="fs-task-main">

				<!-- Хлебные крошки (общий партиал со страницей «Все задания») -->
				<?php include __DIR__ . '/partials/breadcrumbs.php'; ?>

				<!-- Заголовок задания -->
				<h1 class="fs-task-title"><?php echo esc_html( $task_post?->title ?? '' ); ?></h1>

				<!-- Навигация: предыдущее / все задания / следующее -->
                <hr class="fs-task-divider">
				<nav class="fs-task-nav">
					<?php if ( $nav_prev ) : ?>
					<a href="<?php echo esc_url( $nav_prev['url'] ); ?>" class="fs-task-nav__side fs-task-nav__side--prev" aria-label="Предыдущее задание">
					<?php else : ?>
					<div class="fs-task-nav__side fs-task-nav__side--prev">
					<?php endif; ?>
						<span class="fs-task-nav__arrow<?php echo ! $nav_prev ? ' fs-task-nav__arrow--disabled' : ''; ?>" aria-hidden="true">&#8249;</span>
						<div class="fs-task-nav__info">
							<span class="fs-task-nav__label">Предыдущее</span>
							<span class="fs-task-nav__title">
								<?php echo $nav_prev ? esc_html( $nav_prev['title'] ) : '&mdash;'; ?>
							</span>
						</div>
					<?php if ( $nav_prev ) : ?>
					</a>
					<?php else : ?>
					</div>
					<?php endif; ?>

					<div class="fs-task-nav__center">
						<div class="fs-task-nav__circle"></div>
						<?php if ( '' !== $archive_url ) : ?>
							<a href="<?php echo esc_url( $archive_url ); ?>" class="fs-task-nav__all">Все задания</a>
						<?php else : ?>
							<span class="fs-task-nav__all fs-task-nav__all--plain">Все задания</span>
						<?php endif; ?>
					</div>

					<?php if ( $nav_next ) : ?>
					<a href="<?php echo esc_url( $nav_next['url'] ); ?>" class="fs-task-nav__side fs-task-nav__side--next" aria-label="Следующее задание">
					<?php else : ?>
					<div class="fs-task-nav__side fs-task-nav__side--next">
					<?php endif; ?>
						<span class="fs-task-nav__arrow<?php echo ! $nav_next ? ' fs-task-nav__arrow--disabled' : ''; ?>" aria-hidden="true">&#8250;</span>
						<div class="fs-task-nav__info fs-task-nav__info--right">
							<span class="fs-task-nav__label">Следующее</span>
							<span class="fs-task-nav__title">
								<?php echo $nav_next ? esc_html( $nav_next['title'] ) : '&mdash;'; ?>
							</span>
						</div>
					<?php if ( $nav_next ) : ?>
					</a>
					<?php else : ?>
					</div>
					<?php endif; ?>
					</nav>
					<hr class="fs-task-divider">

				<!-- Карточка задания -->
				<div class="fs-task-card">
					<div class="fs-task-card__body">
                        <!-- Теги -->
                        <?php if ( ! empty( $tags ) ) : ?>
                            <div class="fs-task-tags">
                                <?php foreach ( $tags as $tag ) : ?>
                                    <?php $class = 'fs-tag fs-tag--' . esc_attr( $tag['type'] ); ?>
                                    <?php if ( ! empty( $tag['url'] ) ) : ?>
                                        <a href="<?php echo esc_url( $tag['url'] ); ?>" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $tag['label'] ); ?></a>
                                    <?php else : ?>
                                        <span class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $tag['label'] ); ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

						<!-- Условие задачи -->
						<div class="fs-task-condition">
							<?php echo wp_kses_post( $content['condition'] ); ?>
						</div>

						<!-- Файлы -->
						<?php if ( ! empty( $files ) ) : ?>
							<div class="fs-task-files">
								<?php foreach ( $files as $file ) : ?>
									<a href="<?php echo esc_url( $file['url'] ); ?>" class="fs-file-link">
										<span class="fs-file-icon" aria-hidden="true">
                                            <?php echo Icon::File->svg( 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        </span>
                                        <span class="fs-file-name"><?php echo esc_html( $file['name'] ); ?></span>
                                        <?php if ( ! empty( $file['size'] ) ) : ?>
                                            <span class="fs-file-size"><?php echo esc_html( $file['size'] ); ?></span>
                                        <?php endif; ?>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

					</div>

					<!-- Табы -->
					<?php if ( ! empty( $tabs ) ) : ?>
						<div class="fs-task-tabs">
							<div class="fs-tabs-toolbar">
								<div class="fs-tabs-nav">
									<?php foreach ( $tabs as $i => $tab ) : ?>
										<button
											class="fs-tab-btn<?php echo 0 === $i ? ' is-active' : ''; ?>"
											data-tab="<?php echo esc_attr( $tab['id'] ); ?>">
											<?php echo esc_html( $tab['label'] ); ?>
										</button>
									<?php endforeach; ?>
								</div>
							</div>
							<div class="fs-tabs-content">
								<?php foreach ( $tabs as $i => $tab ) : ?>
									<div
										class="fs-tab-panel<?php echo 0 === $i ? ' is-active' : ''; ?>"
										data-panel="<?php echo esc_attr( $tab['id'] ); ?>">
										<?php echo wp_kses_post( $tab['content'] ); ?>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

				</div>

			</main>

		</div>
	</div>

	<!-- Карусель случайных статей -->
	<?php if ( ! empty( $articles['recommended'] ) ) : ?>
		<hr class="fs-task-divider fs-carousel-divider">
		<div class="fs-task-carousel">
			<div class="fs-carousel-header">
				<h3 class="fs-carousel-title">Рекомендуемые статьи</h3>
			</div>
			<button class="fs-carousel-btn fs-carousel-btn--prev" aria-label="Назад">&#8249;</button>

			<div class="fs-carousel-overflow">
				<div class="fs-carousel-track">
					<?php foreach ( $articles['recommended'] as $article ) : ?>
						<div class="fs-carousel-item">
							<a href="<?php echo esc_url( $article['url'] ); ?>">
								<?php if ( ! empty( $article['task_number'] ) ) : ?>
									<span class="fs-carousel-number">№<?php echo esc_html( $article['task_number'] ); ?></span>
								<?php endif; ?>
								<strong><?php echo esc_html( $article['title'] ); ?></strong>
								<?php if ( ! empty( $article['excerpt'] ) ) : ?>
									<p><?php echo esc_html( $article['excerpt'] ); ?></p>
								<?php endif; ?>
								<span class="fs-carousel-read">Читать →</span>
							</a>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<button class="fs-carousel-btn fs-carousel-btn--next" aria-label="Вперёд">&#8250;</button>
		</div>
	<?php endif; ?>

</div>

<?php \Inc\Services\Shared\ThemeCompatService::footer(); ?>
