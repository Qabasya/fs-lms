<?php

declare( strict_types=1 );

namespace Inc\Enums\Auth;

enum AuthAction: string {
	case Login         = 'login';
	case LoginFailed   = 'login_failed';
	case OtpSent       = 'otp_sent';
	case OtpVerified   = 'otp_verified';
	case PasswordReset = 'password_reset';
	// Форма заявки (/lms/apply): что происходило до отправки кода (события шлёт браузер)
	case ApplyStarted        = 'apply_started';
	case ApplyInvalid        = 'apply_invalid';
	case ApplyCaptchaShown   = 'apply_captcha_shown';
	case ApplyCaptchaFailed  = 'apply_captcha_failed';
	case ApplySubmitFailed   = 'apply_submit_failed';
	case ApplyLeft           = 'apply_left';
	// Гостевая форма записи на экзамен (/exam-signup/): открыта, не прошла проверку, бронь создана, лимит
	case ExamFormOpened      = 'exam_form_opened';
	case ExamFormInvalid     = 'exam_form_invalid';
	case ExamHoldCreated     = 'exam_hold_created';
	case ExamFormLimit       = 'exam_form_limit';
	// Капча не дошла до браузера, форма пропущена по смягчённому правилу
	case CaptchaFallback     = 'captcha_fallback';

	public function label(): string {
		return match ( $this ) {
			self::Login         => 'Вход',
			self::LoginFailed   => 'Неудача входа',
			self::OtpSent       => 'OTP отправлен',
			self::OtpVerified   => 'OTP подтверждён',
			self::PasswordReset => 'Сброс пароля',
			self::ApplyStarted       => 'Заявка: начал заполнять',
			self::ApplyInvalid       => 'Заявка: форма не прошла проверку',
			self::ApplyCaptchaShown  => 'Заявка: капча показала задание',
			self::ApplyCaptchaFailed => 'Заявка: капча не пройдена',
			self::ApplySubmitFailed  => 'Заявка: отправка не удалась',
			self::ApplyLeft          => 'Заявка: ушёл, не отправив',
			self::ExamFormOpened     => 'Экзамен: форма записи открыта',
			self::ExamFormInvalid    => 'Экзамен: форма записи не прошла проверку',
			self::ExamHoldCreated    => 'Экзамен: бронь создана',
			self::ExamFormLimit      => 'Экзамен: лимит заявок',
			self::CaptchaFallback    => 'Пропуск без капчи',
		};
	}
}
