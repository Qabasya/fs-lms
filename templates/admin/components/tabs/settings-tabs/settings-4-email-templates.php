<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

$saved_templates = (array) get_option( 'fs_lms_email_templates', array() );

$types = array(
	'otp_code'                 => array(
		'label'           => 'OTP-код подтверждения (ученику)',
		'default_subject' => '[FS LMS] Одноразовый код подтверждения',
		'placeholders'    => array(
			'{code}' => 'Одноразовый код (6 цифр)',
		),
		// Зеркало текущего templates/emails/otp_code.php (с {code} вместо PHP-интерполяции) —
		// показывается в textarea, пока админ не сохранил свой вариант.
		'default_body'    => <<<HTML
			<div style="background:#eef1f6; padding:32px 12px; font-family:Arial, Helvetica, sans-serif;">
			<span style="display:none; font-size:1px; color:#eef1f6; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">Ваш код регистрации в FS LMS: {code}. Действует 10 минут.</span>
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px; margin:0 auto;">
			  <tr>
			    <td style="padding:0 8px 20px 8px; text-align:center;">
			      <span style="font-size:22px; font-weight:bold; color:#232a3d; letter-spacing:0.5px;">Future Step&nbsp;<span style="color: rgb(53, 86, 201);">LMS</span></span>
			    </td>
			  </tr>
			  <tr>
			    <td>
			      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#fdfdfb; border-radius:14px; border:1px solid #dfe3ec;">
			        <tr>
			          <td height="6" bgcolor="#3556c9" style="border-radius:14px 14px 0 0; font-size:0; line-height:0; mso-line-height-rule:exactly;">&nbsp;</td>
			        </tr>
			        <tr>
			          <td style="padding:36px 40px 8px 40px;">
			            <span style="font-size:24px; font-weight:bold; color:#232a3d; line-height:30px; mso-line-height-rule:exactly;">Привет! Ты почти в деле 👋</span>
			          </td>
			        </tr>
			        <tr>
			          <td style="padding: 8px 40px 24px 40px; text-align: center">
			            <span style="font-size: 15px; color: rgb(74, 82, 102); line-height: 23px; text-align: center">Осталось подтвердить регистрацию на платформе. <br>Введи этот код на странице регистрации:</span>
			          </td>
			        </tr>
			        <tr>
			          <td style="padding:0 40px;">
			            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
			              <tr>
			                <td bgcolor="#eef1f9" style="background:#eef1f9; border:1px dashed #aab6d8; border-radius:10px; padding:24px 16px; text-align:center;">
			                  <span style="font-family:'Courier New', Courier, monospace; font-size:38px; font-weight:bold; letter-spacing:10px; color:#232a3d;">{code}</span>
			                </td>
			              </tr>
			            </table>
			          </td>
			        </tr>
			        <tr>
			          <td style="padding:16px 40px 4px 40px; text-align:center;">
			            <span style="font-size:13px; color:#7a8194; line-height:19px; mso-line-height-rule:exactly;">Код действует <span style="color:#232a3d; font-weight:bold;">10 минут</span></span>
			          </td>
			        </tr>
			        <tr>
			          <td style="padding:20px 40px 0 40px;">
			            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
			              <tr>
			                <td style="border-top:1px solid #e6e9f1; font-size:0; line-height:0;">&nbsp;</td>
			              </tr>
			            </table>
			          </td>
			        </tr>
			        <tr>
			          <td style="padding: 12px 40px 36px 40px; text-align: center">
			            <span style="font-size: 13px; color: #7a8194; line-height: 20px; mso-line-height-rule: exactly">🔒 Никому не сообщай этот код!<br>Если ты не регистрировался в FS&nbsp;LMS, просто проигнорируй это письмо.</span>
			          </td>
			        </tr>
			      </table>
			    </td>
			  </tr>
			  <tr>
			    <td style="padding:24px 8px 8px 8px; text-align:center;">
			      <span style="font-size:12px; color:#9aa0b0; line-height:18px; mso-line-height-rule:exactly;">Это автоматическое письмо, отвечать на него не нужно.<br>FS&nbsp;LMS</span>
			    </td>
			  </tr>
			</table>
			</div>
			HTML,
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
		// Зеркало текущего templates/emails/welcome_with_credentials.php с {token} вместо PHP-переменных.
		'default_body'    => '<p>Здравствуйте, {display_name}!</p>'
			. '<p>Для вас создана учётная запись в системе FS LMS.</p>'
			. '<p><strong>Логин:</strong> {login}<br>'
			. '<strong>Пароль:</strong> {password}</p>'
			. '<p><a href="{login_url}">Войти в личный кабинет</a></p>',
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
