<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Log;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Auth\AuthAction;
use Inc\Enums\Auth\AuthResult;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Log\AuthLogWriter;
use Inc\Services\Security\LoginBlockService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class LoginSecurityCallbacks extends BaseController {
	use Authorizer;
	use Sanitizer;

	public function __construct( private readonly LoginBlockService $blocks, private readonly AuthLogWriter $authLog ) {
		parent::__construct();
	}

	public function ajaxUnlockLoginIp(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );
		$ip = $this->requireText( 'ip', error: 'Укажите IP-адрес.' );
		$wasBlocked = $this->blocks->unblockIpWithStatus( $ip );
		if ( null === $wasBlocked ) {
			$this->error( 'Некорректный IP-адрес.' );
			return;
		}
		$this->authLog->recordEvent( AuthAction::LoginUnlocked, AuthResult::Success, null, array( 'note' => sprintf( '%s IP %s администратором ID %d', $wasBlocked ? 'Снят блок' : 'Сброшен счётчик', $ip, get_current_user_id() ) ) );
		$this->success( array( 'message' => $wasBlocked
			? 'Блокировка IP снята. Блокировка учётной записи снимается отдельно.'
			: 'Постоянной блокировки этого IP не было; счётчик сброшен. Проверьте блокировку учётной записи.' ) );
	}
}
