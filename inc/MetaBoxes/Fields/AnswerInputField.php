<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/** Однострочный эталон ответа: операторы сравнения являются текстом, не HTML. */
class AnswerInputField extends InputField {
	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		parent::render( $post, $id, $label, html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	public function sanitize( mixed $value ): mixed {
		return $this->sanitizeAnswerTextValue( $value );
	}
}
