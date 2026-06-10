<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Settings;

use Inc\Core\BaseController;
use Inc\Enums\EmailTemplateType;
use Inc\Enums\Nonce;
use Inc\Repositories\OptionsRepositories\EmailTemplatesRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class EmailTemplateSettingsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly EmailTemplatesRepository $templates,
	) {
		parent::__construct();
	}

	public function ajaxSaveEmailTemplate(): void {
		$this->authorize( Nonce::Manager );

		$rawType = $this->requireKey( 'type', error: 'Тип шаблона обязателен.' );
		$subject = $this->requireText( 'subject', error: 'Тема письма обязательна.' );
		$type    = EmailTemplateType::tryFrom( $rawType );

		if ( null === $type ) {
			$this->error( 'Неизвестный тип шаблона.' );
		}

		$body = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );

		$this->templates->saveTemplate( $type->value, $subject, $body );

		$this->success( array( 'message' => 'Шаблон сохранён.' ) );
	}

	public function ajaxResetEmailTemplate(): void {
		$this->authorize( Nonce::Manager );

		$rawType = $this->requireKey( 'type', error: 'Тип шаблона обязателен.' );
		$type    = EmailTemplateType::tryFrom( $rawType );

		if ( null === $type ) {
			$this->error( 'Неизвестный тип шаблона.' );
		}

		$this->templates->deleteTemplate( $type->value );

		$this->success( array( 'message' => 'Шаблон сброшен к умолчанию.' ) );
	}
}
