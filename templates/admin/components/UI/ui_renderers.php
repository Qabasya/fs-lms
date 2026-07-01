<?php
function render_fs_toggle( $name, $value = false, $args = array() ) {
	$template_data = array(
		'name'  => $name,
		'value' => $value,
		'args'  => $args,
	);

	include __DIR__ . '/toggle.php';
}

function render_fs_badge( $text, $color = 'gray', $class = '' ) {
	$template_data = compact( 'text', 'color', 'class' );

	include __DIR__ . '/badge.php';
}

/**
 * Render a card primitive.
 *
 * @param array{
 *   modifier?: string,
 *   title?:    string,
 *   desc?:     string,
 *   actions?:  string,
 *   body?:     string|callable,
 *   footer?:   string|callable,
 *   class?:    string,
 * } $args
 */
function render_fs_card( array $args = [] ): void {
	$a = array_merge(
		[
			'modifier' => '',
			'title'    => '',
			'desc'     => '',
			'actions'  => '',
			'body'     => '',
			'footer'   => '',
			'class'    => '',
		],
		$args
	);

	$classes = 'fs-card';
	if ( $a['modifier'] ) {
		$classes .= ' fs-card' . $a['modifier'];
	}
	if ( $a['class'] ) {
		$classes .= ' ' . $a['class'];
	}
	?>
	<div class="<?php echo esc_attr( $classes ); ?>">
		<?php if ( $a['title'] || $a['actions'] ) : ?>
			<div class="fs-card__header">
				<div>
					<?php if ( $a['title'] ) : ?>
						<h3 class="fs-card__title"><?php echo esc_html( $a['title'] ); ?></h3>
					<?php endif; ?>
					<?php if ( $a['desc'] ) : ?>
						<p class="fs-card__desc"><?php echo esc_html( $a['desc'] ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( $a['actions'] ) : ?>
					<div class="fs-card__actions"><?php echo $a['actions']; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<div class="fs-card__body">
			<?php
			if ( is_callable( $a['body'] ) ) {
				( $a['body'] )();
			} else {
				echo $a['body']; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
		</div>
		<?php if ( $a['footer'] ) : ?>
			<div class="fs-card__footer">
				<?php
				if ( is_callable( $a['footer'] ) ) {
					( $a['footer'] )();
				} else {
					echo $a['footer']; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render a page header primitive.
 *
 * @param array{
 *   title?:   string,
 *   desc?:    string,
 *   actions?: string|callable,
 * } $args
 */
function render_fs_page_header( array $args = [] ): void {
	$a = array_merge(
		[
			'title'   => '',
			'desc'    => '',
			'actions' => '',
		],
		$args
	);
	?>
	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<?php if ( $a['title'] ) : ?>
				<h2 class="fs-page-header__title"><?php echo esc_html( $a['title'] ); ?></h2>
			<?php endif; ?>
			<?php if ( $a['desc'] ) : ?>
				<p class="fs-page-header__desc"><?php echo esc_html( $a['desc'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( $a['actions'] ) : ?>
			<div class="fs-page-header__actions">
				<?php
				if ( is_callable( $a['actions'] ) ) {
					( $a['actions'] )();
				} else {
					echo $a['actions']; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render a filter <select> for WP admin list-table filter bars (restrict_manage_posts).
 *
 * @param array{
 *   name:       string,
 *   options:    array<string|int, string>,
 *   selected?:  string|int,
 *   all_label?: string,
 * } $args
 */
function render_fs_select( array $args ): void {
	$a = array_merge( [
		'name'      => '',
		'options'   => [],
		'selected'  => '',
		'all_label' => '',
	], $args );

	printf( '<select name="%s">', esc_attr( $a['name'] ) );
	if ( '' !== (string) $a['all_label'] ) {
		echo '<option value="">' . esc_html( $a['all_label'] ) . '</option>';
	}
	foreach ( $a['options'] as $value => $label ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( (string) $value ),
			selected( (string) $a['selected'], (string) $value, false ),
			esc_html( $label )
		);
	}
	echo '</select>';
}

/**
 * Render an empty-state primitive.
 *
 * @param array{
 *   icon?:   string,
 *   title?:  string,
 *   desc?:   string,
 *   action?: string|callable,
 * } $args
 */
function render_fs_empty( array $args = [] ): void {
	$a = array_merge(
		[
			'icon'   => 'dashicons-database',
			'title'  => '',
			'desc'   => '',
			'action' => '',
		],
		$args
	);
	?>
	<div class="fs-empty">
		<?php if ( $a['icon'] ) : ?>
			<span class="fs-empty__icon dashicons <?php echo esc_attr( $a['icon'] ); ?>"></span>
		<?php endif; ?>
		<?php if ( $a['title'] ) : ?>
			<p class="fs-empty__title"><?php echo esc_html( $a['title'] ); ?></p>
		<?php endif; ?>
		<?php if ( $a['desc'] ) : ?>
			<p class="fs-empty__desc"><?php echo esc_html( $a['desc'] ); ?></p>
		<?php endif; ?>
		<?php if ( $a['action'] ) : ?>
			<div class="fs-empty__action">
				<?php
				if ( is_callable( $a['action'] ) ) {
					( $a['action'] )();
				} else {
					echo $a['action']; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
