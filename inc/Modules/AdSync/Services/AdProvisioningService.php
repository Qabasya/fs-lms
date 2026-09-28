<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Services;

use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Managers\Person\UserManager;
use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\DTO\AdOutboxItemDTO;
use Inc\Modules\AdSync\Enums\AdSyncEvent;
use Inc\Modules\AdSync\Repositories\AdOutboxRepository;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Security\PasswordGeneratorService;
use Inc\Services\Security\PiiCryptoService;

/**
 * Class AdProvisioningService
 *
 * Постановка заданий синхронизации с AD в очередь и сборка их тела. Доставку в офис
 * (push: сайт сам шлёт задание серверу в офисе) делает {@see AdDeliveryService}.
 *
 * Идентификатор учётки в AD — `username` (sAMAccountName, он же логин WP). Логин резолвится
 * **при enqueue** и кладётся в `target` — по нему видно, что сайт последним делал с учёткой
 * ({@see AdOutboxRepository::latestByTarget()}). Пароль в очереди не хранится никогда: он
 * читается в момент отправки — из заявки или из зашифрованной копии пароля пользователя.
 *
 * Жизненный цикл учётки:
 *  - **заявка подана** (направление из provision_subjects) → `provision` в OU направления;
 *  - **заявка истекла / в корзине** → `deprovision` (OU=Отчисленные);
 *  - **отчислен** и не осталось других активных зачислений → `deprovision`;
 *  - **снова зачислен** на направление с доменной учёткой, а учётка сейчас в «Отчисленных» →
 *    `provision`: сервис в офисе реактивирует её и переносит в OU нового направления.
 *    Восстановление из архива само по себе учётку не трогает — направление ещё не выбрано;
 *  - **администратор сменил пароль** → `password`, тот же пароль ставится в AD.
 *
 * @package Inc\Modules\AdSync\Services
 */
class AdProvisioningService {

	public function __construct(
		private readonly AdOutboxRepository       $outbox,
		private readonly ApplicationRepository    $applications,
		private readonly PiiCryptoService         $crypto,
		private readonly PersonRepository         $persons,
		private readonly UserManager              $users,
		private readonly AdSyncConfig             $config,
		private readonly StudentRecordRepository  $records,
		private readonly GroupsRepository         $groups,
		private readonly PasswordGeneratorService $passwords,
	) {}

	/**
	 * Provision по заявке: логин/пароль читаются при отправке из блоба заявки.
	 * Ставится только для направлений из provision_subjects — остальным доменная учётка не нужна.
	 */
	public function enqueueProvision( int $applicationId ): void {
		$app = $this->applications->find( $applicationId );
		if ( null === $app || ! $this->config->shouldProvision( $app->subjectKey ?? null ) ) {
			return;
		}
		$username = $this->usernameFromApplication( $applicationId );
		$this->outbox->enqueue( array(
			'event'           => AdSyncEvent::Provision->value,
			'application_id'  => $applicationId,
			'target'          => '' !== $username ? $username : null,
			'idempotency_key' => 'app:' . $applicationId,
		) );
	}

	/** Deprovision по заявке (истекла/в корзину): username резолвим из блоба сейчас и кладём в target. */
	public function enqueueDeprovisionByApplication( int $applicationId ): void {
		// Provision по этой заявке не ставился (направление вне provision_subjects) — деправижнить нечего.
		if ( null === $this->outbox->latestByApplication( $applicationId ) ) {
			return;
		}
		$username = $this->usernameFromApplication( $applicationId );
		if ( '' === $username ) {
			return;
		}
		$this->outbox->enqueue( array(
			'event'           => AdSyncEvent::Deprovision->value,
			'application_id'  => $applicationId,
			'target'          => $username,
			'idempotency_key' => 'deprovision:app:' . $applicationId,
		) );
	}

	/**
	 * Deprovision по факту отчисления — только если у ученика не осталось других активных
	 * зачислений: отчисление с одного направления не должно отключать учётку, по которой он
	 * продолжает учиться на другом.
	 *
	 * Хук отчисления срабатывает ПОСЛЕ мягкого удаления лица (когда зачислений не осталось),
	 * поэтому логин берётся с учётом удалённых ({@see usernameFromPerson()}).
	 */
	public function enqueueDeprovisionByPerson( int $personId ): void {
		if ( array() !== $this->activeRecords( $personId ) ) {
			return;
		}
		$username = $this->usernameFromPerson( $personId );
		if ( '' === $username ) {
			return;
		}
		$this->outbox->enqueue( array(
			'event'           => AdSyncEvent::Deprovision->value,
			'person_id'       => $personId,
			'target'          => $username,
			'idempotency_key' => 'deprovision:person:' . $personId,
		) );
	}

