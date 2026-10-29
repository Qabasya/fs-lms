<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\Masterclass\Enums\MasterclassContactKind;
use Inc\Modules\Masterclass\Services\MasterclassConversionService;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class MasterclassPlayerCallbacks
 *
 * AJAX окна «Связаться» в плеере. Права нет — это действие самого ребёнка под его сессией, поэтому по правилам проекта
 * только nonce (`Nonce::LearnerActivity`, тот же, что у журнала активности ученика) и вход.
 *
 * Ответ успеха один и тот же, кто бы ни спросил: по нему нельзя узнать, аккаунт ли это мастер-класса.
 *
 * @package Inc\Modules\Masterclass\Callbacks
 */
class MasterclassPlayerCallbacks extends BaseController {

	use Sanitizer;

	public function __construct(
		private readonly MasterclassConversionService $conversion,
	) {
		parent::__construct();
	}

	public function ajaxContact(): void {
		Nonce::LearnerActivity->verify();

		$userId = get_current_user_id();
		if ( $userId <= 0 ) {
			$this->error( 'Требуется вход.' );
			return;
		}

		$kind = MasterclassContactKind::fromRequest( $this->sanitizeKey( 'kind' ) );
		if ( null === $kind ) {
			$this->error( 'Неизвестное действие.' );
			return;
		}

		$this->conversion->logContact( $userId, $kind );
		$this->success( array() );
	}
}
