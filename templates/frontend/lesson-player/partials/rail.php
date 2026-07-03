<?php
/**
 * Рейка-дерево курса (T14.4, D18): slim-полоска 64px (уроки текущего модуля)
 * + разворот 316px по hover/pin (шапка курса с прогрессом, модули → уроки).
 * Данные — $view['tree'] (CourseNavService::tree, T14.3); шаги текущего урока
 * дорисовывает rail.js из панелей плеера (единый источник иконок и статусов).
 *
 * @var array $view    {tree, shell, steps, …}
 * @var int   $groupId
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Wp\PageRoutes;

$rail_tree     = is_array( $view['tree'] ?? null ) ? $view['tree'] : array( 'modules' => array() );
$rail_modules  = $rail_tree['modules'];
$rail_progress = is_array( $view['shell']['course_progress'] ?? null ) ? $view['shell']['course_progress'] : null;
$rail_course   = (string) ( $view['shell']['course_title'] ?? '' );

$rail_current_module = null;
foreach ( $rail_modules as $rail_module ) {
	if ( 'current' === $rail_module['state'] ) {
		$rail_current_module = $rail_module;
		break;
	}
}

$rail_lesson_url = static fn( int $gl ): string => add_query_arg(
	array(
		'gid' => $groupId,
		'gl'  => $gl,
	),
	PageRoutes::GroupCockpit->url()
);

$rail_check = '<svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M4 10.5 8 14l8-8.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$rail_lock  = '<svg width="13" height="13" viewBox="0 0 20 20" fill="none"><rect x="4.5" y="8.5" width="11" height="8" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M7 8.5V6.5a3 3 0 0 1 6 0v2" stroke="currentColor" stroke-width="1.5"/></svg>';
$rail_chevr = '<svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M8 4.5 13.5 10 8 15.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$rail_chevd = '<svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M4.5 8 10 13.5 15.5 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
?>
<!-- Slim-полоска: уроки текущего модуля -->
<div class="rail-slim">
	<span class="rs-x" role="button" tabindex="0" title="<?php esc_attr_e( 'Развернуть структуру курса', 'fs-lms' ); ?>"><?php echo $rail_chevr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	<span class="rs-sep"></span>
	<?php foreach ( ( $rail_current_module['lessons'] ?? array() ) as $rail_node ) : ?>
		<?php
		$rail_cls   = match ( $rail_node['state'] ) {
			'done'    => ' done',
			'current' => ' cur',
			'locked'  => ' lk',
			default   => '',
		};
		$rail_title = sprintf(
			/* translators: 1: lesson number, 2: lesson title */
			__( 'Урок %1$d. %2$s', 'fs-lms' ),
			$rail_node['number'],
			$rail_node['title']
		);
		?>
		<?php if ( 'current' === $rail_node['state'] || 'locked' === $rail_node['state'] ) : ?>
			<span class="rs-les<?php echo esc_attr( $rail_cls ); ?>"
				<?php echo 'locked' === $rail_node['state'] ? 'data-toast="' . esc_attr__( 'Урок откроется позже', 'fs-lms' ) . '"' : ''; ?>
				title="<?php echo esc_attr( $rail_title ); ?>">
				<?php echo esc_html( (string) $rail_node['number'] ); ?>
			</span>
		<?php else : ?>
			<a class="rs-les<?php echo esc_attr( $rail_cls ); ?>"
				href="<?php echo esc_url( $rail_lesson_url( $rail_node['group_lesson_id'] ) ); ?>"
				title="<?php echo esc_attr( $rail_title ); ?>">
				<?php echo 'done' === $rail_node['state'] ? $rail_check : esc_html( (string) $rail_node['number'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
		<?php endif; ?>
	<?php endforeach; ?>
</div>

<!-- Полный разворот: шапка курса + модули → уроки (+ шаги текущего урока из rail.js) -->
<div class="rail-full">
	<div class="rf-h">
		<div class="rf-t">
			<b><?php echo esc_html( $rail_course ); ?></b>
			<?php if ( null !== $rail_progress ) : ?>
				<div class="bar"><span data-width="<?php echo esc_attr( (string) (int) $rail_progress['percent'] ); ?>"></span></div>
				<div class="pct">
					<?php
					printf(
						/* translators: 1: percent, 2: done lessons, 3: total lessons */
						esc_html__( 'Пройдено %1$d%% · %2$d из %3$d уроков', 'fs-lms' ),
						(int) $rail_progress['percent'],
						(int) $rail_progress['done'],
						(int) $rail_progress['total']
					);
					?>
				</div>
			<?php endif; ?>
		</div>
		<button type="button" class="rf-pin" id="fsRailPin" title="<?php esc_attr_e( 'Закрепить панель', 'fs-lms' ); ?>">
			<svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M8 3h4l.6 5.2 2.4 2.3v1.5H5v-1.5l2.4-2.3L8 3zM10 12v5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
		</button>
	</div>
	<div class="rf-list">
		<?php foreach ( $rail_modules as $rail_module ) : ?>
			<?php $rail_is_cur_mod = 'current' === $rail_module['state']; ?>
			<div class="t-mod<?php echo $rail_is_cur_mod ? '' : ' dim'; ?>">
				<span class="tmi <?php echo esc_attr( match ( $rail_module['state'] ) { 'done' => 'ok', 'current' => 'cur', 'locked' => 'lk', default => '' } ); ?>">
					<?php
					if ( 'done' === $rail_module['state'] ) {
						echo $rail_check; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					} elseif ( 'locked' === $rail_module['state'] ) {
						echo $rail_lock; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					} else {
						echo esc_html( (string) ( $rail_module['number'] ?? '·' ) );
					}
					?>
				</span>
				<?php echo esc_html( $rail_module['title'] ); ?>
				<span class="chev"><?php echo $rail_is_cur_mod ? $rail_chevd : $rail_chevr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</div>

			<?php if ( $rail_is_cur_mod ) : ?>
				<?php foreach ( $rail_module['lessons'] as $rail_node ) : ?>
					<?php
					$rail_title = sprintf(
						/* translators: 1: lesson number, 2: lesson title */
						__( 'Урок %1$d. %2$s', 'fs-lms' ),
						$rail_node['number'],
						$rail_node['title']
					);
					?>
					<?php if ( 'current' === $rail_node['state'] ) : ?>
						<div class="t-les cur"><?php echo esc_html( $rail_title ); ?></div>
						<div id="fsRailSteps"></div>
					<?php elseif ( 'locked' === $rail_node['state'] ) : ?>
						<div class="t-les dim" data-toast="<?php esc_attr_e( 'Урок откроется позже', 'fs-lms' ); ?>">
							<?php echo esc_html( $rail_title ); ?>
							<span class="ls"><?php echo $rail_lock; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</div>
					<?php else : ?>
						<a class="t-les<?php echo 'done' === $rail_node['state'] ? ' dim' : ''; ?>"
							href="<?php echo esc_url( $rail_lesson_url( $rail_node['group_lesson_id'] ) ); ?>">
							<?php echo esc_html( $rail_title ); ?>
							<?php if ( 'done' === $rail_node['state'] ) : ?>
								<span class="ls ok"><?php echo $rail_check; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<?php endif; ?>
						</a>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
</div>
