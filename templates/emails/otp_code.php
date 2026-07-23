<?php
/**
 * Email: OTP-код подтверждения email.
 *
 * @var string $code Шестизначный код
 */
defined( 'ABSPATH' ) || exit;

$safe_code = esc_html( $code ?? '' );

$body = <<<HTML
<div style="background:#eef1f6; padding:32px 12px; font-family:Arial, Helvetica, sans-serif;">
<span style="display:none; font-size:1px; color:#eef1f6; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">Ваш код регистрации в FS LMS: {$safe_code}. Действует 10 минут.</span>
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
                  <span style="font-family:'Courier New', Courier, monospace; font-size:38px; font-weight:bold; letter-spacing:10px; color:#232a3d;">{$safe_code}</span>
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
HTML;

return array(
	'subject' => 'Код подтверждения — FS LMS',
	'body'    => $body,
);