	/**
	 * Повторное зачисление: учётка сейчас в «Отчисленных» (последним заданием по ней был
	 * deprovision), а новое направление — с доменной учёткой → provision. Сервис в офисе
	 * реактивирует учётку, переносит в OU нового направления и ставит текущий пароль.
	 *
	 * Первое зачисление после заявки сюда не попадает: учётка уже создана по заявке и активна.
	 */
	public function enqueueReactivation( int $recordId, int $personId ): void {
		$record = $this->records->find( $recordId );
		if ( null === $record || $record->isTrial || ! $this->config->shouldProvision( $this->subjectOf( $record ) ) ) {
			return;
		}
		$username = $this->usernameFromPerson( $personId );
		if ( '' === $username || ! $this->isDeprovisioned( $username ) ) {
			return;
		}
		$this->outbox->enqueue( array(
			'event'           => AdSyncEvent::Provision->value,
			'person_id'       => $personId,
			'target'          => $username,
			'idempotency_key' => 'reenroll:record:' . $recordId,
		) );
	}

	/**
	 * Администратор сменил пароль ученика → тот же пароль в AD. Только для учёток, которыми
	 * управляет сайт: по логину уже были задания, либо ученик учится на направлении с
	 * доменной учёткой. Учётка в «Отчисленных» пропускается — пароль уйдёт с reactivation.
	 */
	public function enqueuePasswordChange( int $wpUserId ): void {
		$person   = $this->persons->findByWpUserId( $wpUserId );
		$username = (string) ( $this->users->find( $wpUserId )?->user_login ?? '' );
		if ( null === $person || '' === $username || $this->isDeprovisioned( $username ) ) {
			return;
		}

		$managed = null !== $this->outbox->latestByTarget( $username );
		foreach ( $this->activeRecords( $person->id ) as $record ) {
			$managed = $managed || $this->config->shouldProvision( $this->subjectOf( $record ) );
		}
		if ( ! $managed ) {
			return;
		}

		$this->outbox->enqueue( array(
			'event'           => AdSyncEvent::Password->value,
			'person_id'       => $person->id,
			'target'          => $username,
			// Каждая смена — своё задание: ключ журнала сервиса, не барьер.
			'idempotency_key' => 'password:person:' . $person->id . ':' . time(),
		) );
	}

	/** Статус провижна по заявке для статус-поллинга фронта: pending|done|failed|none. */
	public function statusForApplication( int $applicationId ): string {
		$row = $this->outbox->latestByApplication( $applicationId );
		if ( null === $row ) {
			return 'none';
		}
		return match ( $row->status ) {
			'sent'  => 'done',
			'dead'  => 'failed',
			default => 'pending',
		};
	}

	/**
	 * Тело задания для сервера в офисе (`POST /v1/jobs`) по типу события.
	 * null — отправлять нечего: заявку/ученика удалили, данных учётки или пароля нет.
	 *
	 * @return array<string, mixed>|null
	 */
	public function payloadFor( AdOutboxItemDTO $row ): ?array {
		return match ( $row->event ) {
			AdSyncEvent::Provision->value => null !== $row->applicationId
				? $this->provisionPayload( $row )
				: $this->reactivationPayload( $row ),
			AdSyncEvent::Password->value  => $this->passwordPayload( $row ),
			default                       => $this->deprovisionPayload( $row ),
		};
	}

	/** @return array<string, mixed>|null */
	private function deprovisionPayload( AdOutboxItemDTO $row ): ?array {
		$username = (string) ( $row->target ?? '' );
		if ( '' === $username ) {
			return null;
		}
		return array(
			'id'              => $row->id,
			'event'           => $row->event,
			'idempotency_key' => $row->idempotencyKey,
			'username'        => $username,
		);
	}

