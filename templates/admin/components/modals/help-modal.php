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
								<input type="text" readonly class="fs-lms-redirect-box__url js-copy-target" value="<?php echo esc_url( home_url( '/lms-auth/callback?provider=google' ) ); ?>" aria-label="Redirect URI">
							</div>
							<div class="fs-lms-redirect-box__subtext">Нажмите кнопку, чтобы скопировать адрес.</div>
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
								<input type="text" readonly class="fs-lms-redirect-box__url js-copy-target" value="<?php echo esc_url( home_url( '/lms-auth/callback?provider=vk' ) ); ?>" aria-label="Redirect URI">
							</div>
							<div class="fs-lms-redirect-box__subtext">Нажмите кнопку, чтобы скопировать адрес.</div>
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
								<input type="text" readonly class="fs-lms-redirect-box__url js-copy-target" value="<?php echo esc_url( home_url( '/lms-auth/callback?provider=github' ) ); ?>" aria-label="Redirect URI">
							</div>
							<div class="fs-lms-redirect-box__subtext">Нажмите кнопку, чтобы скопировать адрес.</div>
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

			<div class="fs-lms-help-content hidden" data-provider="dadata">
				<div class="fs-lms-modal-body-header">
					<div class="fs-lms-modal-body-header__left">
						<span class="fs-lms-modal-body-header__icon fs-lms-modal-body-header__icon--generic">
							<span class="dashicons dashicons-location-alt"></span>
						</span>
						<div class="fs-lms-modal-body-header__title-group">
							<h3>Подключение подсказок DaData</h3>
							<p>Токен нужен для автоподсказок адресов и ФИО на форме заявки.</p>
						</div>
					</div>
					<a href="https://dadata.ru/profile/#info" target="_blank" class="fs-lms-modal-body-header__link">
						Открыть профиль DaData <span class="dashicons dashicons-external"></span>
					</a>
				</div>

				<ol>
					<li>Зарегистрируйтесь или войдите на <a href="https://dadata.ru/" target="_blank">dadata.ru</a>.</li>
					<li>Откройте <a href="https://dadata.ru/profile/#info" target="_blank">Профиль &rarr; API</a>.</li>
					<li>Скопируйте значение <strong>«API-ключ»</strong> (он же токен). Секретный ключ для подсказок не требуется.</li>
					<li>Вставьте ключ в поле <strong>«DaData API Token»</strong> и сохраните настройки.</li>
				</ol>

				<div class="fs-lms-help-success">
					<div class="fs-lms-help-success__badge">
						<span class="dashicons dashicons-yes"></span>
					</div>
					<div class="fs-lms-help-success__text">
						<h4>Готово!</h4>
						<p>После сохранения на форме заявки заработают подсказки адресов и ФИО.</p>
					</div>
				</div>
			</div>

			<div class="fs-lms-help-content hidden" data-provider="smartcaptcha">
				<div class="fs-lms-modal-body-header">
					<div class="fs-lms-modal-body-header__left">
						<span class="fs-lms-modal-body-header__icon fs-lms-modal-body-header__icon--generic">
							<span class="dashicons dashicons-shield"></span>
						</span>
						<div class="fs-lms-modal-body-header__title-group">
							<h3>Подключение Yandex SmartCaptcha</h3>
							<p>Защита формы заявки от ботов. Нужны два ключа: клиентский и серверный.</p>
						</div>
					</div>
					<a href="https://yandex.cloud/ru/docs/smartcaptcha/quickstart#create-captcha" target="_blank" class="fs-lms-modal-body-header__link">
						Открыть инструкцию Яндекса <span class="dashicons dashicons-external"></span>
					</a>
				</div>

				<ol>
					<li>Откройте официальную инструкцию <a href="https://yandex.cloud/ru/docs/smartcaptcha/quickstart#create-captcha" target="_blank">«Создание капчи» в Yandex Cloud</a> и следуйте её шагам.</li>
					<li>При создании укажите домен(ы) вашего сайта и выберите <strong>невидимый</strong> тип проверки.</li>
					<li>После создания откройте капчу и скопируйте <strong>клиентский ключ</strong> и <strong>серверный ключ</strong>.</li>
					<li>Вставьте их в поля <strong>«SmartCaptcha — клиентский ключ»</strong> и <strong>«SmartCaptcha — серверный ключ»</strong>, затем сохраните.</li>
				</ol>

				<div class="fs-lms-help-success">
					<div class="fs-lms-help-success__badge">
						<span class="dashicons dashicons-yes"></span>
					</div>
					<div class="fs-lms-help-success__text">
						<h4>Готово!</h4>
						<p>После сохранения форма заявки <code>/lms/apply</code> будет защищена капчей.</p>
					</div>
				</div>
			</div>

		</div>

		<div class="fs-lms-modal-footer">
			<button type="button" class="fs-lms-modal-close button button-primary">Понятно</button>
		</div>
	</div>
</div>