<?php

declare( strict_types=1 );

namespace Inc\Services\Security;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\EntityType;
use Inc\Enums\LogEvent;
use Inc\Enums\MetaKeys;
use Inc\Enums\OperationType;
use Inc\Managers\UserManager;
use Inc\Repositories\OptionsRepositories\UserRepository;

/**
 * Class PasswordGeneratorService
 *
 * Сервис для генерации, установки и хранения паролей пользователей WordPress.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Генерация паролей** — создание случайных паролей разной сложности.
 * 2. **Установка паролей** — установка пароля через wp_set_password() и сохранение
 *    зашифрованной копии в мета-поле для последующего извлечения.
 * 3. **Получение учётных данных** — возврат логина и расшифрованного пароля.
 * 4. **Аудит действий** — логирование генерации и установки паролей.
 *
 * ### Архитектурная роль:
 *
 * Делегирует работу с пользователями UserManager, шифрование — PiiCryptoService,
 * хранение — UserRepository, события — LogEventDispatcher.
 *
 * ### Примечания:
 *
 * - Пароль сохраняется в мета-поле fs_lms_enc_password в зашифрованном виде (base64 + шифрование).
 * - Это позволяет администраторам видеть пароли пользователей при необходимости.
 * - При самостоятельной смене пароля пользователем, зашифрованная копия перестаёт быть актуальной.
 * - Метод randomize() используется при удалении персональных данных (анонимизация аккаунта).
 */
class PasswordGeneratorService {

	/**
	 * Конструктор сервиса.
	 *
	 * @param UserManager                $user_manager    Менеджер пользователей
	 * @param PiiCryptoService           $crypto          Сервис шифрования PII
	 * @param UserRepository             $user_repository Репозиторий пользователей WP
	 * @param LogEventDispatcherInterface $logEvents      Диспетчер событий логирования
	 */
	public function __construct(
		private readonly UserManager                $user_manager,
		private readonly PiiCryptoService           $crypto,
		private readonly UserRepository             $user_repository,
		private readonly LogEventDispatcherInterface $logEvents,
	) {}

	/**
	 * Генерирует пароль в открытом виде без сохранения.
	 * Используется когда пользователь ещё не создан (ID неизвестен).
	 *
	 * @return string
	 */
	public function generatePlain(): string {
		// wp_generate_password() — генерация случайного пароля
		return wp_generate_password( 8, false );
	}

	/**
	 * Генерирует пароль, устанавливает его пользователю, хранит зашифрованным в user meta.
	 * Возвращает пароль в открытом виде — для немедленного показа/отправки.
	 *
	 * @param int $user_id ID пользователя
	 *
	 * @throws \RuntimeException Если пользователь не найден
	 *
	 * @return string
	 */
	public function generateAndSet( int $user_id ): string {
		$user = $this->user_manager->find( $user_id );

		if ( null === $user ) {
			throw new \RuntimeException( "Пользователь {$user_id} не найден" );
		}

		$password = wp_generate_password( 8, false );

		// Установка пароля в WordPress
		wp_set_password( $password, $user_id );

		// Сохранение зашифрованной копии в мета-поле
		$encoded = base64_encode( $this->crypto->encrypt( $password ) );

		$this->user_repository->updateMeta(
			$user_id,
			array(
				MetaKeys::EncPassword->value => $encoded,
			)
		);

		$this->logEvents->dispatch( LogEvent::UserUpdated, new EntityChangedEvent(
			get_current_user_id(), OperationType::Update, EntityType::User, $user_id, 'password_generated'
		) );

		return $password;
	}

	/**
	 * Возвращает логин и расшифрованный пароль пользователя.
	 * Возвращает null, если зашифрованный пароль не сохранён (пользователь сменил сам).
	 *
	 * @param int $user_id ID пользователя
	 *
	 * @return array{login: string, password: string}|null
	 */
	public function getCredentials( int $user_id ): ?array {
		$user = $this->user_manager->find( $user_id );

		if ( null === $user ) {
			return null;
		}

		// Получение зашифрованного пароля из мета-поля
		$encrypted = $this->user_repository->getMeta(
			$user_id,
			MetaKeys::EncPassword->value
		);

		if ( empty( $encrypted ) ) {
			return null;
		}

		try {
			// Расшифровка пароля
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
	 * @param int    $user_id  ID пользователя
	 * @param string $password Пароль в открытом виде
	 *
	 * @throws \RuntimeException Если пользователь не найден
	 *
	 * @return void
	 */
	public function setFromPlain( int $user_id, string $password ): void {
		$user = $this->user_manager->find( $user_id );

		if ( null === $user ) {
			throw new \RuntimeException( "Пользователь {$user_id} не найден" );
		}

		// Установка пароля в WordPress
		wp_set_password( $password, $user_id );

		// Сохранение зашифрованной копии
		$this->user_repository->updateMeta( $user_id, array(
			MetaKeys::EncPassword->value => base64_encode(
				$this->crypto->encrypt( $password )
			)
		) );

		$this->logEvents->dispatch( LogEvent::UserUpdated, new EntityChangedEvent(
			get_current_user_id(), OperationType::Update, EntityType::User, $user_id, 'password_set'
		) );
	}

	/**
	 * Сохраняет зашифрованную копию пароля в user meta без вызова wp_set_password().
	 * Используется когда пароль уже установлен через wp_insert_user().
	 *
	 * @param int    $user_id  ID пользователя
	 * @param string $password Пароль в открытом виде
	 *
	 * @return void
	 */
	public function storeEncrypted( int $user_id, string $password ): void {
		$this->user_repository->updateMeta(
			$user_id,
			array(
				MetaKeys::EncPassword->value => base64_encode(
					$this->crypto->encrypt( $password )
				)
			)
		);

		$this->logEvents->dispatch( LogEvent::UserUpdated, new EntityChangedEvent(
			get_current_user_id(), OperationType::Update, EntityType::User, $user_id, 'password_set'
		) );
	}

	/**
	 * Устанавливает случайный 64-символьный пароль и удаляет сохранённый.
	 * Используется при блокировке аккаунта после удаления персональных данных.
	 *
	 * @param int $user_id ID пользователя
	 *
	 * @return void
	 */
	public function randomize( int $user_id ): void {
		// Установка случайного длинного пароля
		wp_set_password( wp_generate_password( 64, true, true ), $user_id );

		// Удаление зашифрованной копии (чтобы невозможно было восстановить)
		$this->user_repository->deleteMeta(
			$user_id,
			MetaKeys::EncPassword->value
		);
	}
}