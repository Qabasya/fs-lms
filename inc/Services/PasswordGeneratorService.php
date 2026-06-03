<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Enums\AuditAction;
use Inc\Managers\UserManager;

class PasswordGeneratorService {
    # TODO: как станет больше мета - добавить enum
	private const META_KEY = 'fs_lms_enc_password';

	public function __construct(
		private readonly UserManager      $user_manager,
		private readonly AuditService     $audit_service,
		private readonly PiiCryptoService $crypto,
	) {}

	/**
	 * Генерирует пароль, устанавливает его пользователю, хранит зашифрованным в user meta.
	 * Возвращает пароль в открытом виде — для немедленного показа/отправки.
	 *
	 * @throws \RuntimeException Если пользователь не найден
	 */
	public function generateAndSet( int $user_id ): string {
		$user = $this->user_manager->find( $user_id );

		if ( null === $user ) {
			throw new \RuntimeException( "Пользователь {$user_id} не найден" );
		}

		$password = wp_generate_password( 8, false );

		wp_set_password( $password, $user_id );

		$encoded = base64_encode( $this->crypto->encrypt( $password ) );
		update_user_meta( $user_id, self::META_KEY, $encoded );

		$this->audit_service->record(
			AuditAction::PasswordGenerated->value,
			'user',
			$user_id,
		);

		return $password;
	}

	/**
	 * Возвращает логин и расшифрованный пароль пользователя.
	 * Возвращает null если зашифрованный пароль не сохранён (пользователь сменил сам).
	 *
	 * @return array{login: string, password: string}|null
	 */
	public function getCredentials( int $user_id ): ?array {
		$user = $this->user_manager->find( $user_id );

		if ( null === $user ) {
			return null;
		}

		$encrypted = get_user_meta( $user_id, self::META_KEY, true );

		if ( empty( $encrypted ) ) {
			return null;
		}

		try {
			$password = $this->crypto->decrypt( base64_decode( $encrypted, true ) );
		} catch ( \RuntimeException ) {
			return null;
		}

		return array(
			'login'    => $user->user_login,
			'password' => $password,
		);
	}

	/**
	 * Устанавливает готовый пароль (без генерации), сохраняет зашифрованным в user meta.
	 * Используется когда пользователь задал пароль самостоятельно при подаче заявки.
	 *
	 * @throws \RuntimeException Если пользователь не найден
	 */
	public function setFromPlain( int $user_id, string $password ): void {
		$user = $this->user_manager->find( $user_id );

		if ( null === $user ) {
			throw new \RuntimeException( "Пользователь {$user_id} не найден" );
		}

		wp_set_password( $password, $user_id );
		update_user_meta( $user_id, self::META_KEY, base64_encode( $this->crypto->encrypt( $password ) ) );

		$this->audit_service->record(
			AuditAction::PasswordSet->value,
			'user',
			$user_id,
		);
	}

	/**
	 * Устанавливает случайный 64-символьный пароль и удаляет сохранённый.
	 * Используется при блокировке аккаунта после удаления ПД.
	 */
	public function randomize( int $user_id ): void {
		wp_set_password( wp_generate_password( 64, true, true ), $user_id );
		delete_user_meta( $user_id, self::META_KEY );
	}
}
