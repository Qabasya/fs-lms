<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\Enums\Settings\OptionName;

class EmailTemplatesRepository {

	public function readAll(): array {
		return (array) get_option( OptionName::EmailTemplates->value, array() );
	}

	public function saveTemplate( string $type, string $subject, string $body ): void {
		$templates          = $this->readAll();
		$templates[ $type ] = array(
			'subject' => $subject,
			'body'    => $body,
		);
		update_option( OptionName::EmailTemplates->value, $templates );
	}

	public function deleteTemplate( string $type ): void {
		$templates = $this->readAll();
		unset( $templates[ $type ] );
		update_option( OptionName::EmailTemplates->value, $templates );
	}
}
