<?php
/**
 * Email: Данные для входа после зачисления (только родителю).
 *
 * @var string $display_name       Имя пользователя (родителя)
 * @var string $login              Логин (email)
 * @var string $password           Пароль в открытом виде
 * @var string $login_url          URL страницы входа
 * @var string $student_full_name  Фамилия Имя Отчество ученика
 * @var string $parent_first_name  Имя родителя
 * @var string $parent_middle_name Отчество родителя
 */
defined( 'ABSPATH' ) || exit;

$safe_parent_first_name  = esc_html( $parent_first_name ?? $display_name ?? '' );
$safe_parent_middle_name = esc_html( $parent_middle_name ?? '' );
$safe_student_full_name  = esc_html( $student_full_name ?? '' );
$safe_login              = esc_html( $login ?? '' );
$safe_password           = esc_html( $password ?? '' );
$safe_login_url          = esc_url( $login_url ?? wp_login_url() );

$body = <<<HTML
<div style="background:#eef1f6; padding:32px 12px; font-family:Arial, Helvetica, sans-serif;">
<span style="display:none; font-size:1px; color:#eef1f6; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">Личный кабинет родителя в FS LMS готов — внутри логин и данные для входа.</span>
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
            <span style="font-size:24px; font-weight:bold; color:#232a3d; line-height:30px; mso-line-height-rule:exactly;">Здравствуйте, {$safe_parent_first_name} {$safe_parent_middle_name}!</span>
          </td>
        </tr>
        <tr>
          <td style="padding: 8px 40px 24px 40px; text-align: center">
            <span style="font-size: 15px; color: rgb(74, 82, 102); line-height: 23px; text-align: center;">Рады приветствовать вас в FS&nbsp;LMS! <br>Ваш ребёнок — <span style="color: rgb(35, 42, 61); font-weight: bold;">{$safe_student_full_name}</span> — успешно зачислен на обучение. <br><br>Для вас создан личный кабинет родителя: в нём вы сможете следить <br>за успеваемостью ребёнка.</span>
          </td>
        </tr>
        <tr>
          <td style="padding:0 40px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
              <tr>
                <td bgcolor="#eef1f9" style="background:#eef1f9; border:1px dashed #aab6d8; border-radius:10px; padding:20px 24px;">
                  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                    <tr>
                      <td style="padding:4px 0;">
                        <span style="font-size:12px; color:#7a8194; text-transform:uppercase; letter-spacing:1px;">Логин</span><br>
                        <span style="font-family:'Courier New', Courier, monospace; font-size:18px; font-weight:bold; color:#232a3d; line-height:28px; mso-line-height-rule:exactly;">{$safe_login}</span>
                      </td>
                    </tr>
                    <tr>
                      <td style="border-top:1px solid #d5dcee; font-size:0; line-height:0;">&nbsp;</td>
                    </tr>
                    <tr>
                      <td style="padding:4px 0;">
                        <span style="font-size:12px; color:#7a8194; text-transform:uppercase; letter-spacing:1px;">Пароль</span><br>
                        <span style="font-family:'Courier New', Courier, monospace; font-size:18px; font-weight:bold; color:#232a3d; line-height:28px; mso-line-height-rule:exactly;">{$safe_password}</span>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:28px 40px 8px 40px; text-align:center;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
              <tr>
                <td bgcolor="#3556c9" style="background:#3556c9; border-radius:10px;">
                  <a href="{$safe_login_url}" style="display:block; padding:15px 44px; font-family:Arial, Helvetica, sans-serif; font-size:16px; font-weight:bold; color:#ffffff; text-decoration:none;">Войти в личный кабинет</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:4px 40px 24px 40px; text-align:center;">
            <span style="font-size:13px; color:#7a8194; line-height:19px; mso-line-height-rule:exactly;">или откройте страницу входа: <a href="{$safe_login_url}" style="color:#3556c9;">future-step.ru/sign-in</a></span>
          </td>
        </tr>
        <tr>
          <td style="padding:0 40px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
              <tr>
                <td style="border-top:1px solid #e6e9f1; font-size:0; line-height:0;">&nbsp;</td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding: 12px 40px 36px 40px; text-align: center">
            <span style="font-size: 13px; color: rgb(122, 129, 148); line-height: 20px;">🔒 Пароль — конфиденциальные данные, не пересылайте это письмо<br></span></td>
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
HTML;

return array(
	'subject' => 'Добро пожаловать в FS LMS — данные для входа',
	'body'    => $body,
);