	/** @return array<string, mixed>|null */
	private function provisionPayload( AdOutboxItemDTO $row ): ?array {
		$app = $this->applications->find( (int) $row->applicationId );
		if ( null === $app || empty( $app->studentDataEnc ) ) {
			return null;
		}

		$blob = json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true ) ?? array();

		return array(
			'id'              => $row->id,
			'event'           => $row->event,
			'idempotency_key' => $row->idempotencyKey,
			'username'        => (string) ( $blob['username'] ?? '' ),
			'password'        => (string) ( $blob['login_password'] ?? '' ),
			'first'           => (string) ( $blob['first_name'] ?? '' ),
			'last'            => (string) ( $blob['last_name'] ?? '' ),
			'subject_key'     => (string) ( $app->subjectKey ?? '' ), // сервис выбирает OU направления по нему
		);
	}

	/**
	 * Provision повторного зачисления: ФИО — из лица, пароль — текущий (зашифрованная копия),
	 * направление — действующее зачисление с доменной учёткой (свежее — первым).
	 *
	 * @return array<string, mixed>|null
	 */
	private function reactivationPayload( AdOutboxItemDTO $row ): ?array {
		$person = null !== $row->personId ? $this->persons->findIncludingDeleted( $row->personId ) : null;
		$creds  = null !== $person && null !== $person->wpUserId ? $this->passwords->getCredentials( $person->wpUserId ) : null;
		if ( null === $person || null === $creds ) {
			return null;
		}

		$subjectKey = '';
		foreach ( $this->activeRecords( $person->id ) as $record ) {
			$subject = $this->subjectOf( $record );
			if ( $this->config->shouldProvision( $subject ) ) {
				$subjectKey = (string) $subject;
				break;
			}
		}
		if ( '' === $subjectKey ) {
			return null; // Зачисление успели отменить — возвращать учётку некуда.
		}

		return array(
			'id'              => $row->id,
			'event'           => $row->event,
			'idempotency_key' => $row->idempotencyKey,
			'username'        => $creds['login'],
			'password'        => $creds['password'],
			'first'           => $person->firstName,
			'last'            => $person->lastName,
			'subject_key'     => $subjectKey,
		);
	}

	/** @return array<string, mixed>|null */
	private function passwordPayload( AdOutboxItemDTO $row ): ?array {
		$person = null !== $row->personId ? $this->persons->findIncludingDeleted( $row->personId ) : null;
		$creds  = null !== $person && null !== $person->wpUserId ? $this->passwords->getCredentials( $person->wpUserId ) : null;
		if ( null === $creds ) {
			return null;
		}

		return array(
			'id'              => $row->id,
			'event'           => $row->event,
			'idempotency_key' => $row->idempotencyKey,
			'username'        => $creds['login'],
			'password'        => $creds['password'],
		);
	}

	/** Учётка сейчас в «Отчисленных»: последним заданием по логину был deprovision. */
	private function isDeprovisioned( string $username ): bool {
		return AdSyncEvent::Deprovision->value === $this->outbox->latestByTarget( $username )?->event;
	}

	/**
	 * Действующие зачисления ученика без пробных доступов (временный доступ — не обучение).
	 *
	 * @return StudentRecordDTO[]
	 */
	private function activeRecords( int $personId ): array {
		return array_values( array_filter(
			$this->records->findActiveByStudent( $personId ),
			static fn( StudentRecordDTO $r ): bool => ! $r->isTrial
		) );
	}

	/** Ключ направления зачисления (по группе); null — группы нет. */
	private function subjectOf( StudentRecordDTO $record ): ?string {
		$group = $this->groups->findById( $record->groupId );

		return null !== $group ? (string) $group->subject_key : null;
	}

	private function usernameFromApplication( int $applicationId ): string {
		$app = $this->applications->find( $applicationId );
		if ( null === $app || empty( $app->studentDataEnc ) ) {
			return '';
		}
		$blob = json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true ) ?? array();
		return (string) ( $blob['username'] ?? '' );
	}

	/** Логин ученика; лицо ищется и среди мягко удалённых — хук отчисления приходит после удаления. */
	private function usernameFromPerson( int $personId ): string {
		$person = $this->persons->findIncludingDeleted( $personId );
		if ( null === $person || empty( $person->wpUserId ) ) {
			return '';
		}
		return (string) ( $this->users->find( $person->wpUserId )?->user_login ?? '' );
	}
}
