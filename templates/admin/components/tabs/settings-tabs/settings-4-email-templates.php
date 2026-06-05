<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$saved_templates = (array) get_option( 'fs_lms_email_templates', array() );

$types = array(
	'otp_code'                 => array(
		'label'           => 'OTP-код подтверждения',
		'default_subject' => 'Код подтверждения — FS LMS',
		'placeholders'    => array(
			'{code}' => 'Одноразовый код (6 цифр)',
		),
	),
	'password_setup'           => array(
		'label'           => 'Ссылка для установки пароля',
		'default_subject' => 'Установите пароль для входа в FS LMS',
		'placeholders'    => array(
			'{display_name}' => 'Имя пользователя',
			'{link}'         => 'Ссылка для установки пароля (действует 48 ч)',
		),
	),
	'application_confirmation' => array(
		'label'           => 'Подтверждение заявки (ученику)',
		'default_subject' => 'Ваша заявка принята — FS LMS',
		'placeholders'    => array(
			'{join_url}'   => 'JOIN-ссылка для родителя',
			'{expires_at}' => 'Дата истечения ссылки',
		),
	),
	'application_ready'        => array(
		'label'           => 'Новая заявка (сотруднику)',
		'default_subject' => 'Новая заявка требует проверки — FS LMS',
		'placeholders'    => array(),
	),
	'rejection'                => array(
		'label'           => 'Отклонение заявки',
		'default_subject' => 'Заявка отклонена — FS LMS',
		'placeholders'    => array(
			'{reason}' => 'Причина отклонения',
		),
	),
	'new_representative'       => array(
		'label'           => 'Новый подопечный (родителю)',
		'default_subject' => 'В вашем профиле появился новый подопечный — FS LMS',
		'placeholders'    => array(
			'{display_name}' => 'Имя родителя',
			'{link}'         => 'Ссылка для входа (если аккаунт новый)',
		),
	),
	'welcome_with_credentials' => array(
		'label'           => 'Данные для входа после зачисления',
		'default_subject' => 'Добро пожаловать в FS LMS — данные для входа',
		'placeholders'    => array(
			'{display_name}' => 'Имя пользователя',
			'{login}'        => 'Логин (email)',
			'{password}'     => 'Пароль',
			'{login_url}'    => 'URL страницы входа',
		),
	),
);
?>

<div id="tab-email-templates" class="tab-pane active">

	<div class="header-row">
		<h1 class="wp-heading-inline">Шаблоны писем</h1>
	</div>

	<p class="description">
		Переопределите текст и тему письма. Если поля пусты — используется PHP-шаблон по умолчанию.<br>
		В теме и теле поддерживается HTML. Плейсхолдеры подставляются при отправке.
	</p>

	<div class="fs-email-templates" id="js-email-templates">

		<?php foreach ( $types as $type_key => $type_cfg ) :
			$stored  = $saved_templates[ $type_key ] ?? null;
			$subject = (string) ( $stored['subject'] ?? '' );
			$body    = (string) ( $stored['body'] ?? '' );
			$is_custom = ! empty( $stored['subject'] ) || ! empty( $stored['body'] );
			?>

			<div class="fs-email-template-card" data-type="<?php echo esc_attr( $type_key ); ?>">

				<div class="fs-email-template-card__header">
					<h3><?php echo esc_html( $type_cfg['label'] ); ?></h3>
					<span class="fs-email-template-card__status <?php echo $is_custom ? 'fs-email-template-card__status--custom' : 'fs-email-template-card__status--default'; ?>"
						data-status-label>
						<?php echo $is_custom ? 'Переопределён' : 'По умолчанию'; ?>
					</span>
				</div>

				<div class="fs-email-template-card__body">

					<?php if ( ! empty( $type_cfg['placeholders'] ) ) : ?>
						<div class="fs-email-template-card__placeholders">
							<span class="label">Плейсхолдеры:</span>
							<?php foreach ( $type_cfg['placeholders'] as $placeholder => $desc ) : ?>
								<code title="<?php echo esc_attr( $desc ); ?>"><?php echo esc_html( $placeholder ); ?></code>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<div class="fs-email-template-card__field">
						<label>Тема письма</label>
						<input
							type="text"
							class="regular-text js-email-subject"
							placeholder="<?php echo esc_attr( $type_cfg['default_subject'] ); ?>"
							value="<?php echo esc_attr( $subject ); ?>"
						>
					</div>

					<div class="fs-email-template-card__field">
						<label>Текст письма (HTML)</label>
						<textarea
							class="large-text js-email-body"
							rows="8"
							placeholder="Оставьте пустым, чтобы использовать PHP-шаблон по умолчанию"
						><?php echo esc_textarea( $body ); ?></textarea>
					</div>

					<div class="fs-email-template-card__actions">
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
						<span class="js-template-notice" style="display:none; margin-left:8px; font-size:13px;"></span>
					</div>

				</div>
			</div>

		<?php endforeach; ?>

	</div>

</div>
