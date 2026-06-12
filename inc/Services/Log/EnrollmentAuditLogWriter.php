<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\Log\AuditLogRepository;
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
	 * @param string      $action      Тип действия (из AuditAction)
	 * @param string      $targetType  Тип цели (application, enrollment)
	 * @param int|null    $targetId    ID цели
	 * @param array|null  $details     Дополнительные детали (JSON)
	 *
	 * @return void
	 */
	public function record( string $action, string $targetType, ?int $targetId, ?array $details = null ): void {
		$ctx = $this->requestContext();
		$role = $this->resolveRole( $ctx->actorUserId );

		$this->repository->create( array(
			'actor_user_id' => $ctx->actorUserId > 0 ? $ctx->actorUserId : null,
			'actor_role'    => $role,
			'action'        => $action,
			'target_type'   => $targetType,
			'target_id'     => $targetId,
			'details_json'  => null !== $details ? wp_json_encode( $details ) : null,
			'actor_ip'      => $ctx->ip,
			'actor_ua'      => '' !== $ctx->userAgent ? $ctx->userAgent : null,
			'created_at'    => $this->clock->now( 'mysql', true ),
		) );
	}

	/**
	 * Записывает событие в журнал аудита (анонимный/неавторизованный пользователь).
	 *
	 * @param string      $action      Тип действия (из AuditAction)
	 * @param string      $targetType  Тип цели (application, enrollment)
	 * @param int|null    $targetId    ID цели
	 * @param array|null  $details     Дополнительные детали (JSON)
	 *
	 * @return void
	 */
	public function recordAnonymous( string $action, string $targetType, ?int $targetId, ?array $details = null ): void {
		$ctx = $this->requestContext();

		$this->repository->create( array(
			'actor_user_id' => null,
			'actor_role'    => null,
			'action'        => $action,
			'target_type'   => $targetType,
			'target_id'     => $targetId,
			'details_json'  => null !== $details ? wp_json_encode( $details ) : null,
			'actor_ip'      => $ctx->ip,
			'actor_ua'      => '' !== $ctx->userAgent ? $ctx->userAgent : null,
			'created_at'    => $this->clock->now( 'mysql', true ),
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