<?php
/**
 * Шаблон главной страницы Dashboard.
 *
 * @var array<int, array{
 *   id: string,
 *   title: string,
 *   description: string,
 *   enabled: bool,
 *   const_locked: bool,
 *   const_key: string
 * }> $modules Список модулей, зарегистрированных через фильтр fs_lms_dashboard_modules.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';
?>

<div class="wrap">
	<h1><?php echo esc_html__( 'Dashboard', 'fs-lms' ); ?></h1>

	<?php if ( ! empty( $modules ) ) : ?>
		<div class="fs-modules-section">
			<h2 class="fs-modules-section__title">Модули</h2>
			<div class="fs-module-cards">
				<?php foreach ( $modules as $module ) :
					$is_enabled     = (bool) ( $module['enabled'] ?? false );
					$const_locked   = (bool) ( $module['const_locked'] ?? false );
					$const_key      = esc_html( $module['const_key'] ?? '' );
					$module_id      = esc_attr( $module['id'] ?? '' );
				?>
					<div class="fs-module-card<?php echo $const_locked ? ' fs-module-card--locked' : ''; ?>"
						 data-module="<?php echo $module_id; ?>">

						<div class="fs-module-card__header">
							<h3 class="fs-module-card__title"><?php echo esc_html( $module['title'] ?? '' ); ?></h3>
							<?php if ( $const_locked ) : ?>
								<?php render_fs_badge( 'wp-config', 'blue' ); ?>
							<?php endif; ?>
						</div>

						<p class="fs-module-card__description">
							<?php echo esc_html( $module['description'] ?? '' ); ?>
						</p>

						<div class="fs-module-card__footer">
							<?php
							render_fs_toggle( 'module_' . $module_id, $is_enabled, array(
								'id'       => 'fs-module-toggle-' . $module_id,
								'class'    => 'js-module-toggle',
								'readonly' => $const_locked,
							) );
							?>
							<span class="fs-module-card__status"
								  id="fs-module-status-<?php echo $module_id; ?>">
								<?php if ( $const_locked ) : ?>
									Задан в <code><?php echo $const_key; ?></code>
								<?php endif; ?>
							</span>
						</div>

					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>
</div>
