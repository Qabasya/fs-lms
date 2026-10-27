<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\Enums\Course\LessonKind;
use Inc\Enums\Course\LessonStatus;
use Inc\Enums\Profile\NotificationType;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\GroupLessonUsageGuard;
use Inc\Services\Course\RoomAvailabilityService;
use Inc\Services\Profile\NotificationService;
use Inc\Shared\Traits\TransactionRunner;

/**
 * Class IndividualLessonService
 *
 * Индивидуальные занятия группы (D3): занятие на одного ученика, привязанное
 * к дате, вне программы группы и вне раскладки reflow.
 *
 * @package Inc\Services\Group
 *
 * Программа группы — {@see ProgramCompositionService}; общий календарь —
 * {@see GroupCalendarService}.
 */
readonly class IndividualLessonService {

	use TransactionRunner;

	/**
	 * @param GroupLessonRepository   $groupLessons  Строки программы/занятий
	 * @param GroupsRepository        $groups        Группы
	 * @param StudentRecordRepository $records       Записи учеников (членство в группе)
	 * @param RoomRepository          $rooms         Кабинеты
	 * @param LessonManager           $lessonManager Банк уроков
	 * @param CourseManager           $courses       Курсы (уроки назначенного курса)
	 * @param ScheduleEventPublisher  $events        Публикация событий обучения
	 * @param RoomAvailabilityService $roomAvailability Занятость кабинетов (занятия и сеансы экзаменов)
	 * @param GroupLessonUsageGuard   $usage         Есть ли у строки данные ученика (можно ли убрать его из доп. занятия)
	 * @param NotificationService     $notifications Оповещения ученика о назначении, переносе и отмене доп. занятия
	 */
	public function __construct(
		private GroupLessonRepository   $groupLessons,
		private GroupsRepository        $groups,
		private StudentRecordRepository $records,
		private RoomRepository          $rooms,
		private LessonManager           $lessonManager,
		private CourseManager           $courses,
		private ScheduleEventPublisher  $events,
		private RoomAvailabilityService $roomAvailability,
		private GroupLessonUsageGuard   $usage,
		private NotificationService     $notifications,
	) {}

	/**
	 * Создаёт индивидуальное занятие на одного ученика (D3): `kind='individual'`,
	 * привязано к дате (`is_pinned`), НЕ входит в программу группы и НЕ участвует
	 * в раскладке `reflow`.
	 *
	 * @param int         $groupId         ID группы
	 * @param int         $studentPersonId Ученик (обязан состоять в группе)
	 * @param string      $scheduledAt     'Y-m-d H:i:s'
	 * @param string|null $endsAt          'Y-m-d H:i:s' (опц.)
	 * @param int|null    $lessonId        Привязка к банку урока (опц.)
	 * @param string|null $label           Ярлык строки (опц.)
	 * @param int|null    $teacherUserId   Явный преподаватель (опц., иначе — препод группы)
	 * @param int         $actorUserId     Автор изменения
	 * @param int|null    $roomId          Кабинет (опц.)
	 *
	 * @return int ID созданного занятия
	 *
	 * @throws \InvalidArgumentException Если группа не найдена или ученик не в группе
	 */
	public function createIndividualLesson(
		int     $groupId,
		int     $studentPersonId,
		string  $scheduledAt,
		?string $endsAt,
		?int    $lessonId,
		?string $label,
		?int    $teacherUserId,
		int     $actorUserId,
		?int    $roomId = null,
		?string $extraKey = null,
		?string $broadcastUrl = null
	): int {
		if ( ! $this->groups->findById( $groupId ) ) {
			throw new \InvalidArgumentException( 'Группа не найдена.' );
		}

		$this->assertMember( $groupId, $studentPersonId );

		if ( null !== $roomId && $roomId > 0 ) {
			return $this->inTransaction( function () use ( $groupId, $studentPersonId, $scheduledAt, $endsAt, $lessonId, $label, $teacherUserId, $actorUserId, $roomId, $extraKey, $broadcastUrl ): int {
				// Кабинет занимается под блокировкой: сеанс экзамена, назначаемый параллельно, видит это занятие, а занятие — сеанс.
				$this->rooms->lockForUpdate( $roomId );
				$this->assertNoExamInRoom( $roomId, $scheduledAt, $endsAt );

				return $this->insertIndividual( $groupId, $studentPersonId, $scheduledAt, $endsAt, $lessonId, $label, $teacherUserId, $actorUserId, $roomId, $extraKey, $broadcastUrl );
			} );
		}

		return $this->insertIndividual( $groupId, $studentPersonId, $scheduledAt, $endsAt, $lessonId, $label, $teacherUserId, $actorUserId, $roomId, $extraKey, $broadcastUrl );
	}

	/**
	 * Дополнительное занятие вне расписания для части группы: по строке-занятию на каждого
	 * выбранного ученика (дата, время, кабинет, тема и ссылка на трансляцию общие). Каждое — обычное занятие
	 * `kind='individual'`, поэтому журнал, посещаемость, выдача и плеер работают без изменений.
	 * Состав проверяется целиком до первой вставки.
	 *
	 * @param int[] $studentPersonIds Ученики группы
	 *
	 * @return int[] ID созданных занятий
	 *
	 * @throws \InvalidArgumentException Если группа не найдена, учеников нет или кто-то не в группе
	 */
	public function createExtraLessons(
		int     $groupId,
		array   $studentPersonIds,
		string  $scheduledAt,
		?string $endsAt,
		?int    $lessonId,
		?string $label,
		?int    $teacherUserId,
		int     $actorUserId,
		?int    $roomId = null,
		?string $broadcastUrl = null
	): array {
		$studentPersonIds = array_values( array_unique( array_filter( array_map( 'intval', $studentPersonIds ) ) ) );
		if ( ! $studentPersonIds ) {
			throw new \InvalidArgumentException( 'Выберите хотя бы одного ученика.' );
		}
		if ( ! $this->groups->findById( $groupId ) ) {
			throw new \InvalidArgumentException( 'Группа не найдена.' );
		}
		foreach ( $studentPersonIds as $personId ) {
			$this->assertMember( $groupId, $personId );
		}

		// Общий ключ: КТП группы показывает строки одного занятия одной карточкой и правит их разом.
		$extraKey = str_replace( '-', '', wp_generate_uuid4() );

		$ids = array();
		foreach ( $studentPersonIds as $personId ) {
			$ids[] = $this->createIndividualLesson( $groupId, $personId, $scheduledAt, $endsAt, $lessonId, $label, $teacherUserId, $actorUserId, $roomId, $extraKey, $broadcastUrl );
		}

		return $ids;
	}

	/**
	 * Дополнительные занятия группы для календаря КТП: строки с общим ключом сведены в одну карточку
	 * со списком учеников. Занятия без ключа (созданные по одному) сюда не попадают — они в режиме
	 * «Индивидуальные занятия».
	 *
	 * @return array<int, array<string, mixed>> По дате; `group_lesson_id` — первая строка занятия (для плеера)
	 */
	public function getExtraLessons( int $groupId ): array {
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			return array();
		}

		$names = $this->studentNames( $groupId );
		$room  = $this->roomNames();

		$items = array();
		foreach ( $this->groupLessons->listByGroup( $groupId ) as $row ) {
			if ( ! $row->kind->isIndividual() || null === $row->extraKey || null === $row->studentPersonId ) {
				continue;
			}

			$key = $row->extraKey;
			if ( ! isset( $items[ $key ] ) ) {
				$lesson      = $row->lessonId ? $this->lessonManager->get( $row->lessonId ) : null;
				$effRoomId   = ! empty( $row->roomId ) ? (int) $row->roomId : ( ! empty( $group->room_id ) ? (int) $group->room_id : 0 );
				$items[ $key ] = array(
					'extra_key'       => $key,
					'group_lesson_id' => $row->id,
					'group_id'        => $groupId,
					'scheduled_at'    => $row->scheduledAt,
					'ends_at'         => $row->endsAt,
					'room_id'         => ! empty( $row->roomId ) ? (int) $row->roomId : 0,
					'room'            => ( $effRoomId && isset( $room[ $effRoomId ] ) ) ? $room[ $effRoomId ] : '',
					'lesson_id'       => $row->lessonId,
					'broadcast_url'   => (string) ( $row->broadcastUrl ?? '' ),
					'topic'           => $lesson?->topic ?? ( $row->label ?? '' ),
					// Вход в плеер (teacher-режим) — как у темы КТП; занятие без урока не кликабельно.
					'player_url'      => $row->lessonId ? PageRoutes::LessonPlayer->lessonUrl( $groupId, $row->id ) : '',
					'students'        => array(),
				);
			}

			$items[ $key ]['students'][] = array(
				'person_id' => $row->studentPersonId,
				'name'      => $names[ $row->studentPersonId ] ?? '—',
			);
		}

		$items = array_values( $items );
		usort( $items, static fn( $a, $b ) => strcmp( (string) $a['scheduled_at'], (string) $b['scheduled_at'] ) );

		return $items;
	}

	/**
	 * Правка дополнительного занятия целиком: состав учеников, дата/время, кабинет, тема.
	 * Общие поля пишутся во все строки; новым ученикам добавляются строки, убранным — удаляются.
	 * Ученика с данными по занятию (прогресс, сдачи, попытки, посещаемость) убрать нельзя, тему при
	 * учебных данных — сменить нельзя.
	 *
	 * @param int[]    $studentPersonIds Новый состав (минимум один)
	 * @param int|null $lessonId         Новая тема; null — не менять
	 * @param int|null $roomId           Кабинет; 0 — снять, null — не менять
	 * @param string|null $broadcastUrl  Своя ссылка на трансляцию; null — вернуться к ссылке группы
	 *
	 * @throws \InvalidArgumentException Нет занятия, пустой состав, ученик не в группе, у убираемого ученика есть данные
	 */
	public function updateExtraLessons(
		int     $groupId,
		string  $extraKey,
		array   $studentPersonIds,
		string  $scheduledAt,
		?string $endsAt,
		?int    $roomId,
		?int    $lessonId,
		int     $actorUserId,
		?string $broadcastUrl = null
	): void {
		$rows = array_values( array_filter(
			$this->groupLessons->listByGroup( $groupId ),
			static fn( GroupLessonDTO $r ): bool => $r->kind->isIndividual() && $r->extraKey === $extraKey
		) );
		if ( ! $rows ) {
			throw new \InvalidArgumentException( 'Дополнительное занятие не найдено.' );
		}

		$studentPersonIds = array_values( array_unique( array_filter( array_map( 'intval', $studentPersonIds ) ) ) );
		if ( ! $studentPersonIds ) {
			throw new \InvalidArgumentException( 'Выберите хотя бы одного ученика.' );
		}
		foreach ( $studentPersonIds as $personId ) {
			$this->assertMember( $groupId, $personId );
		}
		if ( null !== $lessonId && $lessonId > 0 ) {
			$this->assertLessonExists( $lessonId );
		}

		$names      = $this->studentNames( $groupId );
		$changeLesson = null !== $lessonId && $lessonId > 0 && $lessonId !== $rows[0]->lessonId;
		$existing   = array();
		$toRemove   = array();
		foreach ( $rows as $row ) {
			if ( in_array( $row->studentPersonId, $studentPersonIds, true ) ) {
				$existing[ (int) $row->studentPersonId ] = $row;
				if ( $changeLesson && $this->usage->hasLearningData( $row->id ) ) {
					throw new \InvalidArgumentException( sprintf( 'Тему нельзя сменить: у ученика «%s» уже есть решения по занятию.', $names[ $row->studentPersonId ] ?? '—' ) );
				}
			} else {
				if ( $this->usage->hasEngagement( $row->id ) ) {
					throw new \InvalidArgumentException( sprintf( 'Ученика «%s» убрать нельзя: по занятию уже есть его данные.', $names[ $row->studentPersonId ] ?? '—' ) );
				}
				$toRemove[] = $row;
			}
		}

		foreach ( $existing as $row ) {
			$this->updateIndividualLesson( $row->id, $scheduledAt, $endsAt, $roomId, null, $lessonId, $actorUserId );
			$this->groupLessons->setBroadcastUrl( $row->id, $broadcastUrl );
			if ( $row->scheduledAt !== $scheduledAt ) {
				$this->notifyExtra( NotificationType::ExtraLessonScheduled, $row->id, $groupId, (int) $row->studentPersonId, $scheduledAt, $lessonId ?: $row->lessonId, $row->label );
			}
		}
		foreach ( $toRemove as $row ) {
			$this->notifyExtraCancelled( $row );
			$this->groupLessons->remove( $row->id );
		}

		$template = $rows[0];
		$newRoom  = null !== $roomId ? ( $roomId > 0 ? $roomId : null ) : $template->roomId;
		$newLesson = ( null !== $lessonId && $lessonId > 0 ) ? $lessonId : $template->lessonId;
		$recorded = null;
		foreach ( $rows as $row ) {
			if ( $row->hasRecording() ) {
				$recorded = $row;
				break;
			}
		}
		foreach ( $studentPersonIds as $personId ) {
			if ( ! isset( $existing[ $personId ] ) ) {
				$id = $this->insertIndividual( $groupId, $personId, $scheduledAt, $endsAt, $newLesson, $template->label, $template->teacherUserId, $actorUserId, $newRoom, $extraKey, $broadcastUrl );
				$this->copyRecording( $recorded, $id );
			}
		}

		$this->events->groupChanged( $groupId, $actorUserId );
	}

	/**
	 * Отмена дополнительного занятия целиком: строки всех учеников удаляются, ученики оповещаются.
	 * Если по занятию у кого-то уже есть данные (решения, посещаемость), отменить нельзя — иначе они пропадут.
	 *
	 * @throws \InvalidArgumentException Нет занятия или по нему есть данные учеников
	 */
	public function deleteExtraLessons( int $groupId, string $extraKey, int $actorUserId ): void {
		$rows = array_values( array_filter(
			$this->groupLessons->listByGroup( $groupId ),
			static fn( GroupLessonDTO $r ): bool => $r->kind->isIndividual() && $r->extraKey === $extraKey
		) );
		if ( ! $rows ) {
			throw new \InvalidArgumentException( 'Дополнительное занятие не найдено.' );
		}

		$names = $this->studentNames( $groupId );
		foreach ( $rows as $row ) {
			if ( $this->usage->hasEngagement( $row->id ) ) {
				throw new \InvalidArgumentException( sprintf( 'Занятие отменить нельзя: по нему уже есть данные ученика «%s».', $names[ $row->studentPersonId ] ?? '—' ) );
			}
		}

		foreach ( $rows as $row ) {
			$this->notifyExtraCancelled( $row );
			$this->groupLessons->remove( $row->id );
		}

		$this->events->groupChanged( $groupId, $actorUserId );
	}

	/** Ученику — назначено/перенесено доп. занятие (плитка по ключу строки заменяется свежей). */
	private function notifyExtra( NotificationType $type, int $rowId, int $groupId, int $personId, string $scheduledAt, ?int $lessonId, ?string $label ): void {
		$userId = $this->notifications->studentUserId( $personId );
		if ( null === $userId ) {
			return;
		}

		$this->notifications->pushFresh(
			array( $userId ),
			$type,
			"extra:{$rowId}",
			array(
				'when'       => $this->formatWhen( $scheduledAt ),
				'topic'      => $lessonId ? ( $this->lessonManager->get( $lessonId )?->topic ?? (string) $label ) : (string) $label,
				'group_name' => $this->notifications->groupName( $groupId ),
			),
			PageRoutes::LessonPlayer->lessonUrl( $groupId, $rowId ),
			$groupId,
			'group_lesson',
			$rowId
		);
	}

	/** Ученику — доп. занятие отменено; прежняя плитка о назначении снимается. */
	private function notifyExtraCancelled( GroupLessonDTO $row ): void {
		$userId = $this->notifications->studentUserId( (int) $row->studentPersonId );
		if ( null === $userId ) {
			return;
		}

		$this->notifications->retract( array( $userId ), "extra:{$row->id}" );
		$this->notifications->push(
			array( $userId ),
			NotificationType::ExtraLessonCancelled,
			"extra-cancel:{$row->id}",
			array(
				'when'       => $this->formatWhen( (string) $row->scheduledAt ),
				'topic'      => $row->lessonId ? ( $this->lessonManager->get( $row->lessonId )?->topic ?? (string) $row->label ) : (string) $row->label,
				'group_name' => $this->notifications->groupName( $row->groupId ),
			),
			'',
			$row->groupId,
			'group_lesson',
			$row->id
		);
	}

	/** «23.10 в 15:00» из `Y-m-d H:i:s`. */
	private function formatWhen( string $scheduledAt ): string {
		$ts = strtotime( $scheduledAt );

		return false === $ts ? '' : gmdate( 'd.m', $ts ) . ' в ' . gmdate( 'H:i', $ts );
	}

	/** @return array<int, string> person_id → «Фамилия Имя» активных учеников группы. */
	private function studentNames( int $groupId ): array {
		$names = array();
		foreach ( $this->records->findActiveByGroupId( $groupId ) as $rec ) {
			$names[ $rec->studentPersonId ] = trim( $rec->snapshotLastName . ' ' . $rec->snapshotFirstName );
		}

		return $names;
	}

	/** @return array<int, string> id → название кабинета. */
	private function roomNames(): array {
		$names = array();
		foreach ( $this->rooms->findAll() as $r ) {
			$names[ $r->id ] = $r->name;
		}

		return $names;
	}

	/** Запись занятия принадлежит всем его строкам: ученик, добавленный позже, получает её сразу. */
	private function copyRecording( ?GroupLessonDTO $source, int $id ): void {
		if ( null === $source ) {
			return;
		}
		if ( null !== $source->recordingUrl && '' !== $source->recordingUrl ) {
			$this->groupLessons->setRecordingUrl( $id, $source->recordingUrl );
		}
		if ( null !== $source->recordingLink ) {
			$this->groupLessons->setRecordingLink( $id, $source->recordingLink );
		}
		$this->groupLessons->setStatus( $id, LessonStatus::Held );
	}

	/** Вставка индивидуального занятия и событие обучения. */
	private function insertIndividual( int $groupId, int $studentPersonId, string $scheduledAt, ?string $endsAt, ?int $lessonId, ?string $label, ?int $teacherUserId, int $actorUserId, ?int $roomId, ?string $extraKey = null, ?string $broadcastUrl = null ): int {
		$id = $this->groupLessons->add( new GroupLessonInputDTO(
			groupId         : $groupId,
			lessonId        : $lessonId,
			position        : 0,
			scheduledAt     : $scheduledAt,
			endsAt          : $endsAt,
			isPinned        : true,
			teacherUserId   : $teacherUserId,
			createdByUserId : $actorUserId,
			label           : $label,
			kind            : LessonKind::Individual,
			status          : 'scheduled',
			studentPersonId : $studentPersonId,
			roomId          : $roomId,
			extraKey        : $extraKey,
			broadcastUrl    : $broadcastUrl,
		) );

		$this->events->lessonChanged( $groupId, $id, $actorUserId );

		if ( null !== $extraKey ) {
			$this->notifyExtra( NotificationType::ExtraLessonScheduled, $id, $groupId, $studentPersonId, $scheduledAt, $lessonId, $label );
		}

		return $id;
	}

	/**
	 * НБ-9: индивидуальные занятия группы для режима КТП «Индивидуальные занятия».
	 * Каждый слот: ФИО ученика, дата/время, эффективный кабинет, привязанный урок
	 * (тема) либо пусто. Порядок — по дате.
	 *
	 * @param int $groupId ID группы
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getIndividualProgram( int $groupId ): array {
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			return array();
		}

		$names = array();
		foreach ( $this->records->findActiveByGroupId( $groupId ) as $rec ) {
			$names[ $rec->studentPersonId ] = trim( $rec->snapshotLastName . ' ' . $rec->snapshotFirstName );
		}

		$roomNames = array();
		foreach ( $this->rooms->findAll() as $r ) {
			$roomNames[ $r->id ] = $r->name;
		}
		$groupRoomId = ( ! empty( $group->room_id ) ) ? (int) $group->room_id : 0;

		$items = array();
		foreach ( $this->groupLessons->listByGroup( $groupId ) as $row ) {
			if ( ! $row->kind->isIndividual() || null === $row->studentPersonId ) {
				continue;
			}

			$lesson    = $row->lessonId ? $this->lessonManager->get( $row->lessonId ) : null;
			$effRoomId = ! empty( $row->roomId ) ? (int) $row->roomId : $groupRoomId;
			$items[]   = array(
				'group_lesson_id'   => $row->id,
				'group_id'          => $groupId,
				'group_name'        => $group->name,
				'subject'           => $group->subject_key,
				'student_person_id' => $row->studentPersonId,
				'student_name'      => $names[ $row->studentPersonId ] ?? '—',
				'scheduled_at'      => $row->scheduledAt,
				'ends_at'           => $row->endsAt, // B2: время окончания (префилл правки)
				'room'              => ( $effRoomId && isset( $roomNames[ $effRoomId ] ) ) ? $roomNames[ $effRoomId ] : '',
				'room_id'           => ! empty( $row->roomId ) ? (int) $row->roomId : 0, // B2: для префилла правки
				'lesson_id'         => $row->lessonId,
				'topic'             => $lesson?->topic ?? ( $row->label ?? '' ),
			);
		}

		usort( $items, static fn( $a, $b ) => strcmp( (string) $a['scheduled_at'], (string) $b['scheduled_at'] ) );

		return $items;
	}

	/**
	 * НБ-9: уроки предмета группы для назначения индивидуальному занятию — сперва
	 * уроки назначенного курса (`in_course`), затем остальные уроки предмета;
	 * фильтр по названию.
	 *
	 * @param int    $groupId ID группы
	 * @param string $search  Фильтр по названию урока
	 *
	 * @return array<int, array{id:int, title:string, in_course:bool}>
	 */
	public function lessonCandidatesForGroup( int $groupId, string $search = '', bool $courseOnly = false ): array {
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			return array();
		}

		$courseLessonIds = array();
		if ( ! empty( $group->course_id ) ) {
			$course = $this->courses->get( (int) $group->course_id );
			if ( $course ) {
				foreach ( $course->lessonIds() as $lid ) {
					$courseLessonIds[ (int) $lid ] = true;
				}
			}
		}

		// Только темы курса группы — в порядке курса, без выборки из общего банка (лимит банка может срезать часть курса).
		if ( $courseOnly ) {
			$out = array();
			foreach ( array_keys( $courseLessonIds ) as $lid ) {
				$lesson = $this->lessonManager->get( $lid );
				if ( ! $lesson || ( '' !== $search && false === mb_stripos( $lesson->topic, $search ) ) ) {
					continue;
				}
				$out[] = array(
					'id'        => $lesson->id,
					'title'     => $lesson->topic,
					'in_course' => true,
				);
			}

			return $out;
		}

		$args = array( 'limit' => 100 );
		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		$out = array();
		foreach ( $this->lessonManager->getBankBySubject( (string) $group->subject_key, $args ) as $lesson ) {
			$out[] = array(
				'id'        => $lesson->id,
				'title'     => $lesson->topic,
				'in_course' => isset( $courseLessonIds[ (int) $lesson->id ] ),
			);
		}

		// Уроки курса — первыми (стабильная сортировка сохраняет исходный порядок внутри групп).
		usort( $out, static fn( $a, $b ) => ( $b['in_course'] <=> $a['in_course'] ) );

		return $out;
	}

	/**
	 * НБ-9: привязывает урок банка к индивидуальному занятию (`group_lessons.lesson_id`).
	 *
	 * @param int $groupLessonId ID индивидуального занятия
	 * @param int $lessonId      ID урока банка
	 * @param int $actorUserId   Автор изменения
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException Если строка не найдена/не индивидуальная или урок не найден
	 */
	public function assignLessonToIndividual( int $groupLessonId, int $lessonId, int $actorUserId ): void {
		$row = $this->requireIndividual( $groupLessonId );
		$this->assertLessonExists( $lessonId );

		$this->groupLessons->setLessonId( $groupLessonId, $lessonId );
		$this->events->groupChanged( $row->groupId, $actorUserId );
	}

	/**
	 * Правка индивидуального занятия (B2): дата/время, кабинет, ученик, урок (тема).
	 * null-поля не меняются. Новый ученик должен состоять в группе занятия.
	 *
	 * @param int         $groupLessonId   ID индивидуального занятия
	 * @param string|null $scheduledAt     Новое начало
	 * @param string|null $endsAt          Новое окончание
	 * @param int|null    $roomId          Новый кабинет (0 — снять)
	 * @param int|null    $studentPersonId Новый ученик
	 * @param int|null    $lessonId        Новый урок банка
	 * @param int         $actorUserId     Автор изменения
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException Если занятие не найдено, ученик не в группе или урок не найден
	 */
	public function updateIndividualLesson(
		int     $groupLessonId,
		?string $scheduledAt,
		?string $endsAt,
		?int    $roomId,
		?int    $studentPersonId,
		?int    $lessonId,
		int     $actorUserId
	): void {
		$row = $this->requireIndividual( $groupLessonId );

		// Кабинет после правки — новый или прежний, если время сдвинулось: в обоих случаях проверка и запись идут под его блокировкой.
		$effectiveRoom = null !== $roomId ? $roomId : ( $row->roomId ?? 0 );
		$timeChanged   = null !== $scheduledAt && '' !== $scheduledAt;
		if ( $effectiveRoom > 0 && ( $timeChanged || ( null !== $roomId && $roomId > 0 ) ) ) {
			$this->inTransaction( function () use ( $groupLessonId, $row, $scheduledAt, $endsAt, $roomId, $effectiveRoom, $timeChanged ): void {
				$this->rooms->lockForUpdate( $effectiveRoom );
				$start = $timeChanged ? (string) $scheduledAt : (string) $row->scheduledAt;
				$end   = $timeChanged ? $endsAt : $row->endsAt;
				if ( '' !== $start ) {
					$this->assertNoExamInRoom( $effectiveRoom, $start, $end );
				}
				$this->writeSchedule( $groupLessonId, $row, $scheduledAt, $endsAt, $roomId );
			} );
		} else {
			$this->writeSchedule( $groupLessonId, $row, $scheduledAt, $endsAt, $roomId );
		}

		if ( null !== $studentPersonId && $studentPersonId > 0 ) {
			$this->assertMember( $row->groupId, $studentPersonId );
			$this->groupLessons->setStudentPersonId( $groupLessonId, $studentPersonId );
		}

		if ( null !== $lessonId && $lessonId > 0 ) {
			$this->assertLessonExists( $lessonId );
			$this->groupLessons->setLessonId( $groupLessonId, $lessonId );
		}

		$this->events->groupChanged( $row->groupId, $actorUserId );
	}

	/** Запись даты/времени и кабинета правки занятия. */
	private function writeSchedule( int $groupLessonId, GroupLessonDTO $row, ?string $scheduledAt, ?string $endsAt, ?int $roomId ): void {
		if ( null !== $scheduledAt && '' !== $scheduledAt ) {
			$this->groupLessons->updateSchedule( $groupLessonId, $scheduledAt, $row->teacherUserId, $endsAt );
		}

		if ( null !== $roomId ) {
			$this->groupLessons->setRoom( $groupLessonId, $roomId > 0 ? $roomId : null );
		}
	}

	/**
	 * Кабинет не занят сеансом экзамена в окне занятия (занятие без конца — час, как в `RoomRepository::isBusy()`).
	 *
	 * @throws \InvalidArgumentException
	 */
	private function assertNoExamInRoom( int $roomId, string $scheduledAt, ?string $endsAt ): void {
		$end = ( null !== $endsAt && '' !== $endsAt )
			? $endsAt
			: ( new \DateTimeImmutable( $scheduledAt ) )->modify( '+60 minutes' )->format( 'Y-m-d H:i:s' );

		if ( $this->roomAvailability->hasExamConflict( $roomId, $scheduledAt, $end ) ) {
			throw new \InvalidArgumentException( 'Кабинет занят экзаменом в это время.' );
		}
	}

	/**
	 * Индивидуальное занятие или исключение.
	 *
	 * @param int $groupLessonId ID занятия
	 *
	 * @throws \InvalidArgumentException Если занятие не найдено или не индивидуальное
	 */
	private function requireIndividual( int $groupLessonId ): GroupLessonDTO {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row || ! $row->kind->isIndividual() ) {
			throw new \InvalidArgumentException( 'Индивидуальное занятие не найдено.' );
		}

		return $row;
	}

	/**
	 * Проверяет, что ученик состоит в группе.
	 *
	 * @param int $groupId         ID группы
	 * @param int $studentPersonId ID ученика
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException Если ученик не в группе
	 */
	private function assertMember( int $groupId, int $studentPersonId ): void {
		foreach ( $this->records->findActiveByGroupId( $groupId ) as $rec ) {
			if ( $rec->studentPersonId === $studentPersonId ) {
				return;
			}
		}

		throw new \InvalidArgumentException( 'Ученик не состоит в этой группе.' );
	}

	/**
	 * Проверяет существование урока банка.
	 *
	 * @param int $lessonId ID урока
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException Если урок не найден
	 */
	private function assertLessonExists( int $lessonId ): void {
		if ( null === $this->lessonManager->get( $lessonId ) ) {
			throw new \InvalidArgumentException( 'Урок не найден.' );
		}
	}
}
