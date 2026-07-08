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
						<span class="fs-lms-auth-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="36px" height="36px" viewBox="0 0 36 36" version="1.1">
                                <g id="surface1">
                                    <path style=" stroke:none;fill-rule:nonzero;fill:rgb(25.882354%,52.156866%,95.686275%);fill-opacity:1;" d="M 33.839844 18.375 C 33.839844 17.203125 33.734375 16.078125 33.539062 15 L 18 15 L 18 21.390625 L 26.878906 21.390625 C 26.488281 23.445312 25.320312 25.183594 23.566406 26.355469 L 23.566406 30.511719 L 28.921875 30.511719 C 32.039062 27.628906 33.839844 23.398438 33.839844 18.375 Z M 33.839844 18.375 "/>
                                    <path style=" stroke:none;fill-rule:nonzero;fill:rgb(20.392157%,65.882355%,32.549021%);fill-opacity:1;" d="M 18 34.5 C 22.453125 34.5 26.191406 33.03125 28.921875 30.511719 L 23.566406 26.355469 C 22.09375 27.34375 20.21875 27.945312 18 27.945312 C 13.710938 27.945312 10.066406 25.050781 8.761719 21.148438 L 3.269531 21.148438 L 3.269531 25.410156 C 5.984375 30.796875 11.550781 34.5 18 34.5 Z M 18 34.5 "/>
                                    <path style=" stroke:none;fill-rule:nonzero;fill:rgb(98.431373%,73.725492%,1.960784%);fill-opacity:1;" d="M 8.761719 21.136719 C 8.429688 20.144531 8.234375 19.09375 8.234375 18 C 8.234375 16.90625 8.429688 15.855469 8.761719 14.863281 L 8.761719 10.605469 L 3.269531 10.605469 C 2.144531 12.824219 1.5 15.328125 1.5 18 C 1.5 20.671875 2.144531 23.175781 3.269531 25.394531 L 7.546875 22.066406 Z M 8.761719 21.136719 "/>
                                    <path style=" stroke:none;fill-rule:nonzero;fill:rgb(91.764706%,26.274511%,20.784314%);fill-opacity:1;" d="M 18 8.070312 C 20.429688 8.070312 22.589844 8.910156 24.316406 10.53125 L 29.039062 5.804688 C 26.175781 3.136719 22.453125 1.5 18 1.5 C 11.550781 1.5 5.984375 5.203125 3.269531 10.605469 L 8.761719 14.863281 C 10.066406 10.964844 13.710938 8.070312 18 8.070312 Z M 18 8.070312 "/>
                                </g>
                            </svg>
                        </span>
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
						<span class="fs-lms-auth-icon">

                            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="36px" height="36px" viewBox="0 0 36 36" version="1.1">
                                <g id="surface1">
                                    <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,46.666667%,100%);fill-opacity:1;" d="M 0 17.28125 C 0 9.132812 0 5.0625 2.53125 2.53125 C 5.0625 0 9.132812 0 17.28125 0 L 18.71875 0 C 26.867188 0 30.9375 0 33.46875 2.53125 C 36 5.0625 36 9.132812 36 17.28125 L 36 18.71875 C 36 26.867188 36 30.9375 33.46875 33.46875 C 30.9375 36 26.867188 36 18.71875 36 L 17.28125 36 C 9.132812 36 5.0625 36 2.53125 33.46875 C 0 30.9375 0 26.867188 0 18.71875 Z M 0 17.28125 "/>
                                    <path style=" stroke:none;fill-rule:nonzero;fill:rgb(100%,100%,100%);fill-opacity:1;" d="M 19.15625 25.933594 C 10.949219 25.933594 6.269531 20.308594 6.074219 10.949219 L 10.183594 10.949219 C 10.320312 17.820312 13.351562 20.730469 15.75 21.328125 L 15.75 10.949219 L 19.621094 10.949219 L 19.621094 16.875 C 21.988281 16.621094 24.480469 13.921875 25.320312 10.949219 L 29.191406 10.949219 C 28.546875 14.609375 25.84375 17.308594 23.925781 18.421875 C 25.84375 19.320312 28.921875 21.675781 30.089844 25.933594 L 25.828125 25.933594 C 24.914062 23.085938 22.636719 20.878906 19.621094 20.578125 L 19.621094 25.933594 Z M 19.15625 25.933594 "/>
                                </g>
                            </svg>
                        </span>
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
						<span class="fs-lms-auth-icon ">
                            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="37px" height="36px" viewBox="0 0 36 36" version="1.1">
                                <g id="surface1">
                                    <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 15.222656 26.019531 C 10.582031 25.445312 7.3125 22.035156 7.3125 17.621094 C 7.3125 15.828125 7.945312 13.890625 9 12.597656 C 8.542969 11.414062 8.613281 8.902344 9.140625 7.859375 C 10.546875 7.679688 12.445312 8.433594 13.570312 9.472656 C 14.90625 9.042969 16.3125 8.828125 18.035156 8.828125 C 19.757812 8.828125 21.164062 9.042969 22.429688 9.4375 C 23.519531 8.433594 25.453125 7.679688 26.859375 7.859375 C 27.351562 8.828125 27.421875 11.339844 26.964844 12.5625 C 28.089844 13.925781 28.6875 15.753906 28.6875 17.621094 C 28.6875 22.035156 25.417969 25.375 20.707031 25.984375 C 21.902344 26.773438 22.710938 28.496094 22.710938 30.46875 L 22.710938 34.203125 C 22.710938 35.277344 23.589844 35.886719 24.644531 35.457031 C 31.007812 32.980469 36 26.484375 36 18.445312 C 36 8.289062 27.914062 0 17.964844 0 C 8.015625 0 0 8.289062 0 18.445312 C 0 26.414062 4.957031 33.019531 11.636719 35.492188 C 12.585938 35.851562 13.5 35.207031 13.5 34.238281 L 13.5 31.367188 C 13.007812 31.582031 12.375 31.726562 11.8125 31.726562 C 9.492188 31.726562 8.121094 30.433594 7.136719 28.027344 C 6.75 27.058594 6.328125 26.484375 5.519531 26.378906 C 5.097656 26.34375 4.957031 26.164062 4.957031 25.949219 C 4.957031 25.515625 5.660156 25.195312 6.363281 25.195312 C 7.382812 25.195312 8.261719 25.839844 9.175781 27.167969 C 9.878906 28.207031 10.617188 28.675781 11.496094 28.675781 C 12.375 28.675781 12.9375 28.351562 13.746094 27.527344 C 14.34375 26.917969 14.800781 26.378906 15.222656 26.019531 Z M 15.222656 26.019531 "/>
                                </g>
                            </svg>
                        </span>
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
