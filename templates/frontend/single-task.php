<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = get_the_ID();

$container = new \Inc\Core\Container();
$callbacks = $container->get( \Inc\Callbacks\TemplateCallbacks::class );
$task_data = $callbacks->getTaskData( $post_id );

$post_data   = $task_data['post'];
$subject     = $task_data['subject'];
$content     = $task_data['content'];
$files       = $task_data['files'];
$tags        = $task_data['tags'];
$articles    = $task_data['articles'];
$navigation  = $task_data['navigation'];
$breadcrumbs = $navigation['breadcrumbs'];
$nav_prev    = $navigation['prev'] ?? null;
$nav_next    = $navigation['next'] ?? null;

$tabs = array();

if ( ! empty( $content['answer'] ) ) {
	$tabs[] = array(
		'id'      => 'answer',
		'label'   => 'Ответ',
		'content' => $content['answer'],
	);
}
if ( ! empty( $content['code'] ) ) {
	$tabs[] = array(
		'id'      => 'code',
		'label'   => 'Python',
		'content' => $content['code'],
	);
}
if ( ! empty( $content['text'] ) ) {
	$tabs[] = array(
		'id'      => 'text',
		'label'   => 'Пояснение',
		'content' => $content['text'],
	);
}

\Inc\Services\ThemeCompatService::header();
?>

