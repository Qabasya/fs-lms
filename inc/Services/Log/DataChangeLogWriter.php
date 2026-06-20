<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\DataChangeLogInputDTO;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\Log\DataChangeLogRepository;
use Inc\Services\Security\PiiCryptoService;
use Inc\Shared\Traits\RequestContextProvider;

/**
 * Class DataChangeLogWriter
 *
 * Сервис для записи изменений персональных данных в журнал аудита.
 *
 * @package Inc\Services\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Запись изменений полей лица** — логирование изменений ФИО, документов, контактов.
 * 2. **Шифрование значений** — старые и новые значения хранятся в зашифрованном виде (BLOB).
 * 3. **Сбор контекста запроса** — получение IP, User-Agent через трейт RequestContextProvider.
 * 4. **Определение роли пользователя** — получение роли через UserManager.
 *
 * ### Архитектурная роль:
 *
 * Делегирует сохранение DataChangeLogRepository.
 * Используется в DataChangeSubscriber для записи событий при изменении данных лица.
 *
 * ### Примечания:
 *
 * - Старые и новые значения шифруются через PiiCryptoService для защиты PII.
 * - Расшифровка возможна только при наличии соответствующих прав.
 * - Лог изменений данных важен для аудита и отката изменений.
 */
class DataChangeLogWriter {

	use RequestContextProvider;  // Трейт с методом requestContext() для получения IP/UA

	/**
	 * Конструктор райтера.
	 *
	 * @param DataChangeLogRepository $repository  Репозиторий журнала изменений данных
	 * @param PiiCryptoService        $crypto      Сервис шифрования PII
	 * @param UserManager             $userManager Менеджер пользователей
	 * @param ClockInterface          $clock       Интерфейс часов
	 */
	public function __construct(
		private readonly DataChangeLogRepository $repository,
		private readonly PiiCryptoService        $crypto,
		private readonly UserManager             $userManager,
		private readonly ClockInterface          $clock,
	) {}

	/**
	 * Записывает изменение персональных данных в журнал.
	 *
	 * @param int         $targetPersonId ID лица (из persons), чьи данные изменены
	 * @param string      $fieldName      Название изменённого поля
	 * @param string|null $oldValue       Старое значение в открытом виде (будет зашифровано)
	 * @param string|null $newValue       Новое значение в открытом виде (будет зашифровано)
	 *
	 * @return void
	 */
	public function record( int $targetPersonId, string $fieldName, ?string $oldValue, ?string $newValue ): void {
		$ctx = $this->requestContext();
		$role = $this->resolveRole( $ctx->actorUserId );

		// Шифрование значений (пустые строки сохраняются как null)
		$oldEnc = null !== $oldValue && '' !== $oldValue ? $this->crypto->encrypt( $oldValue ) : null;
		$newEnc = null !== $newValue && '' !== $newValue ? $this->crypto->encrypt( $newValue ) : null;

		$this->repository->create( new DataChangeLogInputDTO(
			actorUserId:    $ctx->actorUserId > 0 ? $ctx->actorUserId : 0,
			actorRole:      $role,
			targetPersonId: $targetPersonId,
			fieldName:      $fieldName,
			oldValueEnc:    $oldEnc,
			newValueEnc:    $newEnc,
			createdAt:      $this->clock->now( 'mysql', true ),
		) );
	}

	/**
	 * Определяет роль пользователя по ID.
	 *
	 * @param int $userId ID пользователя WordPress
	 *
	 * @return string|null
	 */
	private function resolveRole( int $userId ): ?string {
		if ( $userId <= 0 ) {
			return null;
		}
		$user = $this->userManager->find( $userId );
		if ( null === $user || empty( $user->roles ) ) {
			return null;
		}
		// reset() — возвращает первый элемент массива (первую роль пользователя)
		return (string) reset( $user->roles );
	}
}