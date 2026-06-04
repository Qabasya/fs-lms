<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Contracts\ClockInterface;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\AuditLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

/**
 * Class AuditService
 *
 * Единая точка записи в журнал аудита системы зачисления.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Централизованная запись аудита** — все действия логируются только через этот сервис.
 *    Прямые вызовы AuditLogRepository::create() из бизнес-кода запрещены.
 * 2. **Автоматический сбор контекста** — IP, User-Agent и ID актора собираются
 *    автоматически из текущего HTTP-запроса через трейт RequestContextProvider.
 * 3. **Поддержка анонимных событий** — recordAnonymous() для публичных endpoint-ов,
 *    где авторизация отсутствует (actor_user_id = 0, actor_role = null).
 *
 * ### Архитектурная роль:
 *
 * Делегирует запись в AuditLogRepository. Не содержит бизнес-логики —
 * только сборку контекста и формирование payload для репозитория.
 *
 * ### Требования к details:
 *
 * В details_json писать только метаданные изменений — никогда не писать значения PII.
 * Допустимо: хэши, названия изменённых полей, ID сущностей.
 * Пример: ['changed_fields' => ['phone', 'address']]
 */
readonly class AuditService {

	use RequestContextProvider;

	/**
	 * Конструктор сервиса.
	 *
	 * @param AuditLogRepository $auditLogRepository Репозиторий журнала аудита
	 * @param UserManager        $userManager        Менеджер пользователей
	 */
	public function __construct(
		private AuditLogRepository $auditLogRepository,
		private UserManager        $userManager,
		private ClockInterface     $clock,
	) {}

	/**
	 * Записывает событие аудита от авторизованного пользователя.
	 *
	 * Собирает контекст запроса (actor, IP, UA) автоматически.
	 * Роль актора определяется по текущему WP-пользователю на момент вызова.
	 *
	 * @param string     $action     Действие из AuditAction::value
	 * @param string     $targetType Тип целевой сущности (application, enrollment, person)
	 * @param int|null   $targetId   ID целевой сущности
	 * @param array|null $details    Метаданные события (без значений PII)
	 *
	 * @return void
	 */
	public function record(
		string $action,
		string $targetType,
		?int $targetId,
		?array $details = null,
	): void {
		$ctx       = $this->requestContext();
		$actorRole = $this->resolveActorRole( $ctx->actorUserId );

		$this->auditLogRepository->create( array(
			'actor_user_id' => $ctx->actorUserId > 0 ? $ctx->actorUserId : null,
			'actor_role'    => $actorRole,
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
	 * Записывает событие аудита от анонимного пользователя.
	 *
	 * Используется для публичных endpoint-ов (/lms/apply, /lms/join/{code}),
	 * где авторизация не требуется. actor_user_id всегда null, actor_role — null.
	 *
	 * @param string     $action     Действие из AuditAction::value
	 * @param string     $targetType Тип целевой сущности
	 * @param int|null   $targetId   ID целевой сущности
	 * @param array|null $details    Метаданные события (без значений PII)
	 *
	 * @return void
	 */
	public function recordAnonymous(
		string $action,
		string $targetType,
		?int $targetId,
		?array $details = null,
	): void {
		$ctx = $this->requestContext();

		$this->auditLogRepository->create( array(
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
	 * Определяет первую роль WP-пользователя через UserManager.
	 *
	 * @param int $userId ID пользователя WordPress
	 *
	 * @return string|null Название роли или null для анонимных/не найденных
	 */
	private function resolveActorRole( int $userId ): ?string {
		if ( $userId <= 0 ) {
			return null;
		}

		$user = $this->userManager->find( $userId );

		if ( null === $user || empty( $user->roles ) ) {
			return null;
		}

		return (string) reset( $user->roles );
	}
}