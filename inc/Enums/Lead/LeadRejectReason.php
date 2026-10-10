<?php

declare( strict_types=1 );

namespace Inc\Enums\Lead;

/**
 * Почему лид-форма сайта отклонила заявку. Значения задаёт тема
 * (`fs_lms_theme_handle_form_submit()`), здесь — их подписи для таблицы.
 */
enum LeadRejectReason: string {

	case NameFormat       = 'name_format';
	case NameSingleWord   = 'name_single_word';
	case PhoneFormat      = 'phone_format';
	case RateIp           = 'rate_ip';
	case RatePhone        = 'rate_phone';
	case CaptchaFailed    = 'captcha_failed';
	case NoCaptchaLimit   = 'no_captcha_limit';

	public function label(): string {
		return match ( $this ) {
			self::NameFormat     => 'Недопустимые символы в имени',
			self::NameSingleWord => 'Имя из одного слова',
			self::PhoneFormat    => 'Неверный телефон',
			self::RateIp         => 'Лимит заявок с IP',
			self::RatePhone      => 'Лимит заявок по телефону',
			self::CaptchaFailed  => 'Капча не пройдена',
			self::NoCaptchaLimit => 'Лимит заявок без капчи',
		};
	}
}
