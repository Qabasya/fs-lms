<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Enrollment;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Core\BaseController;
use Inc\DTO\Log\Events\PiiRevealedEvent;
use Inc\Enums\Access\Capability;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Person\PiiAccessReason;
use Inc\Enums\Person\PiiField;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Person\UserManager;
use Inc\Services\Security\PasswordGeneratorService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class UserCredentialsCallbacks
 *
 * Учётные данные пользователей в карточке заявки/зачисления: показ логина
 * и расшифрованного пароля (с PII-логированием) и перегенерация пароля,
 * когда reveal недоступен (пароль сменён вручную).
 *
 * Выделен из EnrollmentCallbacks (Т14.2): отдельный класс — у reveal-операций
 * свой нонс (RevealPii) и свои события аудита PII.
 *
 * @package Inc\Callbacks\Enrollment
 */
class UserCredentialsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly PasswordGeneratorService    $passwordGenerator,
		private readonly UserManager                 $userManager,
		private readonly LogEventDispatcherInterface $logEvents,
	) {
		parent::__construct();
	}

	/**
	 * AJAX: возвращает логин и расшифрованный пароль пользователя.
	 * Используется кнопкой "Показать логин+пароль" в карточке заявки/зачисления.
	 *
	 * Принимает: user_id (int)
	 *
	 * @return void
	 */
	public function ajaxRevealUserCredentials(): void {
		$this->authorize( Nonce::RevealPii, Capability::ManageApplications );

		$user_id = $this->requireInt( 'user_id', error: 'ID пользователя не указан.' );

		$credentials = $this->passwordGenerator->getCredentials( $user_id );

		if ( null === $credentials ) {
			$this->error( 'Пароль недоступен. Пользователь сменил пароль самостоятельно — воспользуйтесь функцией сброса.' );

			return;
		}

		$actor_id = get_current_user_id();
		$personId = $this->userManager->getPersonId( $user_id );

		$this->logEvents->dispatch( LogEvent::PiiRevealed, new PiiRevealedEvent(
			actorUserId:    $actor_id,
			targetPersonId: $personId ?: null,
			fieldsAccessed: PiiField::Login->value . ',' . PiiField::Password->value,
			accessReason:   PiiAccessReason::AdminRevealCredentials->value,
		) );

		$this->success( $credentials );
	}

	/**
	 * AJAX: генерирует новый пароль для пользователя и сохраняет зашифрованную копию в meta.
	 * Вызывается когда admin_reveal_credentials вернул null (пароль был сменён вручную).
	 *
	 * @return void
	 */
	public function ajaxRegenerateUserPassword(): void {
		$this->authorize( Nonce::RevealPii, Capability::ManageApplications );

		$user_id  = $this->requireInt( 'user_id', error: 'ID пользователя не указан.' );
		$password = $this->passwordGenerator->generateAndSet( $user_id );

		$this->success( array( 'password' => $password ) );
	}
}
