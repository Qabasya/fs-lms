<?php

declare( strict_types=1 );

namespace Inc\Contracts;

use Inc\DTO\Email\EmailTemplateData;
use Inc\Enums\Email\EmailTemplateType;

interface EmailTemplateInterface {

	/**
	 * Возвращает тему и тело письма для заданного типа.
	 *
	 * @param EmailTemplateType    $type Тип шаблона
	 * @param array<string, mixed> $vars Переменные для подстановки
	 *
	 * @return EmailTemplateData
	 */
	public function get( EmailTemplateType $type, array $vars = [] ): EmailTemplateData;
}
