<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\AdSync\Services\AdProvisioningService;
use Inc\Modules\AdSync\Services\AdStatusTokenService;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class AdSyncStatusCallbacks
 *
 * nopriv-AJAX статуса провижна для фронт-поллинга. Заявка адресуется токеном
 * из AdStatusTokenService, а не сырым ID: невалидный/протухший токен неотличим
 * от «ещё создаём» — существование заявок наружу не раскрывается.
 *
 * @package Inc\Modules\AdSync\Callbacks
 */
class AdSyncStatusCallbacks extends BaseController {

	use Sanitizer;

	public function __construct(
		private readonly AdProvisioningService $service,
		private readonly AdStatusTokenService  $tokens,
	) {
		parent::__construct();
	}

	/** nopriv-AJAX: статус провижна по токену опроса. */
	public function ajaxStatus(): void {
		Nonce::Apply->verify();

		$token = $this->sanitizeKey( 'ref' );
		$appId = '' !== $token ? $this->tokens->resolve( $token ) : 0;
		$state = $appId > 0 ? $this->service->statusForApplication( $appId ) : 'none';

		// TODO(текст): сообщения статусов (готово / ошибка / в процессе).
		$messages = array(
			'done'    => 'Готово! Войдите в учётную запись на компьютере.',
			'failed'  => 'Не удалось создать учётную запись. Обратитесь к администратору.',
			'pending' => 'Создаём учётную запись в домене…',
			'none'    => 'Создаём учётную запись в домене…',
		);

		$this->success( array(
			'state'   => $state,
			'message' => $messages[ $state ] ?? '',
		) );
	}
}
