<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\ConsentChangeLogInputDTO;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\Log\ConsentChangeLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

/**
 * Class ConsentChangeLogWriter
 *
 * Сервис для записи изменений согласий в журнал аудита.
 *
 * @package Inc\Services\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Запись изменений версий согласий** — логирование обновления документа согласия
 *    (старый и новый хеш).
 * 2. **Сбор контекста запроса** — получение IP, User-Agent через трейт RequestContextProvider.
 * 3. **Определение роли пользователя** — получение роли через UserManager.
 *
 * ### Архитектурная роль:
 *
 * Делегирует сохранение ConsentChangeLogRepository.
 * Используется в ConsentChangeSubscriber для записи событий при изменении версии согласия.
 *
 * ### Примечания:
 *
 * - Лог изменений согласий важен для отслеживания, когда и кем была изменена версия
 *   документа согласия (с учётом требований 152-ФЗ).
 * - Старый и новый хеши позволяют сравнить различия между версиями документа.
 */
class ConsentChangeLogWriter {

	use RequestContextProvider;  // Трейт с методом requestContext() для получения IP/UA

	/**
	 * Конструктор райтера.
	 *
	 * @param ConsentChangeLogRepository $repository Репозиторий журнала изменений согласий
	 * @param UserManager                $userManager Менеджер пользователей
	 * @param ClockInterface             $clock      Интерфейс часов
	 */
	public function __construct(
		private readonly ConsentChangeLogRepository $repository,
		private readonly UserManager                $userManager,
		private readonly ClockInterface             $clock,
	) {}

	/**
	 * Записывает изменение согласия в журнал.
	 *
	 * @param int|null    $personId    ID лица (из persons), чьё согласие изменилось (null при анонимном подписании)
	 * @param string      $consentType Тип согласия (pd_processing, marketing и т.д.)
	 * @param string|null $oldHash     SHA-256 хеш старой версии документа согласия
	 * @param string|null $newHash     SHA-256 хеш новой версии документа согласия
	 *
	 * @return void
	 */
	public function record( ?int $personId, string $consentType, ?string $oldHash, ?string $newHash ): void {
		$ctx = $this->requestContext();

		// Определение роли пользователя (если ID > 0)
		$role = $this->resolveRole( $ctx->actorUserId );

		$this->repository->create( new ConsentChangeLogInputDTO(
			actorUserId: $ctx->actorUserId > 0 ? $ctx->actorUserId : null,
			actorRole:   $role,
			personId:    $personId,
			consentType: $consentType,
			oldHash:     $oldHash,
			newHash:     $newHash,
			actorIp:     '' !== $ctx->ip ? $ctx->ip : null,
			actorUa:     '' !== $ctx->userAgent ? $ctx->userAgent : null,
			createdAt:   $this->clock->now( 'mysql', true ),
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