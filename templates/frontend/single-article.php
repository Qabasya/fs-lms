<?php
/**
 * Страница статьи (CPT {subject}_articles).
 *
 * Данные — ArticlePageDTO из ArticleDataBuilder. Три колонки: курсы предмета,
 * текст статьи, оглавление. Крошки, сайдбар курсов и карусель статей — общие
 * партиалы со страницей задания.
 *
 * Контент уже прошёл `the_content` и пост-обработку в ArticleContentService:
 * у заголовков есть якоря, у врезок и листингов — классы. Экранировать его
 * повторно нельзя — это готовый HTML статьи.
 *
 * @var \Inc\DTO\Article\ArticlePageDTO $article_data
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Ui\Icon;
use Inc\Services\Shared\ThemeCompatService;

/** @var \Inc\DTO\Article\ArticlePageDTO $article_data */
$article_data = get_query_var( 'fs_article_data' );
$article_post = $article_data->post;
$content      = $article_data->content;
$headings     = $content->headings;
$courses      = $article_data->courses;
$navigation   = $article_data->navigation;

// Ни оглавления, ни курсов — колонки нет вовсе: текст занимает всю полосу.
$has_sidebar = ! empty( $headings ) || ! empty( $courses );

ThemeCompatService::header();
?>

<div class="fs-page-wrapper fs-article-page">
	<div class="shell">

		<div class="crumbs-row">
			<?php
			$crumbs = $article_data->breadcrumbs;
			include __DIR__ . '/partials/breadcrumbs.php';
			?>
		</div>

		<div class="layout<?php echo $has_sidebar ? '' : ' layout--solo'; ?>">

			<!-- ===================== САЙДБАР: СОДЕРЖАНИЕ + КУРСЫ ===================== -->
			<?php if ( $has_sidebar ) : ?>
				<aside class="fs-article-aside">

					<?php if ( ! empty( $headings ) ) : ?>
						<nav class="fs-article-toc js-article-toc" aria-label="Содержание статьи">
							<div class="fs-article-toc__head">
								<span class="fs-article-toc__title">Содержание</span>
								<button type="button" class="fs-article-toc__toggle js-article-toc-toggle"
									aria-expanded="true" aria-controls="fs-article-toc-body" aria-label="Свернуть">
									<?php echo Icon::ChevronDown->svg( 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</button>
							</div>

							<?php // Обёртка тела — ради анимации сворачивания (переход grid-template-rows). ?>
							<div class="fs-article-toc__body" id="fs-article-toc-body">
								<div class="fs-article-toc__body-inner">
									<div class="fs-article-toc__list">
										<?php foreach ( $headings as $heading ) : ?>
											<a class="fs-article-toc__link js-article-toc-link<?php echo 3 === $heading->level ? ' fs-article-toc__link--sub' : ''; ?>"
												href="#<?php echo esc_attr( $heading->id ); ?>">
												<?php echo esc_html( $heading->text ); ?>
											</a>
										<?php endforeach; ?>
									</div>

									<?php // Прогресс чтения — <progress>, а не div с шириной: JS меняет значение, а не стиль. ?>
									<div class="fs-article-toc__progress-row">
										<progress class="fs-article-toc__progress js-article-progress"
											max="100" value="0" aria-label="Прогресс чтения"></progress>
									</div>
								</div>
							</div>
						</nav>
					<?php endif; ?>

					<?php if ( ! empty( $courses ) ) : ?>
						<?php
						$sidebar_courses = $courses;
						include __DIR__ . '/partials/sidebar-courses.php';
						?>
					<?php endif; ?>

				</aside>
			<?php endif; ?>

			<!-- ===================== ТЕКСТ СТАТЬИ ===================== -->
			<main class="fs-article-main">
				<?php
				// Навигация по серии — над заголовком и под текстом. Один партиал
				// на обе копии: разметка длинная, дублировать её в шаблоне нельзя.
				$article_nav_modifier = '';
				include __DIR__ . '/partials/article-nav.php';
				?>

				<h1 class="fs-article-title"><?php echo esc_html( $article_post->title ); ?></h1>

				<?php
				// Контент статьи выводится сырым — ровно как это делает the_content()
				// в ядре: он уже прошёл фильтры и kses при сохранении записи. Второй
				// прогон через kses срезал бы служебные атрибуты пост-обработки
				// (data-lang листинга, aria-controls карточки задания).
				?>
				<div class="fs-article-prose js-article-prose">
					<?php echo $content->html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<?php
				// Нижняя копия: модификатор нужен ради отступа сверху — у текста
				// статьи снизу своего отступа нет.
				$article_nav_modifier = ' fs-task-nav--bottom';
				include __DIR__ . '/partials/article-nav.php';
				?>

			</main>

		</div>
	</div>

	<?php // Карусель рекомендуемых статей — тот же блок и то же место, что на странице задания: вне сетки, во всю ширину полосы. ?>
	<?php if ( ! empty( $article_data->recommended ) ) : ?>
		<hr class="fs-task-divider fs-carousel-divider">
		<?php
		$carousel_articles = $article_data->recommended;
		include __DIR__ . '/partials/articles-carousel.php';
		?>
	<?php endif; ?>

	<?php // Кнопка «наверх» — показывает и прячет components/scroll-top.js. ?>
	<button type="button" class="fs-to-top js-to-top" aria-label="Наверх" hidden>
		<?php echo Icon::ChevronDown->svg( 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</button>
</div>

<?php ThemeCompatService::footer(); ?>