<?php

declare( strict_types=1 );

namespace Inc\Contracts;

interface FieldInterface {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void;

	public function sanitize( mixed $value ): mixed;
}