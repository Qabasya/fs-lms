<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\AuditLogInputDTO;
use Inc\Enums\Log\AuditAction;
use Inc\Enums\Log\AuditTargetType;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\Log\AuditLogRepository;
use Inc\Enums\Access\UserRole;
use Inc\Shared\Traits\RequestContextProvider;

/**
 * Class EnrollmentAuditLogWriter
 *
 * Сервис для записи событий, связанных с зачислениями, в журнал аудита.
 *
 * @package Inc\Services\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Запись событий зачисления** — логирование действий: StudentEnrolled, EnrollmentFailed,
 *    StudentExpelled, StudentRestored, EnrollmentStarted, EnrollmentCanceled.
 * 2. **Анонимная запись** — для событий, выполняемых неавторизованными пользователями.
 * 3. **Сбор контекста запроса** — получение IP, User-Agent через трейт RequestContextProvider.
 * 4. **Определение роли пользователя** — получение роли через UserManager.
 *
 * ### Архитектурная роль:
 *
 * Делегирует сохранение AuditLogRepository.
 * Используется в EnrollmentAuditSubscriber для записи событий при изменении статуса зачисления.
 *
 * ### Примечания:
 *
 * - Метод record() используется для авторизованных действий (запись с actor_user_id).
 * - Метод recordAnonymous() используется для неавторизованных действий (например, создание заявки).
 * - Поле details_json может содержать дополнительный контекст (student_person_id, group_id).
 */
class EnrollmentAuditLogWriter {

	use RequestContextProvider;  // Трейт с методом requestContext() для получения IP/UA

	/**
	 * Конструктор райтера.
	 *
	 * @param AuditLogRepository $repository  Репозиторий журнала аудита
	 * @param UserManager        $userManager Менеджер пользователей
	 * @param ClockInterface     $clock       Интерфейс часов
	 */
	public function __construct(
		private readonly AuditLogRepository $repository,
		private readonly UserManager        $userManager,
		private readonly ClockInterface     $clock,
	) {}

	/**
	 * Записывает событие в журнал аудита (авторизованный пользователь).
	 *
	 * @param AuditAction     $action      Тип действия
	 * @param AuditTargetType $targetType  Тип цели
	 * @param int|null        $targetId    ID цели
	 * @param array|null      $details     Дополнительные детали (JSON)
	 *
	 * @return void
	 */
	public function record( AuditAction $action, AuditTargetType $targetType, ?int $targetId, ?array $details = null ): void {
		$ctx  = $this->requestContext();
		$role = $this->resolveRole( $ctx->actorUserId );

		$this->repository->create( new AuditLogInputDTO(
			actorUserId: $ctx->actorUserId > 0 ? $ctx->actorUserId : null,
			actorRole:   $role,
			action:      $action->value,
			targetType:  $targetType->value,
			targetId:    $targetId,
			detailsJson: null !== $details ? wp_json_encode( $details ) : null,
			actorIp:     $ctx->ip,
			actorUa:     '' !== $ctx->userAgent ? $ctx->userAgent : null,
			createdAt:   $this->clock->now( 'mysql', true ),
		) );
	}

	/**
	 * Записывает событие в журнал аудита (анонимный/неавторизованный пользователь).
	 *
	 * @param AuditAction     $action      Тип действия
	 * @param AuditTargetType $targetType  Тип цели
	 * @param int|null        $targetId    ID цели
	 * @param array|null      $details     Дополнительные детали (JSON)
	 *
	 * @return void
	 */
	public function recordAnonymous( AuditAction $action, AuditTargetType $targetType, ?int $targetId, ?array $details = null ): void {
		$ctx = $this->requestContext();

		$this->repository->create( new AuditLogInputDTO(
			actorUserId: null,
			actorRole:   null,
			action:      $action->value,
			targetType:  $targetType->value,
			targetId:    $targetId,
			detailsJson: null !== $details ? wp_json_encode( $details ) : null,
			actorIp:     $ctx->ip,
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
		return UserRole::primarySlug( (array) $user->roles );
	}
}