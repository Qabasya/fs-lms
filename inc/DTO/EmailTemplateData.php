<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Тема и тело одного письма, готовые к передаче в wp_mail().
 */
readonly class EmailTemplateData {

	public function __construct(
		public string $subject,
		public string $body,
	) {}
}