<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$saved_templates = (array) get_option( 'fs_lms_email_templates', array() );

$types = array(
	'otp_code'                 => array(
		'label'           => 'OTP-код подтверждения (ученику)',
		'default_subject' => '[FS LMS] Одноразовый код подтверждения',
		'placeholders'    => array(
			'{code}' => 'Одноразовый код (6 цифр)',
		),
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
						<span class="fs-template-notice js-template-notice hidden"></span>
					</div>

				</div>
			</div>

		<?php endforeach; ?>

	</div>

</div>
