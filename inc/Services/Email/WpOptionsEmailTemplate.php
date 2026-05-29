<?php

declare( strict_types=1 );

namespace Inc\Services\Email;

use Inc\Contracts\EmailTemplateInterface;
use Inc\DTO\EmailTemplateData;
use Inc\Enums\OptionName;

/**
 * Загружает email-шаблон из wp_options; при отсутствии — делегирует в PhpEmailTemplate.
 *
 * ### Структура wp_options (OptionName::EmailTemplates):
 * ```php
 * [
 *   'otp_code' => [
 *     'subject' => 'Код подтверждения — FS LMS',
 *     'body'    => '<p>Ваш код: <strong>{code}</strong>. Действителен 10 минут.</p>',
 *   ],
 *   'password_setup' => [ ... ],
 * ]
 * ```
 *
 * ### Плейсхолдеры в теле и теме:
 * Переменные из $vars подставляются как {key} → value.
 * Например: `'Ваш код: {code}'` + `['code' => '123456']` → `'Ваш код: 123456'`.
 */
class WpOptionsEmailTemplate implements EmailTemplateInterface {

	public function __construct(
		private readonly PhpEmailTemplate $fallback,
	) {}

	public function get( string $type, array $vars = [] ): EmailTemplateData {
		$all      = (array) get_option( OptionName::EmailTemplates->value, array() );
		$template = $all[ $type ] ?? null;

		if ( empty( $template['subject'] ) || empty( $template['body'] ) ) {
			return $this->fallback->get( $type, $vars );
		}

		return new EmailTemplateData(
			subject: $this->interpolate( (string) $template['subject'], $vars ),
			body:    $this->interpolate( (string) $template['body'], $vars ),
		);
	}

	/**
	 * Заменяет {key} на соответствующее значение из $vars.
	 * Значения экранируются через esc_html() для безопасного вывода в HTML.
	 *
	 * @param string               $text
	 * @param array<string, mixed> $vars
	 *
	 * @return string
	 */
	private function interpolate( string $text, array $vars ): string {
		foreach ( $vars as $key => $value ) {
			$text = str_replace( '{' . $key . '}', esc_html( (string) $value ), $text );
		}

		return $text;
	}
}