<div class="fs-task-page">

	<!-- ===================== ЛЕВЫЙ САЙДБАР ===================== -->
	<aside class="fs-task-sidebar">

		<div class="fs-sidebar-block">
			<h3 class="fs-sidebar-title">Курсы</h3>
			<p class="fs-sidebar-stub">Скоро здесь появятся курсы</p>
		</div>

		<?php if ( ! empty( $articles['related'] ) ) : ?>
			<div class="fs-sidebar-block">
				<h3 class="fs-sidebar-title">Статьи</h3>
				<ul class="fs-sidebar-articles">
					<?php foreach ( array_slice( $articles['related'], 0, 4 ) as $article ) : ?>
						<li>
							<a href="<?php echo esc_url( $article['url'] ); ?>">
								<?php echo esc_html( $article['title'] ); ?>
							</a>
							<?php if ( ! empty( $article['excerpt'] ) ) : ?>
								<p><?php echo esc_html( $article['excerpt'] ); ?></p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="fs-sidebar-block">
			<p class="fs-sidebar-stub">Реклама</p>
		</div>

	</aside>

	<!-- ===================== ОСНОВНОЙ КОНТЕНТ ===================== -->
	<main class="fs-task-main">

		<!-- Хлебные крошки -->
		<nav class="fs-breadcrumbs">
			<?php if ( ! empty( $breadcrumbs['subject']['label'] ) ) : ?>
				<?php if ( ! empty( $breadcrumbs['subject']['url'] ) ) : ?>
					<a href="<?php echo esc_url( $breadcrumbs['subject']['url'] ); ?>"><?php echo esc_html( $breadcrumbs['subject']['label'] ); ?></a>
				<?php else : ?>
					<span><?php echo esc_html( $breadcrumbs['subject']['label'] ); ?></span>
				<?php endif; ?>
				<span class="fs-breadcrumbs__sep">/</span>
			<?php endif; ?>

			<?php if ( ! empty( $breadcrumbs['trainer']['label'] ) ) : ?>
				<?php if ( ! empty( $breadcrumbs['trainer']['url'] ) ) : ?>
					<a href="<?php echo esc_url( $breadcrumbs['trainer']['url'] ); ?>"><?php echo esc_html( $breadcrumbs['trainer']['label'] ); ?></a>
				<?php else : ?>
					<span><?php echo esc_html( $breadcrumbs['trainer']['label'] ); ?></span>
				<?php endif; ?>
				<span class="fs-breadcrumbs__sep">/</span>
			<?php endif; ?>

			<?php if ( ! empty( $breadcrumbs['task_type']['label'] ) ) : ?>
				<?php if ( ! empty( $breadcrumbs['task_type']['url'] ) ) : ?>
					<a href="<?php echo esc_url( $breadcrumbs['task_type']['url'] ); ?>"><?php echo esc_html( $breadcrumbs['task_type']['label'] ); ?></a>
				<?php else : ?>
					<span><?php echo esc_html( $breadcrumbs['task_type']['label'] ); ?></span>
				<?php endif; ?>
				<span class="fs-breadcrumbs__sep">/</span>
			<?php endif; ?>

			<?php if ( ! empty( $breadcrumbs['task']['label'] ) ) : ?>
				<span class="fs-breadcrumbs__current"><?php echo esc_html( $breadcrumbs['task']['label'] ); ?></span>
			<?php endif; ?>
		</nav>

		<!-- Заголовок задания -->
		<h1 class="fs-task-title"><?php echo esc_html( $post_data['title'] ); ?></h1>

		<!-- Навигация: предыдущее / все задания / следующее -->
		<hr class="fs-task-divider">
		<nav class="fs-task-nav">
			<div class="fs-task-nav__side fs-task-nav__side--prev">
				<?php if ( $nav_prev ) : ?>
					<a href="<?php echo esc_url( $nav_prev['url'] ); ?>" class="fs-task-nav__arrow" aria-label="Предыдущее">&#8249;</a>
				<?php else : ?>
					<span class="fs-task-nav__arrow fs-task-nav__arrow--disabled" aria-hidden="true">&#8249;</span>
				<?php endif; ?>
				<div class="fs-task-nav__info">
					<span class="fs-task-nav__label">Предыдущее</span>
					<span class="fs-task-nav__title">
						<?php if ( $nav_prev ) : ?>
							<a href="<?php echo esc_url( $nav_prev['url'] ); ?>"><?php echo esc_html( $nav_prev['title'] ); ?></a>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</span>
				</div>
			</div>

			<div class="fs-task-nav__center">
				<div class="fs-task-nav__circle"></div>
				<?php if ( ! empty( $breadcrumbs['task_type']['url'] ) ) : ?>
					<a href="<?php echo esc_url( $breadcrumbs['task_type']['url'] ); ?>" class="fs-task-nav__all">Все задания</a>
				<?php elseif ( ! empty( $breadcrumbs['subject']['url'] ) ) : ?>
					<a href="<?php echo esc_url( $breadcrumbs['subject']['url'] ); ?>" class="fs-task-nav__all">Все задания</a>
				<?php else : ?>
					<span class="fs-task-nav__all fs-task-nav__all--plain">Все задания</span>
				<?php endif; ?>
			</div>

			<div class="fs-task-nav__side fs-task-nav__side--next">
				<div class="fs-task-nav__info fs-task-nav__info--right">
					<span class="fs-task-nav__label">Следующее</span>
					<span class="fs-task-nav__title">
						<?php if ( $nav_next ) : ?>
							<a href="<?php echo esc_url( $nav_next['url'] ); ?>"><?php echo esc_html( $nav_next['title'] ); ?></a>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</span>
				</div>
				<?php if ( $nav_next ) : ?>
					<a href="<?php echo esc_url( $nav_next['url'] ); ?>" class="fs-task-nav__arrow" aria-label="Следующее">&#8250;</a>
				<?php else : ?>
					<span class="fs-task-nav__arrow fs-task-nav__arrow--disabled" aria-hidden="true">&#8250;</span>
				<?php endif; ?>
			</div>
		</nav>
		<hr class="fs-task-divider">

		<!-- Теги -->
		<?php if ( ! empty( $tags ) ) : ?>
			<div class="fs-task-tags">
				<?php foreach ( $tags as $tag ) : ?>
					<?php $class = 'fs-tag fs-tag--' . esc_attr( $tag['type'] ); ?>
					<?php if ( ! empty( $tag['url'] ) ) : ?>
						<a href="<?php echo esc_url( $tag['url'] ); ?>" class="<?php echo $class; ?>"><?php echo esc_html( $tag['label'] ); ?></a>
					<?php else : ?>
						<span class="<?php echo $class; ?>"><?php echo esc_html( $tag['label'] ); ?></span>
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
				<strong>Файлы к заданию:</strong>
				<?php foreach ( $files as $file ) : ?>
					<a href="<?php echo esc_url( $file['url'] ); ?>" class="fs-file-link">
						<?php echo esc_html( $file['name'] ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<!-- Табы -->
		<?php if ( ! empty( $tabs ) ) : ?>
			<div class="fs-task-tabs">
				<div class="fs-tabs-nav">
					<?php foreach ( $tabs as $i => $tab ) : ?>
						<button
							class="fs-tab-btn<?php echo $i === 0 ? ' is-active' : ''; ?>"
							data-tab="<?php echo esc_attr( $tab['id'] ); ?>">
							<?php echo esc_html( $tab['label'] ); ?>
						</button>
					<?php endforeach; ?>
				</div>
				<div class="fs-tabs-content">
					<?php foreach ( $tabs as $i => $tab ) : ?>
						<div
							class="fs-tab-panel<?php echo $i === 0 ? ' is-active' : ''; ?>"
							data-panel="<?php echo esc_attr( $tab['id'] ); ?>">
							<?php echo wp_kses_post( $tab['content'] ); ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

	</main>

</div>

<!-- Карусель случайных статей -->
<?php if ( ! empty( $articles['random'] ) ) : ?>
	<div class="fs-task-carousel">
		<button class="fs-carousel-btn fs-carousel-btn--prev" aria-label="Назад">&#8249;</button>

		<div class="fs-carousel-overflow">
			<div class="fs-carousel-track">
				<?php foreach ( array_slice( $articles['random'], 0, 6 ) as $article ) : ?>
					<div class="fs-carousel-item">
						<a href="<?php echo esc_url( $article['url'] ); ?>">
							<strong><?php echo esc_html( $article['title'] ); ?></strong>
							<?php if ( ! empty( $article['excerpt'] ) ) : ?>
								<p><?php echo esc_html( $article['excerpt'] ); ?></p>
							<?php endif; ?>
						</a>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<button class="fs-carousel-btn fs-carousel-btn--next" aria-label="Вперёд">&#8250;</button>
	</div>
<?php endif; ?>

<?php \Inc\Services\ThemeCompatService::footer(); ?>