<?php

declare( strict_types=1 );

namespace Inc\Contracts;

use Inc\DTO\EmailTemplateData;

/**
 * Стратегия получения содержимого email-шаблона.
 *
 * Одна реализация читает из PHP-файлов (templates/emails/),
 * другая — из wp_options с fallback на PHP-файлы.
 * EmailService зависит от этого интерфейса — не от конкретных реализаций.
 */
interface EmailTemplateInterface {

	/**
	 * Возвращает тему и тело письма для заданного типа.
	 *
	 * @param string               $type Идентификатор шаблона (например 'otp_code')
	 * @param array<string, mixed> $vars Переменные для подстановки в шаблон
	 *
	 * @return EmailTemplateData
	 */
	public function get( string $type, array $vars = [] ): EmailTemplateData;
}