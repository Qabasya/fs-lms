<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Services;

use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Managers\Person\UserManager;
use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\DTO\AdOutboxItemDTO;
use Inc\Modules\AdSync\DTO\AdPreparedJob;
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
 *  - **заявка подана** из доверенной сети (направление из provision_subjects) → `provision`
 *    в OU направления; подана с другого адреса — учётку создаёт сотрудник кнопкой «Создать
 *    учётку» в окне заявки ({@see accountStateForApplication()});
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
		if ( $this->config->isExcluded( $username ) ) {
			return;
		}
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
		if ( '' === $username || $this->config->isExcluded( $username ) ) {
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
		if ( '' === $username || $this->config->isExcluded( $username ) ) {
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
		if ( '' === $username || $this->config->isExcluded( $username ) || ! $this->isDeprovisioned( $username ) ) {
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
		if ( null === $person || '' === $username || $this->config->isExcluded( $username ) || $this->isDeprovisioned( $username ) ) {
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

	/**
	 * Снимок учёток для ручного полного обновления: активные ученики и живые заявки.
	 * Направления без AD, пробные доступы и исключения не создают доменных учёток.
	 * Пароли в план не попадают: перечитываются только при отправке задания.
	 *
	 * @return array{targets: array<string, array<string, mixed>>, issues: array<int, array<string, mixed>>}
	 */
	public function fullSyncTargets(): array {
		$targets = array();
		$issues = array();
		foreach ( $this->records->allActiveStudentPersonIds() as $personId ) {
			$eligible = false;
			foreach ( $this->activeRecords( $personId ) as $record ) {
				$eligible = $eligible || $this->config->shouldProvision( $this->subjectOf( $record ) );
			}
			if ( ! $eligible ) {
				continue;
			}
			$username = $this->usernameFromPerson( $personId );
			if ( '' === $username ) {
				$issues[] = array( 'username' => 'Ученик #' . $personId, 'status' => 'error', 'message' => 'Не задан логин для синхронизации.' );
				continue;
			}
			if ( ! $this->config->isExcluded( $username ) ) {
				$targets[ mb_strtolower( $username ) ] = array( 'event' => AdSyncEvent::Provision->value, 'person_id' => $personId, 'target' => $username );
			}
		}
		foreach ( $this->applications->findByStatuses( array( ApplicationStatus::PendingParent->value, ApplicationStatus::ReadyForReview->value, ApplicationStatus::Enrolling->value ) ) as $app ) {
			if ( ! $this->config->shouldProvision( $app->subjectKey ?? null ) ) {
				continue;
			}
			$username = $this->usernameFromApplication( $app->id );
			if ( '' === $username ) {
				$issues[] = array( 'username' => 'Заявка #' . $app->id, 'status' => 'error', 'message' => 'Не задан логин для синхронизации.' );
				continue;
			}
			$key = mb_strtolower( $username );
			if ( ! isset( $targets[ $key ] ) && ! $this->config->isExcluded( $username ) ) {
				$targets[ $key ] = array( 'event' => AdSyncEvent::Provision->value, 'application_id' => $app->id, 'target' => $username );
			}
		}
		return array( 'targets' => $targets, 'issues' => $issues );
	}

	/** Обновление ФИО существующей доменной учётки после правки заявки. */
	public function enqueueNameUpdate( int $applicationId ): void {
		$previous = $this->outbox->latestByApplication( $applicationId );
		if ( null === $previous || AdSyncEvent::Delete->value === $previous->event ) {
			return;
		}
		$app = $this->applications->find( $applicationId );
		if ( null === $app || ApplicationStatus::Trash === $app->status ) {
			return;
		}
		$username = $this->usernameFromApplication( $applicationId );
		if ( '' === $username || $this->config->isExcluded( $username ) ) {
			return;
		}
		$this->outbox->enqueue( array(
			'event'           => AdSyncEvent::Rename->value,
			'application_id'  => $applicationId,
			'target'          => $username,
			'idempotency_key' => 'rename:app:' . $applicationId . ':' . bin2hex( random_bytes( 16 ) ),
		) );
	}

	/** Заявка уже удалена; логин берём из последнего задания по ней. */
	public function enqueueDeleteByApplication( int $applicationId, ?int $personId = null ): void {
		if ( null !== $personId && array() !== $this->activeRecords( $personId ) ) {
			return; // Заявка удалена, но ученик продолжает обучение.
		}
		$previous = $this->outbox->latestByApplication( $applicationId );
		$username = (string) ( $previous?->target ?? '' );
		if ( '' === $username || $this->config->isExcluded( $username ) || AdSyncEvent::Delete->value === $previous?->event ) {
			return;
		}
		$latest = $this->outbox->latestByTarget( $username );
		if ( AdSyncEvent::Provision->value === $latest?->event && $latest->applicationId !== $applicationId ) {
			return; // Другой заявкой или повторным зачислением учётка уже активирована.
		}
		$this->outbox->enqueue( array(
			'event'           => AdSyncEvent::Delete->value,
			'application_id'  => $applicationId,
			'target'          => $username,
			'idempotency_key' => 'delete:app:' . $applicationId,
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
	 * Состояние доменной учётки по заявке — для кнопки «Создать учётку» в окне заявки.
	 *
	 *  - `none`      — учётка не положена (направление без доменных учёток, заявка закрыта)
	 *                  или сайт её уже отключил/удалил: кнопки нет;
	 *  - `creatable` — учётки ещё нет, её можно создать вручную;
	 *  - `pending` / `done` / `failed` — задание на создание в очереди, выполнено, отклонено.
	 */
	public function accountStateForApplication( int $applicationId ): string {
		$app = $this->applications->find( $applicationId );
		if ( null === $app
			|| ! in_array( $app->status, array( ApplicationStatus::PendingParent, ApplicationStatus::ReadyForReview ), true )
			|| ! $this->config->shouldProvision( $app->subjectKey ?? null ) ) {
			return 'none';
		}

		if ( $this->config->isExcluded( $this->usernameFromApplication( $applicationId ) ) ) {
			return 'none';
		}
		$latest = $this->outbox->latestByApplication( $applicationId );
		if ( null === $latest ) {
			return 'creatable';
		}

		return AdSyncEvent::Provision->value === $latest->event || AdSyncEvent::Rename->value === $latest->event
			? $this->statusForApplication( $applicationId )
			: 'none';
	}

	/** Подготовка с объяснением: готово, устарело либо ошибка данных. */
	public function prepareJob( AdOutboxItemDTO $row ): AdPreparedJob {
		$obsolete = $this->obsoleteReason( $row );
		if ( null !== $obsolete ) {
			return AdPreparedJob::obsolete( $obsolete );
		}
		try {
			$payload = $this->payloadFor( $row );
		} catch ( \JsonException ) {
			return AdPreparedJob::invalid( 'Данные учётной записи в заявке №' . $row->applicationId . ' повреждены: неверный формат.' );
		} catch ( \RuntimeException ) {
			return AdPreparedJob::invalid( 'Не удалось расшифровать данные учётной записи. Проверьте ключ шифрования и сохранённые данные в LMS.' );
		}
		if ( null === $payload ) {
			return AdPreparedJob::invalid( $this->missingDataReason( $row ) );
		}
		if ( '' === trim( (string) ( $payload['username'] ?? '' ) ) ) {
			return AdPreparedJob::invalid( null !== $row->applicationId ? 'В заявке №' . $row->applicationId . ' не указан логин.' : 'Не задан логин доменной учётной записи.' );
		}
		if ( in_array( $row->event, array( AdSyncEvent::Provision->value, AdSyncEvent::Password->value ), true ) && '' === (string) ( $payload['password'] ?? '' ) ) {
			return AdPreparedJob::invalid( null !== $row->applicationId ? 'В заявке №' . $row->applicationId . ' нет сохранённого пароля.' : 'Недоступен сохранённый пароль ученика. Задайте пароль заново в LMS.' );
		}
		return AdPreparedJob::ready( $payload );
	}

	/** Отменяет старые pending/failed/dead-задания без чтения паролей и запросов в AD. */
	public function cancelObsoleteJobs(): int {
		$cancelled = 0;
		foreach ( $this->outbox->listUnfinishedSourceJobs() as $row ) {
			$reason = $this->obsoleteReason( $row );
			if ( null !== $reason ) {
				$this->outbox->markCancelled( $row->id, $reason );
				++$cancelled;
			}
		}
		return $cancelled;
	}

	/** Удаление и отключение по сохранённому target не требуют существования заявки. */
	private function obsoleteReason( AdOutboxItemDTO $row ): ?string {
		if ( in_array( $row->event, array( AdSyncEvent::Delete->value, AdSyncEvent::Deprovision->value ), true ) ) {
			return null;
		}
		if ( null !== $row->applicationId ) {
			$app = $this->applications->find( $row->applicationId );
			if ( null === $app ) {
				return 'Заявка №' . $row->applicationId . ' удалена; задание отменено как устаревшее.';
			}
			if ( in_array( $app->status, array( ApplicationStatus::Trash, ApplicationStatus::Expired ), true ) ) {
				return 'Заявка №' . $row->applicationId . ' закрыта (' . $app->status->label() . '); создание или обновление учётки больше не требуется.';
			}
		} elseif ( null !== $row->personId ) {
			if ( null === $this->persons->findIncludingDeleted( $row->personId ) ) {
				return 'Ученик №' . $row->personId . ' удалён; задание отменено как устаревшее.';
			}
			if ( AdSyncEvent::Provision->value === $row->event ) {
				foreach ( $this->activeRecords( $row->personId ) as $record ) {
					if ( $this->config->shouldProvision( $this->subjectOf( $record ) ) ) {
						return null;
					}
				}
				return 'У ученика нет действующих зачислений с доменной учёткой; реактивация отменена.';
			}
		}
		return null;
	}

	/** Конкретная причина отсутствия тела запроса; содержимое пароля не используется. */
	private function missingDataReason( AdOutboxItemDTO $row ): string {
		if ( in_array( $row->event, array( AdSyncEvent::Delete->value, AdSyncEvent::Deprovision->value ), true ) ) {
			return 'В задании отключения или удаления не указан доменный логин.';
		}
		if ( null !== $row->applicationId ) {
			$app = $this->applications->find( $row->applicationId );
			return empty( $app?->studentDataEnc )
				? 'В заявке №' . $row->applicationId . ' не сохранены данные учётной записи.'
				: 'В задании по заявке №' . $row->applicationId . ' не указан доменный логин.';
		}
		if ( null === $row->personId ) {
			return 'В задании не указан ученик.';
		}
		$person = $this->persons->findIncludingDeleted( $row->personId );
		if ( null === $person?->wpUserId ) {
			return 'У ученика не привязана учётная запись WordPress.';
		}
		if ( null === $this->users->find( $person->wpUserId ) ) {
			return 'Привязанная учётная запись WordPress не найдена.';
		}
		return 'Недоступен сохранённый пароль ученика. Задайте пароль заново в LMS.';
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
			AdSyncEvent::Rename->value    => $this->renamePayload( $row ),
			default                       => $this->deprovisionPayload( $row ),
		};
	}

	/** @return array<string, mixed>|null */
	private function renamePayload( AdOutboxItemDTO $row ): ?array {
		$app = $this->applications->find( (int) $row->applicationId );
		if ( null === $app || empty( $app->studentDataEnc ) || empty( $row->target ) ) {
			return null;
		}
		$blob = $this->applicationBlob( $app->studentDataEnc );
		return array(
			'id'              => $row->id,
			'event'           => $row->event,
			'idempotency_key' => $row->idempotencyKey,
			'username'        => $row->target,
			'first'           => (string) ( $blob['first_name'] ?? '' ),
			'last'            => (string) ( $blob['last_name'] ?? '' ),
			'middle'          => (string) ( $blob['middle_name'] ?? '' ),
		);
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

		$blob = $this->applicationBlob( $app->studentDataEnc );

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

	/**
	 * Читает только структуру данных заявки, не включая её содержимое в исключения.
	 *
	 * @throws \JsonException Структура данных повреждена.
	 */
	private function applicationBlob( string $encrypted ): array {
		$blob = json_decode( $this->crypto->decrypt( $encrypted ), true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $blob ) ) {
			throw new \JsonException( 'Invalid application data structure' );
		}
		return $blob;
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
