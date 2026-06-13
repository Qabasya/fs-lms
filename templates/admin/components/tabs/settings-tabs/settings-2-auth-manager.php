<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';
?>

<div id="tab-2" class="tab-pane active fs-lms-auth-settings">
	<h1 class="wp-heading-inline">Настройка авторизации</h1>
	<p class="description">Подключите внешние сервисы, чтобы пользователи могли входить в систему через свои аккаунты.</p>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'fs_lms_auth_group' );
		$options = get_option( 'fs_lms_auth_settings', array() );
		?>

		<div class="fs-lms-auth-providers">

			<div class="fs-lms-auth-card">
				<div class="fs-lms-auth-card__header">
					<div class="fs-lms-auth-card__info">
						<span class="fs-lms-auth-card__icon fs-lms-auth-card__icon--google"></span>
						<div class="fs-lms-auth-card__title-group">
							<h3 class="fs-lms-auth-card__title">Google Auth</h3>
							<p class="fs-lms-auth-card__desc">Авторизация через аккаунт Google</p>
						</div>
					</div>
					<div class="fs-lms-auth-card__actions">
						<a href="#" class="fs-lms-auth-card__link js-open-help-modal" data-provider="google">
							Как подключить? <span class="dashicons dashicons-external"></span>
						</a>
						<?php
						render_fs_toggle(
							'fs_lms_auth_settings[google_enabled]',
							! empty( $options['google_enabled'] ),
							array(
								'class' => 'js-provider-toggle',
								'id'    => 'google_toggle',
								'args'  => array( 'data-provider' => 'google' ),
							)
						);
						?>
					</div>
				</div>

				<div class="fs-lms-auth-card__body auth-fields-google <?php echo empty( $options['google_enabled'] ) ? 'hidden' : ''; ?>">
					<div class="fs-lms-form-group">
						<label for="google_id">Client ID</label>
						<input name="fs_lms_auth_settings[google_id]" type="text" id="google_id"
								value="<?php echo esc_attr( $options['google_id'] ?? '' ); ?>" class="regular-text">
					</div>
					<div class="fs-lms-form-group">
						<label for="google_secret">Client Secret</label>
						<div class="fs-lms-secret-field">
							<input name="fs_lms_auth_settings[google_secret]" type="password" id="google_secret"
									value="<?php echo esc_attr( $options['google_secret'] ?? '' ); ?>" class="regular-text">
							<button type="button" class="fs-lms-toggle-secret js-toggle-secret" aria-label="Показать секретный ключ">
								<span class="dashicons dashicons-visibility"></span>
							</button>
						</div>
					</div>
				</div>
			</div>

			<div class="fs-lms-auth-card">
				<div class="fs-lms-auth-card__header">
					<div class="fs-lms-auth-card__info">
						<span class="fs-lms-auth-card__icon fs-lms-auth-card__icon--vk"></span>
						<div class="fs-lms-auth-card__title-group">
							<h3 class="fs-lms-auth-card__title">ВКонтакте</h3>
							<p class="fs-lms-auth-card__desc">Авторизация через аккаунт ВКонтакте</p>
						</div>
					</div>
					<div class="fs-lms-auth-card__actions">
						<a href="#" class="fs-lms-auth-card__link js-open-help-modal" data-provider="vk">
							Как подключить? <span class="dashicons dashicons-external"></span>
						</a>
						<?php
						render_fs_toggle(
							'fs_lms_auth_settings[vk_enabled]',
							! empty( $options['vk_enabled'] ),
							array(
								'class' => 'js-provider-toggle',
								'id'    => 'vk_toggle',
								'args'  => array( 'data-provider' => 'vk' ),
							)
						);
						?>
					</div>
				</div>

				<div class="fs-lms-auth-card__body auth-fields-vk <?php echo empty( $options['vk_enabled'] ) ? 'hidden' : ''; ?>">
					<div class="fs-lms-form-group">
						<label for="vk_id">VK App ID</label>
						<input name="fs_lms_auth_settings[vk_id]" type="text" id="vk_id"
								value="<?php echo esc_attr( $options['vk_id'] ?? '' ); ?>" class="regular-text">
					</div>
					<div class="fs-lms-form-group">
						<label for="vk_secret">VK App Secret</label>
						<div class="fs-lms-secret-field">
							<input name="fs_lms_auth_settings[vk_secret]" type="password" id="vk_secret"
									value="<?php echo esc_attr( $options['vk_secret'] ?? '' ); ?>" class="regular-text">
							<button type="button" class="fs-lms-toggle-secret js-toggle-secret" aria-label="Показать секретный ключ">
								<span class="dashicons dashicons-visibility"></span>
							</button>
						</div>
					</div>
				</div>
			</div>

			<div class="fs-lms-auth-card">
				<div class="fs-lms-auth-card__header">
					<div class="fs-lms-auth-card__info">
						<span class="fs-lms-auth-card__icon fs-lms-auth-card__icon--github"></span>
						<div class="fs-lms-auth-card__title-group">
							<h3 class="fs-lms-auth-card__title">GitHub</h3>
							<p class="fs-lms-auth-card__desc">Авторизация через аккаунт GitHub</p>
						</div>
					</div>
					<div class="fs-lms-auth-card__actions">
						<a href="#" class="fs-lms-auth-card__link js-open-help-modal" data-provider="github">
							Как подключить? <span class="dashicons dashicons-external"></span>
						</a>
						<?php
						render_fs_toggle(
							'fs_lms_auth_settings[github_enabled]',
							! empty( $options['github_enabled'] ),
							array(
								'class' => 'js-provider-toggle',
								'id'    => 'github_toggle',
								'args'  => array( 'data-provider' => 'github' ),
							)
						);
						?>
					</div>
				</div>

				<div class="fs-lms-auth-card__body auth-fields-github <?php echo empty( $options['github_enabled'] ) ? 'hidden' : ''; ?>">
					<div class="fs-lms-form-group">
						<label for="github_id">Client ID</label>
						<input name="fs_lms_auth_settings[github_id]" type="text" id="github_id"
								value="<?php echo esc_attr( $options['github_id'] ?? '' ); ?>" class="regular-text">
					</div>
					<div class="fs-lms-form-group">
						<label for="github_secret">Client Secret</label>
						<div class="fs-lms-secret-field">
							<input name="fs_lms_auth_settings[github_secret]" type="password" id="github_secret"
									value="<?php echo esc_attr( $options['github_secret'] ?? '' ); ?>" class="regular-text">
							<button type="button" class="fs-lms-toggle-secret js-toggle-secret" aria-label="Показать секретный ключ">
								<span class="dashicons dashicons-visibility"></span>
							</button>
						</div>
					</div>
				</div>
			</div>

		</div>

		<div class="fs-lms-auth-footer">
			<input type="submit" name="submit" id="submit" class="button button-primary button-large" value="Сохранить изменения">
			<span class="fs-lms-auth-footer__notice">Не забудьте сохранить изменения, чтобы они вступили в силу.</span>
		</div>
	</form>
</div>

<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/help-modal.php'; ?>