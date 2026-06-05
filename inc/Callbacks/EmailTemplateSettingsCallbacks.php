<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Repositories\OptionsRepositories\EmailTemplatesRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class EmailTemplateSettingsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	private const ALLOWED_TYPES = array(
		'otp_code',
		'password_setup',
		'application_confirmation',
		'application_ready',
		'rejection',
		'new_representative',
		'welcome_with_credentials',
	);

	public function __construct(
		private readonly EmailTemplatesRepository $templates,
	) {
		parent::__construct();
	}

	public function ajaxSaveEmailTemplate(): void {
		$this->authorize( Nonce::Manager );

		$type    = $this->requireKey( 'type', error: 'Тип шаблона обязателен.' );
		$subject = $this->requireText( 'subject', error: 'Тема письма обязательна.' );

		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			$this->error( 'Неизвестный тип шаблона.' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$body = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );

		$this->templates->saveTemplate( $type, $subject, $body );
		$this->success( array( 'message' => 'Шаблон сохранён.' ) );
	}

	public function ajaxResetEmailTemplate(): void {
		$this->authorize( Nonce::Manager );

		$type = $this->requireKey( 'type', error: 'Тип шаблона обязателен.' );

		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			$this->error( 'Неизвестный тип шаблона.' );
		}

		$this->templates->deleteTemplate( $type );
		$this->success( array( 'message' => 'Шаблон сброшен к умолчанию.' ) );
	}
}
