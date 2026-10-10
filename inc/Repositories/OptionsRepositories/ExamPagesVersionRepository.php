<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\Enums\Settings\OptionName;

/** Маркер успешного обеспечения гостевых страниц; не настройки модуля свободной практики. */
class ExamPagesVersionRepository {

	public function get(): string {
		$value = get_option( OptionName::ExamPagesVersion->value, '' );
		return is_string( $value ) ? $value : '';
	}

	public function save( string $version ): bool {
		update_option( OptionName::ExamPagesVersion->value, $version, false );
		return $version === $this->get();
	}
}
