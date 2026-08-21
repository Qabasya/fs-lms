<div id="fs-lms-help-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>
	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title">Инструкция по подключению</h2>
			<button type="button" class="fs-lms-modal-close" aria-label="Закрыть">&times;</button>
		</div>
		<div class="fs-lms-modal-body">

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