<?php

declare( strict_types=1 );

namespace Inc\Services\Email;

use Inc\Contracts\EmailTemplateInterface;
use Inc\DTO\Email\EmailTemplateData;
use Inc\Enums\EmailTemplateType;
use RuntimeException;

/**
 * Загружает email-шаблон из PHP-файла templates/emails/{type}.php.
 *
 * Каждый файл шаблона должен вернуть массив ['subject' => ..., 'body' => ...].
 * Переменные из $vars доступны внутри файла через extract().
 *
 * ### Пример шаблона (templates/emails/otp_code.php):
 * ```php
 * <?php
 * // @var string $code
 * return [
 *     'subject' => 'Код подтверждения — FS LMS',
 *     'body'    => '<p>Ваш код: <strong>' . esc_html( $code ) . '</strong>. Действителен 10 минут.</p>',
 * ];
 * ```
 */
class PhpEmailTemplate implements EmailTemplateInterface {

	public function get( EmailTemplateType $type, array $vars = [] ): EmailTemplateData {
		$path = FS_LMS_PATH . 'templates/emails/' . $type->value . '.php';

		if ( ! file_exists( $path ) ) {
			throw new RuntimeException( "Email template not found: {$type->value} ({$path})" );
		}

		// Переменные из $vars попадают в scope файла через extract()
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );

		// include возвращает значение return-statement файла
		$data = include $path;

		if ( ! is_array( $data ) || empty( $data['subject'] ) ) {
			throw new RuntimeException( "Email template '{$type->value}' must return ['subject' => ..., 'body' => ...]" );
		}

		return new EmailTemplateData(
			subject: (string) $data['subject'],
			body:    (string) ( $data['body'] ?? '' ),
		);
	}
}