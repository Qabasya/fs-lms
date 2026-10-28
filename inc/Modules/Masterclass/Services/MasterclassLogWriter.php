<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Services;

use Inc\Contracts\ClockInterface;
use Inc\Modules\Masterclass\DTO\MasterclassLogInputDTO;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Repositories\MasterclassLogRepository;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\RequestContextProvider;

/**
 * Class MasterclassLogWriter
 *
 * Запись действий с мастер-классами в журнал модуля. Образец — `ExamLogWriter`.
 *
 * Событие пишется ПОСЛЕ успешной операции и вне блокировок; сбой записи никогда не прерывает само
 * действие — он уходит только в PHP-лог. Пароли и персональные данные в журнал не попадают:
 * подпись объекта — «Ученик#N» (номер аккаунта и есть его `id`, запрос в БД не нужен), а из `context`
 * defensively убираются ключи, похожие на секреты.
 *
 * ### Ключи `$data`
 *
 * - `masterclass_id`, `account_id` — объект действия (положительные числа);
 * - `actor` — id пользователя WP; не задан и `actor_type` не задан — берётся пользователь текущего запроса;
 * - `actor_type` — `user` | `guest` | `system` (ребёнок под временным аккаунтом — `user`, тик — `system`);
 * - `actor_label` — подпись актора; не задана — имя пользователя из профиля;
 * - `result` — `success` (по умолчанию) | `failure`;
 * - `context` — массив без паролей и ПД.
 *
 * @package Inc\Modules\Masterclass\Services
 */
class MasterclassLogWriter {

	use RequestContextProvider;

	/** Ключи контекста, которые не пишутся в журнал ни при каких условиях. */
	private const SECRET_KEY_PATTERN = '/pass|pwd|secret|token|nonce/i';

	public function __construct(
		private readonly MasterclassLogRepository $repository,
		private readonly ClockInterface $clock,
	) {}

	/**
	 * Пишет действие; любые сбои гасит.
	 *
	 * @param array<string, mixed> $data Данные действия, см. описание класса.
	 */
	public function write( MasterclassLogAction $action, array $data = array() ): void {
		try {
			$this->repository->create( $this->input( $action, $data ) );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'MasterclassLog', $e, array( 'action' => $action->value ), true );
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function input( MasterclassLogAction $action, array $data ): MasterclassLogInputDTO {
		$accountId = $this->id( $data, 'account_id' );

		// Актор не назван и тип не задан — это действие пользователя текущего запроса; у крона и тика тип
		// задаёт вызывающий код (`system`), у ребёнка под временным аккаунтом актор — он сам.
		$explicitType = isset( $data['actor_type'] ) ? (string) $data['actor_type'] : null;
		$actorUserId  = $this->id( $data, 'actor' ) ?? ( null === $explicitType && get_current_user_id() > 0 ? get_current_user_id() : null );
		$actorType    = $explicitType ?? ( null !== $actorUserId ? 'user' : 'system' );
		$actorLabel   = isset( $data['actor_label'] ) ? (string) $data['actor_label'] : $this->userLabel( $actorUserId );
		$context      = is_array( $data['context'] ?? null ) ? $this->scrub( $data['context'] ) : array();

		return new MasterclassLogInputDTO(
			action:        $action,
			result:        'failure' === ( $data['result'] ?? 'success' ) ? 'failure' : 'success',
			actorType:     $actorType,
			actorUserId:   $actorUserId,
			actorLabel:    $actorLabel,
			masterclassId: $this->id( $data, 'masterclass_id' ),
			accountId:     $accountId,
			subjectLabel:  null !== $accountId ? 'Ученик#' . $accountId : null,
			actorIp:       $this->requestContext()->ip,
			context:       $context,
			createdAt:     $this->clock->now( 'mysql', true ),
		);
	}

	/**
	 * Положительный id из данных или null.
	 *
	 * @param array<string, mixed> $data
	 */
	private function id( array $data, string $key ): ?int {
		$value = (int) ( $data[ $key ] ?? 0 );

		return $value > 0 ? $value : null;
	}

	/** Имя пользователя из профиля WP; без пользователя — null. */
	private function userLabel( ?int $userId ): ?string {
		if ( null === $userId ) {
			return null;
		}

		$user = get_userdata( $userId );

		return $user ? (string) $user->display_name : '#' . $userId;
	}

	/**
	 * Убирает из контекста (на любой глубине) ключи, похожие на пароли и токены.
	 *
	 * @param array<mixed> $context
	 *
	 * @return array<mixed>
	 */
	private function scrub( array $context ): array {
		$clean = array();

		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && 1 === preg_match( self::SECRET_KEY_PATTERN, $key ) ) {
				continue;
			}
			$clean[ $key ] = is_array( $value ) ? $this->scrub( $value ) : $value;
		}

		return $clean;
	}
}
