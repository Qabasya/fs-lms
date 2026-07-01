<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';
?>

<div id="tab-2" class="tab-pane active">
<div class="fs-lms-auth-providers">

	<h1 class="wp-heading-inline">Настройка авторизации</h1>
	<p class="description">Подключите внешние сервисы, чтобы пользователи могли входить в систему через свои аккаунты.</p>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'fs_lms_auth_group' );
		$options = get_option( 'fs_lms_auth_settings', array() );
		?>

		<div class="fs-lms-auth-cards">

			<div class="fs-card">
				<div class="fs-card__header">
					<div class="fs-card__info">
						<span class="fs-lms-auth-icon fs-lms-auth-icon--google"></span>
						<h3 class="fs-card__title">Google Auth</h3>
					</div>
					<div class="fs-card__actions">
						<a href="#" class="fs-config-help-link js-open-help-modal" data-provider="google">
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

				<div class="fs-card__body auth-fields-google <?php echo empty( $options['google_enabled'] ) ? 'hidden' : ''; ?>">
					<p class="fs-card__desc">Авторизация через аккаунт Google</p>
					<div class="fs-field">
						<label class="fs-field__label" for="google_id">Client ID</label>
						<div class="fs-field__control">
							<input name="fs_lms_auth_settings[google_id]" type="text" id="google_id"
									value="<?php echo esc_attr( $options['google_id'] ?? '' ); ?>" class="regular-text">
						</div>
					</div>
					<div class="fs-field">
						<label class="fs-field__label" for="google_secret">Client Secret</label>
						<div class="fs-field__control">
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
			</div>

			<div class="fs-card">
				<div class="fs-card__header">
					<div class="fs-card__info">
						<span class="fs-lms-auth-icon fs-lms-auth-icon--vk"></span>
						<h3 class="fs-card__title">ВКонтакте</h3>
					</div>
					<div class="fs-card__actions">
						<a href="#" class="fs-config-help-link js-open-help-modal" data-provider="vk">
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

				<div class="fs-card__body auth-fields-vk <?php echo empty( $options['vk_enabled'] ) ? 'hidden' : ''; ?>">
					<p class="fs-card__desc">Авторизация через аккаунт ВКонтакте</p>
					<div class="fs-field">
						<label class="fs-field__label" for="vk_id">VK App ID</label>
						<div class="fs-field__control">
							<input name="fs_lms_auth_settings[vk_id]" type="text" id="vk_id"
									value="<?php echo esc_attr( $options['vk_id'] ?? '' ); ?>" class="regular-text">
						</div>
					</div>
					<div class="fs-field">
						<label class="fs-field__label" for="vk_secret">VK App Secret</label>
						<div class="fs-field__control">
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
			</div>

			<div class="fs-card">
				<div class="fs-card__header">
					<div class="fs-card__info">
						<span class="fs-lms-auth-icon fs-lms-auth-icon--github"></span>
						<h3 class="fs-card__title">GitHub</h3>
					</div>
					<div class="fs-card__actions">
						<a href="#" class="fs-config-help-link js-open-help-modal" data-provider="github">
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

				<div class="fs-card__body auth-fields-github <?php echo empty( $options['github_enabled'] ) ? 'hidden' : ''; ?>">
					<p class="fs-card__desc">Авторизация через аккаунт GitHub</p>
					<div class="fs-field">
						<label class="fs-field__label" for="github_id">Client ID</label>
						<div class="fs-field__control">
							<input name="fs_lms_auth_settings[github_id]" type="text" id="github_id"
									value="<?php echo esc_attr( $options['github_id'] ?? '' ); ?>" class="regular-text">
						</div>
					</div>
					<div class="fs-field">
						<label class="fs-field__label" for="github_secret">Client Secret</label>
						<div class="fs-field__control">
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

		</div>

		<div class="fs-lms-auth-footer">
			<input type="submit" name="submit" id="submit" class="button button-primary button-large" value="Сохранить изменения">
			<span class="fs-lms-auth-footer__notice">Не забудьте сохранить изменения, чтобы они вступили в силу.</span>
		</div>
	</form>

</div>
</div>

<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/help-modal.php'; ?>
