<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

// $saved_templates приходит из AdminCallbacks::settingsPage() (репозиторий шаблонов).
$saved_templates = isset( $saved_templates ) ? (array) $saved_templates : array();

$types = array(
	'otp_code'                 => array(
		'label'           => 'OTP-код подтверждения (ученику)',
		'default_subject' => '[FS LMS] Одноразовый код подтверждения',
		'placeholders'    => array(
			'{code}' => 'Одноразовый код (6 цифр)',
		),
		// Разметка — из единственного источника templates/emails/bodies/otp_code.php
		// (плейсхолдер {code}); показывается в textarea, пока админ не сохранил свой вариант.
		'default_body'    => (string) require FS_LMS_PATH . 'templates/emails/bodies/otp_code.php',
	),

	'welcome_with_credentials' => array(
		'label'           => 'Данные для входа после зачисления (родителю)',
		'default_subject' => '[FS LMS] Данные для входа',
		'placeholders'    => array(
			'{display_name}'       => 'Имя пользователя',
			'{login}'              => 'Логин (email)',
			'{password}'           => 'Пароль',
			'{login_url}'          => 'URL страницы входа',
			'{student_full_name}'  => 'Фамилия Имя Отчество ученика',
			'{parent_first_name}'  => 'Имя родителя',
			'{parent_middle_name}' => 'Отчество родителя',
		),
		// Зеркало текущего templates/emails/welcome_with_credentials.php (с {token} вместо PHP-интерполяции) —
		// показывается в textarea, пока админ не сохранил свой вариант. Письмо уходит только родителю.
		// Разметка — из templates/emails/bodies/welcome_with_credentials.php.
		'default_body'    => (string) require FS_LMS_PATH . 'templates/emails/bodies/welcome_with_credentials.php',
	),
);
?>

<div id="tab-email-templates" class="tab-pane active">

	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h2 class="fs-page-header__title">Шаблоны писем</h2>
		</div>
		<p class="fs-page-header__desc">
			Переопределите текст и тему письма. Если поля пусты — используется PHP-шаблон по умолчанию.<br>
			В теме и теле поддерживается HTML. Плейсхолдеры подставляются при отправке.
		</p>
	</div>

	<div class="fs-email-templates" id="js-email-templates">

		<?php foreach ( $types as $type_key => $type_cfg ) :
			$stored    = $saved_templates[ $type_key ] ?? null;
			$subject   = (string) ( $stored['subject'] ?? '' );
			$body      = (string) ( $stored['body'] ?? $type_cfg['default_body'] );
			$is_custom = ! empty( $stored['subject'] ) || ! empty( $stored['body'] );
			?>

			<div
				class="fs-card"
				data-type="<?php echo esc_attr( $type_key ); ?>"
				data-default-subject="<?php echo esc_attr( $type_cfg['default_subject'] ); ?>"
				data-default-body="<?php echo esc_attr( $type_cfg['default_body'] ); ?>"
			>

				<div class="fs-card__header">
					<h3 class="fs-card__title"><?php echo esc_html( $type_cfg['label'] ); ?></h3>
					<span class="fs-email-status <?php echo $is_custom ? 'fs-email-status--custom' : 'fs-email-status--default'; ?>"
						data-status-label>
						<?php echo $is_custom ? 'Переопределён' : 'По умолчанию'; ?>
					</span>
				</div>

				<div class="fs-card__body">

					<?php if ( ! empty( $type_cfg['placeholders'] ) ) : ?>
						<div class="fs-email-placeholders">
							<span class="label">Плейсхолдеры:</span>
							<?php foreach ( $type_cfg['placeholders'] as $placeholder => $desc ) : ?>
								<code title="<?php echo esc_attr( $desc ); ?>"><?php echo esc_html( $placeholder ); ?></code>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<div class="fs-field">
						<label class="fs-field__label">Тема письма</label>
						<div class="fs-field__control">
							<input
								type="text"
								class="regular-text js-email-subject"
								placeholder="<?php echo esc_attr( $type_cfg['default_subject'] ); ?>"
								value="<?php echo esc_attr( $subject ); ?>"
							>
						</div>
					</div>

					<div class="fs-field">
						<label class="fs-field__label">Текст письма (HTML)</label>
						<div class="fs-field__control">
							<textarea
								class="large-text js-email-body"
								rows="8"
								placeholder="Оставьте пустым, чтобы использовать PHP-шаблон по умолчанию"
							><?php echo esc_textarea( $body ); ?></textarea>
						</div>
					</div>

				</div>

				<div class="fs-card__footer">
					<button type="button" class="button button-primary js-save-email-template">
						Сохранить
					</button>
					<?php if ( $is_custom ) : ?>
						<button type="button" class="button js-reset-email-template">
							Сбросить к умолчанию
						</button>
					<?php else : ?>
						<button type="button" class="button js-reset-email-template" disabled>
							Сбросить к умолчанию
						</button>
					<?php endif; ?>
					<span class="fs-template-notice js-template-notice hidden"></span>
				</div>

			</div>

		<?php endforeach; ?>

	</div>

</div>
