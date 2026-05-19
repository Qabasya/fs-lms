<div id="fs-lms-help-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>
	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title">Инструкция по подключению</h2>
			<button type="button" class="fs-lms-modal-close" aria-label="Закрыть">&times;</button>
		</div>
		<div class="fs-lms-modal-body">

			<div class="fs-lms-help-content hidden" data-provider="google">
				<div class="fs-lms-modal-body-header">
					<div class="fs-lms-modal-body-header__left">
						<span class="fs-lms-modal-body-header__icon fs-lms-modal-body-header__icon--google"></span>
						<div class="fs-lms-modal-body-header__title-group">
							<h3>Настройка авторизации через Google Cloud Console</h3>
							<p>Следуйте шагам ниже, чтобы получить Client ID и Client Secret.</p>
						</div>
					</div>
					<a href="https://console.cloud.google.com/" target="_blank" class="fs-lms-modal-body-header__link">
						Открыть Google Cloud Console <span class="dashicons dashicons-external"></span>
					</a>
				</div>

				<ol>
					<li>Перейдите в <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a>.</li>
					<li>Создайте новый проект или выберите существующий.</li>
					<li>В меню откройте раздел <strong>API & Services &rarr; Credentials</strong>.</li>
					<li>Нажмите <strong>Create Credentials</strong> и выберите <strong>OAuth client ID</strong>.</li>
					<li>
						В поле &ldquo;Authorized redirect URIs&rdquo; вставьте:
						<div class="fs-lms-redirect-box">
							<div class="fs-lms-redirect-box__label">Redirect URI</div>
							<div class="fs-lms-redirect-box__field">
								<span class="fs-lms-redirect-box__url js-copy-target"><?php echo esc_url( home_url( '/lms-auth/callback?provider=google' ) ); ?></span>
							</div>
							<div class="fs-lms-redirect-box__subtext">Нажмите текст выше, чтобы быстро скопировать URI.</div>
						</div>
					</li>
					<li>Скопируйте полученные <strong>Client ID</strong> и <strong>Client Secret</strong> и вставьте их в настройки.</li>
				</ol>

				<div class="fs-lms-help-success">
					<div class="fs-lms-help-success__badge">
						<span class="dashicons dashicons-yes"></span>
					</div>
					<div class="fs-lms-help-success__text">
						<h4>Готово!</h4>
						<p>После сохранения настроек пользователи смогут входить через Google.</p>
					</div>
				</div>
			</div>

			<div class="fs-lms-help-content hidden" data-provider="vk">
				<div class="fs-lms-modal-body-header">
					<div class="fs-lms-modal-body-header__left">
						<span class="fs-lms-modal-body-header__icon fs-lms-modal-body-header__icon--vk"></span>
						<div class="fs-lms-modal-body-header__title-group">
							<h3>Настройка авторизации через VK ID</h3>
							<p>Следуйте шагам ниже, чтобы зарегистрировать приложение и получить ключи доступа.</p>
						</div>
					</div>
					<a href="https://id.vk.com/business/go" target="_blank" class="fs-lms-modal-body-header__link">
						Открыть VK ID для бизнеса <span class="dashicons dashicons-external"></span>
					</a>
				</div>

				<ol>
					<li>Перейдите на платформу <a href="https://id.vk.com/business/go" target="_blank">VK ID для бизнеса</a>.</li>
					<li>Создайте новое приложение, выбрав тип <strong>«Веб-сайт»</strong>.</li>
					<li>В настройках подключения укажите название и базовый домен вашего образовательного сайта.</li>
					<li>
						В поле <strong>«Доверительный URI редиректа»</strong> вставьте:
						<div class="fs-lms-redirect-box">
							<div class="fs-lms-redirect-box__label">Redirect URI</div>
							<div class="fs-lms-redirect-box__field">
								<span class="fs-lms-redirect-box__url js-copy-target"><?php echo esc_url( home_url( '/lms-auth/callback?provider=vk' ) ); ?></span>
							</div>
							<div class="fs-lms-redirect-box__subtext">Нажмите текст выше, чтобы быстро скопировать URI.</div>
						</div>
					</li>
					<li>Перейдите в раздел «Настройки» созданного приложения, скопируйте <strong>App ID</strong> и <strong>Защищённый ключ (App Secret)</strong> и вставьте их в поля настроек.</li>
				</ol>

				<div class="fs-lms-help-success">
					<div class="fs-lms-help-success__badge">
						<span class="dashicons dashicons-yes"></span>
					</div>
					<div class="fs-lms-help-success__text">
						<h4>Готово!</h4>
						<p>После сохранения настроек пользователи смогут входить через ВКонтакте.</p>
					</div>
				</div>
			</div>

			<div class="fs-lms-help-content hidden" data-provider="github">
				<div class="fs-lms-modal-body-header">
					<div class="fs-lms-modal-body-header__left">
						<span class="fs-lms-modal-body-header__icon fs-lms-modal-body-header__icon--github"></span>
						<div class="fs-lms-modal-body-header__title-group">
							<h3>Настройка авторизации через GitHub OAuth Apps</h3>
							<p>Следуйте шагам ниже, чтобы зарегистрировать новое OAuth-приложение.</p>
						</div>
					</div>
					<a href="https://github.com/settings/developers" target="_blank" class="fs-lms-modal-body-header__link">
						Открыть Developer Settings <span class="dashicons dashicons-external"></span>
					</a>
				</div>

				<ol>
					<li>Зайдите в настройки профиля в раздел <a href="https://github.com/settings/developers" target="_blank">Developer settings &rarr; OAuth Apps</a>.</li>
					<li>Нажмите верхнюю правую кнопку <strong>Register a new application</strong>.</li>
					<li>Заполните название приложения и в поле Homepage URL введите адрес вашего сайта.</li>
					<li>
						В поле <strong>«Authorization callback URL»</strong> вставьте следующий адрес:
						<div class="fs-lms-redirect-box">
							<div class="fs-lms-redirect-box__label">Redirect URI</div>
							<div class="fs-lms-redirect-box__field">
								<span class="fs-lms-redirect-box__url js-copy-target"><?php echo esc_url( home_url( '/lms-auth/callback?provider=github' ) ); ?></span>
							</div>
							<div class="fs-lms-redirect-box__subtext">Нажмите текст выше, чтобы быстро скопировать URI.</div>
						</div>
					</li>
					<li>Нажмите кнопку <strong>Register application</strong>, после чего скопируйте сгенерированный <strong>Client ID</strong>.</li>
					<li>Там же нажмите кнопку <strong>Generate a new client secret</strong>, скопируйте созданный ключ и перенесите оба значения в форму настроек.</li>
				</ol>

				<div class="fs-lms-help-success">
					<div class="fs-lms-help-success__badge">
						<span class="dashicons dashicons-yes"></span>
					</div>
					<div class="fs-lms-help-success__text">
						<h4>Готово!</h4>
						<p>После сохранения настроек пользователи смогут входить через GitHub.</p>
					</div>
				</div>
			</div>

		</div>

		<div class="fs-lms-modal-footer">
			<button type="button" class="fs-lms-modal-close button button-primary">Понятно</button>
		</div>
	</div>
</div>