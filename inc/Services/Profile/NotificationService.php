<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Profile\NotificationDTO;
use Inc\Enums\Profile\NotificationType;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\NotificationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\EffectiveTeacherResolver;

/**
 * Class NotificationService
 *
 * Домен in-app уведомлений кабинета: адресация получателей + вставка +
 * рендер текста плитки для клиента. Единственная точка русских строк
 * (`toClientArray()`) — клиент выбирает по `type` только иконку/цвет.
 *
 * ### Адресные хелперы
 *
 * Обратного метода «ребёнок → родитель» в кодовой базе нет — {@see self::guardianUserIds()}
 * инкапсулирует двухшаговый паттерн ({@see \Inc\Callbacks\Person\PersonViewCallbacks}):
 * активные записи ученика → `parentPersonId` → `PersonRepository::find()` → `wpUserId`.
 *
 * `lessonTeacherUserId()` — тонкая обёртка над {@see EffectiveTeacherResolver::forLesson()}
 * (разовый `teacher_user_id` занятия › активная замена › `groups.teacher_id`).
 *
 * @package Inc\Services\Profile
 */
readonly class NotificationService {

	public function __construct(
		private NotificationRepository    $notifications,
		private StudentRecordRepository   $studentRecords,
		private PersonRepository          $personRepository,
		private GroupsRepository          $groups,
		private LessonManager             $lessons,
		private EffectiveTeacherResolver  $effectiveTeacher,
	) {}

	/**
	 * Вставляет уведомление каждому получателю (идемпотентно по dedupe-ключу).
	 * Для реально вставленных строк дёргает `do_action('fs_lms_notification_created')`
	 * — шов будущего Telegram-модуля Notifier (core на модуль не ссылается).
	 *
	 * @param int[]                $userIds WP user id получателей (0/дубли отфильтровываются)
	 * @param array<string,mixed>  $payload Снапшот данных для текста плитки
	 */
	public function push(
		array            $userIds,
		NotificationType $type,
		string           $dedupeKey,
		array            $payload = array(),
		string           $url = '',
		?int             $groupId = null,
		?string          $entityType = null,
		?int             $entityId = null
	): void {
		foreach ( $this->uniqueValidIds( $userIds ) as $userId ) {
			$inserted = $this->notifications->insertIgnore(
				$userId,
				$type->value,
				$dedupeKey,
				$payload,
				$url,
				$groupId,
				$entityType,
				$entityId
			);

			if ( $inserted ) {
				do_action( 'fs_lms_notification_created', $userId, $type->value, $payload );
			}
		}
	}

	/**
	 * Заменяет старую плитку по тому же dedupe-ключу свежей непрочитанной
	 * (кейс переоценки работы: новый балл должен быть замечен заново).
	 *
	 * @param int[] $userIds
	 * @param array<string,mixed> $payload
	 */
	public function pushFresh(
		array            $userIds,
		NotificationType $type,
		string           $dedupeKey,
		array            $payload = array(),
		string           $url = '',
		?int             $groupId = null,
		?string          $entityType = null,
		?int             $entityId = null
	): void {
		$this->retract( $userIds, $dedupeKey );
		$this->push( $userIds, $type, $dedupeKey, $payload, $url, $groupId, $entityType, $entityId );
	}

	/**
	 * Отзывает уведомление по dedupe-ключу (например, исправление ошибочной
	 * отметки «отсутствовал» на «присутствовал»).
	 *
	 * @param int[] $userIds
	 */
	public function retract( array $userIds, string $dedupeKey ): void {
		$this->notifications->deleteByDedupe( $this->uniqueValidIds( $userIds ), $dedupeKey );
	}

	/** WP user id ученика по person id (null — не привязан к учётке). */
	public function studentUserId( int $personId ): ?int {
		return $this->personRepository->find( $personId )?->wpUserId;
	}

	/**
	 * WP user id родителей ученика (обычно один; несколько — если ученик
	 * фигурирует в нескольких активных записях с разными parent_person_id).
	 *
	 * @return int[]
	 */
	public function guardianUserIds( int $studentPersonId ): array {
		$parentPersonIds = array_unique( array_map(
			static fn( $record ) => $record->parentPersonId,
			$this->studentRecords->findActiveByStudent( $studentPersonId )
		) );

		return $this->wpUserIdsForPersons( $parentPersonIds );
	}

	/**
	 * WP user id всех активных учеников группы.
	 *
	 * @return int[]
	 */
	public function groupStudentUserIds( int $groupId ): array {
		$studentPersonIds = array_map(
			static fn( $record ) => $record->studentPersonId,
			$this->studentRecords->findActiveByGroupId( $groupId )
		);

		return $this->wpUserIdsForPersons( $studentPersonIds );
	}

	/**
	 * WP user id учеников занятия: individual — единственный ученик занятия,
	 * group — все активные ученики группы.
	 *
	 * @return int[]
	 */
	public function lessonStudentUserIds( GroupLessonDTO $lesson ): array {
		if ( 'individual' === $lesson->kind ) {
			if ( null === $lesson->studentPersonId ) {
				return array();
			}
			$userId = $this->studentUserId( $lesson->studentPersonId );
			return null !== $userId ? array( $userId ) : array();
		}

		return $this->groupStudentUserIds( $lesson->groupId );
	}

	/**
	 * Person id учеников занятия (до конвертации в WP user id) — нужно cron-продюсеру
	 * дедлайнов для проверки «уже сдал» ({@see \Inc\Repositories\WPDBRepositories\SubmissionRepository::listByStudentAndGroupLesson()}
	 * работает по person id, не по WP user id).
	 *
	 * @return int[]
	 */
	public function lessonStudentPersonIds( GroupLessonDTO $lesson ): array {
		if ( 'individual' === $lesson->kind ) {
			return null !== $lesson->studentPersonId ? array( $lesson->studentPersonId ) : array();
		}

		return array_map(
			static fn( $record ) => $record->studentPersonId,
			$this->studentRecords->findActiveByGroupId( $lesson->groupId )
		);
	}

	/** Фактический преподаватель занятия (разовый override › замена › препод группы). */
	public function lessonTeacherUserId( GroupLessonDTO $lesson ): ?int {
		return $this->effectiveTeacher->forLesson( $lesson );
	}

	/** Тема занятия для текста плитки: заголовок урока, иначе `label` строки (паттерн {@see \Inc\Services\Profile\LearnerService}). */
	public function lessonTopic( GroupLessonDTO $lesson ): string {
		$content = $lesson->lessonId ? $this->lessons->get( $lesson->lessonId ) : null;
		return $content?->topic ?? ( $lesson->label ?? '' );
	}

	/** Название группы для текста плитки. */
	public function groupName( int $groupId ): string {
		return (string) ( $this->groups->findById( $groupId )?->name ?? '' );
	}

	/**
	 * Снапшот-имя ученика (PII-safe) в занятиях указанной группы — как в Эпике 3.
	 * Пусто, если у ученика нет активной записи в этой группе.
	 */
	public function studentSnapshotName( int $studentPersonId, int $groupId ): string {
		foreach ( $this->studentRecords->findActiveByStudent( $studentPersonId ) as $record ) {
			if ( $record->groupId === $groupId ) {
				return trim( "{$record->snapshotLastName} {$record->snapshotFirstName}" );
			}
		}

		return '';
	}

	/**
	 * Deep-link в плеер урока занятия, при возможности — сразу к шагу нужной работы
	 * (`?step=`), как в {@see \Inc\Services\Profile\LearnerService::deadlines()}.
	 */
	public function lessonWorkUrl( GroupLessonDTO $lesson, int $workId ): string {
		$baseUrl = PageRoutes::GroupCockpit->lessonUrl( $lesson->groupId, $lesson->id );
		$content = $lesson->lessonId ? $this->lessons->get( $lesson->lessonId ) : null;
		$stepKey = $content?->stepKeyForWork( $workId );

		return $stepKey ? (string) add_query_arg( array( 'step' => $stepKey ), $baseUrl ) : $baseUrl;
	}

	/**
	 * Представление уведомления для AJAX-ответа. Русские title/body — из
	 * `type` + `payload` (единственная точка текстов); клиент выбирает
	 * только иконку/цвет по `type`/`tone`.
	 *
	 * @return array{id:int, type:string, tone:string, title:string, body:string, url:string, time:string, unread:bool}
	 */
	public function toClientArray( NotificationDTO $n ): array {
		return array(
			'id'     => $n->id,
			'type'   => $n->type->value,
			'tone'   => $n->type->tone(),
			'title'  => $n->type->title(),
			'body'   => $this->renderBody( $n->type, $n->payload ),
			'url'    => $n->url,
			'time'   => $n->createdAt,
			'unread' => $n->isUnread(),
		);
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private function uniqueValidIds( array $ids ): array {
		return array_values( array_unique( array_filter(
			array_map( 'intval', $ids ),
			static fn( int $id ): bool => $id > 0
		) ) );
	}

	/**
	 * @param int[] $personIds
	 * @return int[]
	 */
	private function wpUserIdsForPersons( array $personIds ): array {
		$personIds = array_values( array_unique( array_filter( array_map( 'intval', $personIds ) ) ) );
		if ( empty( $personIds ) ) {
			return array();
		}

		$userIds = array();
		foreach ( $this->personRepository->findByIds( $personIds ) as $person ) {
			if ( null !== $person->wpUserId ) {
				$userIds[ $person->wpUserId ] = true;
			}
		}

		return array_map( 'intval', array_keys( $userIds ) );
	}

	/** @param array<string,mixed> $p */
	private function renderBody( NotificationType $type, array $p ): string {
		$topic = (string) ( $p['topic'] ?? '' );
		$group = (string) ( $p['group_name'] ?? '' );
		$tail  = '' !== $group ? " · {$group}" : '';

		return match ( $type ) {
			NotificationType::VideoUploaded,
			NotificationType::DeadlineSoon,
			NotificationType::DeadlineMissed,
			NotificationType::LessonSoon,
			NotificationType::WorkReturned => '' !== $topic ? "«{$topic}»{$tail}" : ltrim( $tail, ' ·' ),

			NotificationType::WorkGraded,
			NotificationType::AttemptGraded => '' !== $topic
				? sprintf( '«%s»: %s%s', $topic, $this->renderScore( $p ), $tail )
				: sprintf( '%s%s', $this->renderScore( $p ), $tail ),

			NotificationType::ReviewNeeded,
			NotificationType::AttendanceMissed => sprintf(
				'%s%s',
				(string) ( $p['student_name'] ?? '' ),
				'' !== $topic ? " — «{$topic}»{$tail}" : $tail
			),

			NotificationType::SubstituteAssigned => trim( sprintf(
				'%s%s',
				$group,
				'' !== (string) ( $p['valid_from'] ?? '' ) ? sprintf( ', %s – %s', (string) $p['valid_from'], (string) ( $p['valid_to'] ?? '' ) ) : ''
			) ),
		};
	}

	/** @param array<string,mixed> $p */
	private function renderScore( array $p ): string {
		if ( ! isset( $p['score'] ) ) {
			return '';
		}

		return isset( $p['max_score'] )
			? sprintf( '%s из %s баллов', $p['score'], $p['max_score'] )
			: sprintf( '%s баллов', $p['score'] );
	}
}
