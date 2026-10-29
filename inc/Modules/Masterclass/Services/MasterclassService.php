<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Services;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Person\UserDTO;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Course\AccessMode;
use Inc\Enums\Course\LessonVisibility;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Person\UserManager;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\DTO\MasterclassInputDTO;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\GroupLessonUsageGuard;
use Inc\Services\Course\OpenCourseValidator;
use Inc\Services\Exam\ExamTime;
use Inc\Shared\Traits\TransactionRunner;

/**
 * Class MasterclassService
 *
 * Мастер-класс как объект: создание, правка, продление, запрос удаления, списки для страницы.
 *
 * ### Что такое мастер-класс в данных ядра
 *
 * Открытая группа (`AccessMode::Open`) без курса, в ней одно занятие с уроком банка (`visibility = open`,
 * расписание — окно мастер-класса) и строка `fs_lms_masterclasses` с UTC-сроками. Создание — одна транзакция:
 * сбой на любом шаге не оставляет ни группы, ни занятия.
 *
 * ### Сроки
 *
 * Считаются от конца окна при создании и дальше «Продлить» сдвигает их все на одну разницу. Правка, не
 * менявшая окно (школа, преподаватель), сроки и отметки тика не трогает: иначе она затёрла бы продление.
 *
 * ### Время
 *
 * Форма присылает местное время; строки модуля хранят UTC (`ExamTime`), а расписание занятия ядра — местное.
 *
 * @package Inc\Modules\Masterclass\Services
 */
class MasterclassService {

	use TransactionRunner;

	/** Предел продления за один раз, дней. */
	public const EXTEND_MAX_DAYS = 60;

	/** Предел названия школы (в имя группы добавляются ещё префикс и дата). */
	public const SCHOOL_MAX = 200;

	/** Сколько мастер-классов одной школы за один день различает суффикс « (N)» в имени группы. */
	private const NAME_VARIANTS_MAX = 100;

	private const DATE_FORMAT = 'Y-m-d H:i:s';

	public function __construct(
		private readonly MasterclassRepository $masterclasses,
		private readonly MasterclassAccountRepository $accounts,
		private readonly MasterclassAccountService $accountService,
		private readonly GroupsRepository $groups,
		private readonly GroupLessonRepository $groupLessons,
		private readonly AcademicPeriodRepository $periods,
		private readonly LessonManager $lessons,
		private readonly OpenCourseValidator $validator,
		private readonly GroupLessonUsageGuard $usage,
		private readonly UserRepository $teachers,
		private readonly UserManager $users,
		private readonly MasterclassConfig $config,
		private readonly MasterclassLogWriter $log,
		private readonly ExamTime $time,
		private readonly ClockInterface $clock,
	) {}

	/**
	 * Создаёт мастер-класс: группа → занятие → строка в одной транзакции, журнал — после неё. Аккаунтов не создаёт.
	 *
	 * @throws \InvalidArgumentException Пустая школа, окно не по порядку, урок непригоден, не преподаватель, нет текущего периода.
	 * @throws \RuntimeException         Группа, занятие или строка не создались (транзакция откатана).
	 */
	public function create( MasterclassInputDTO $input, int $actorUserId ): MasterclassDTO {
		$school = $this->validatedSchool( $input->schoolName );
		$this->assertWindow( $input->startsAtLocal, $input->endsAtLocal );
		$this->assertTeacher( $input->teacherUserId );
		$lesson = $this->requireUsableLesson( $input->lessonId, $input->subjectKey );

		$period = $this->periods->getCurrentPeriod();
		if ( null === $period ) {
			throw new \InvalidArgumentException( 'Нет текущего учебного периода — задайте его в настройках, иначе некуда создать группу.' );
		}

		$nowUtc    = $this->clock->now( 'mysql', true );
		$startsUtc = $this->time->toUtc( $input->startsAtLocal );
		$endsUtc   = $this->time->toUtc( $input->endsAtLocal );
		$groupName = $this->groupName( $school, $input->startsAtLocal, $period->id );

		$id = (int) $this->inTransaction(
			function () use ( $input, $school, $lesson, $period, $groupName, $nowUtc, $startsUtc, $endsUtc, $actorUserId ): int {
				$groupId = $this->groups->create(
					array(
						'subject_key'        => $input->subjectKey,
						'academic_period_id' => $period->id,
						'name'               => $groupName,
						'teacher_id'         => $input->teacherUserId,
						'meetings'           => '[]',
						'access_mode'        => AccessMode::Open->value,
					)
				);
				if ( $groupId <= 0 ) {
					throw new \RuntimeException( 'Не удалось создать группу мастер-класса.' );
				}

				$groupLessonId = $this->groupLessons->add(
					new GroupLessonInputDTO(
						groupId:         $groupId,
						lessonId:        $lesson->id,
						position:        0,
						workIdsSnapshot: $lesson->workIds(),
						scheduledAt:     $input->startsAtLocal,
						endsAt:          $input->endsAtLocal,
						teacherUserId:   $input->teacherUserId,
						visibility:      LessonVisibility::Open->value,
						openedAt:        null,
						createdByUserId: $actorUserId > 0 ? $actorUserId : null,
					)
				);
				if ( $groupLessonId <= 0 ) {
					throw new \RuntimeException( 'Не удалось создать занятие мастер-класса.' );
				}

				return $this->masterclasses->insert(
					array_merge(
						array(
							'school_name'        => $school,
							'subject_key'        => $input->subjectKey,
							'lesson_id'          => $lesson->id,
							'group_id'           => $groupId,
							'group_lesson_id'    => $groupLessonId,
							'teacher_user_id'    => $input->teacherUserId,
							'starts_at'          => $startsUtc,
							'ends_at'            => $endsUtc,
							'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
							'created_at'         => $nowUtc,
							'updated_at'         => $nowUtc,
						),
						$this->deadlines( $endsUtc )
					)
				);
			}
		);

		$this->log->write(
			MasterclassLogAction::MasterclassCreated,
			array(
				'masterclass_id' => $id,
				'actor'          => $actorUserId,
				'context'        => array( 'school' => $school, 'lesson_id' => $lesson->id, 'starts_at' => $startsUtc ),
			)
		);

		return $this->reload( $id );
	}

	/**
	 * Правит мастер-класс. Предмет не меняется; урок — только пока по занятию нет данных учеников; окно пересчитывает
	 * сроки и сбрасывает отметки тика; школа и преподаватель переезжают в группу и занятие.
	 *
	 * @throws \InvalidArgumentException Мастер-класса нет / вычищен / удаляется; ввод не прошёл проверку; урок уже проходили.
	 * @throws \RuntimeException         Группа или занятие не обновились (транзакция откатана).
	 */
	public function update( int $id, MasterclassInputDTO $input, int $actorUserId ): MasterclassDTO {
		$current = $this->requireEditable( $id );

		$school = $this->validatedSchool( $input->schoolName );
		$this->assertWindow( $input->startsAtLocal, $input->endsAtLocal );
		$this->assertTeacher( $input->teacherUserId );
		if ( $input->subjectKey !== $current->subjectKey ) {
			throw new \InvalidArgumentException( 'Предмет мастер-класса изменить нельзя — создайте новый мастер-класс.' );
		}

		$groupId       = (int) $current->groupId;
		$groupLessonId = (int) $current->groupLessonId;

		$lesson = null;
		if ( $input->lessonId !== $current->lessonId ) {
			if ( $this->usage->hasLearningData( $groupLessonId ) ) {
				throw new \InvalidArgumentException( 'Урок уже начали проходить — сменить его нельзя.' );
			}
			$lesson = $this->requireUsableLesson( $input->lessonId, $input->subjectKey );
		}

		$startsUtc      = $this->time->toUtc( $input->startsAtLocal );
		$endsUtc        = $this->time->toUtc( $input->endsAtLocal );
		$windowChanged  = $startsUtc !== $current->startsAt || $endsUtc !== $current->endsAt;
		$teacherChanged = $input->teacherUserId !== $current->teacherUserId;

		$group = array( 'teacher_id' => $input->teacherUserId );
		if ( $school !== $current->schoolName || $this->localDate( $input->startsAtLocal ) !== $this->localDate( $this->time->toLocal( $current->startsAt ) ) ) {
			$group['name'] = $this->groupName( $school, $input->startsAtLocal, $this->periodOfGroup( $groupId ) );
		}

		$nowUtc = $this->clock->now( 'mysql', true );
		$row    = array(
			'school_name'     => $school,
			'teacher_user_id' => $input->teacherUserId,
			'updated_at'      => $nowUtc,
		);
		if ( null !== $lesson ) {
			$row['lesson_id'] = $lesson->id;
		}
		if ( $windowChanged ) {
			$row = array_merge(
				$row,
				array(
					'starts_at'        => $startsUtc,
					'ends_at'          => $endsUtc,
					'frozen_at'        => null,
					'unused_purged_at' => null,
				),
				$this->deadlines( $endsUtc )
			);
		}

		$this->inTransaction(
			function () use ( $id, $input, $groupId, $groupLessonId, $group, $lesson, $row, $windowChanged, $teacherChanged ): void {
				if ( ! $this->groups->update( $groupId, $group ) ) {
					throw new \RuntimeException( 'Не удалось обновить группу мастер-класса.' );
				}

				if ( ( $windowChanged || $teacherChanged ) && ! $this->groupLessons->updateSchedule( $groupLessonId, $input->startsAtLocal, $input->teacherUserId, $input->endsAtLocal ) ) {
					throw new \RuntimeException( 'Не удалось обновить расписание занятия мастер-класса.' );
				}

				if ( null !== $lesson && ( ! $this->groupLessons->setLessonId( $groupLessonId, $lesson->id ) || ! $this->groupLessons->setWorkIdsSnapshot( $groupLessonId, $lesson->workIds() ) ) ) {
					throw new \RuntimeException( 'Не удалось сменить урок занятия мастер-класса.' );
				}

				$this->masterclasses->update( $id, $row );
			}
		);

		$this->log->write(
			MasterclassLogAction::MasterclassUpdated,
			array(
				'masterclass_id' => $id,
				'actor'          => $actorUserId,
				'context'        => array( 'window_changed' => $windowChanged, 'lesson_changed' => null !== $lesson ),
			)
		);

		return $this->reload( $id );
	}

	/**
	 * Продлевает: заморозка уходит на `$days` дней от большего из (текущей заморозки, сейчас), остальные сроки — на ту же
	 * разницу; отметки тика сбрасываются, чтобы заморозка и чистка неиспользованных отработали заново.
	 *
	 * @throws \InvalidArgumentException `$days` вне 1–60; мастер-класса нет / вычищен / удаляется.
	 */
	public function extend( int $id, int $days, int $actorUserId ): MasterclassDTO {
		if ( $days < 1 || $days > self::EXTEND_MAX_DAYS ) {
			throw new \InvalidArgumentException( sprintf( 'Продлить можно на 1–%d дней.', self::EXTEND_MAX_DAYS ) );
		}

		$current   = $this->requireEditable( $id );
		$base      = max( $current->freezeAt, $this->clock->now( 'mysql', true ) );
		$freezeAt  = $this->shift( $base, $days * DAY_IN_SECONDS );
		$delta     = $this->time->secondsUntil( $current->freezeAt, $freezeAt );

		$this->masterclasses->update(
			$id,
			array(
				'freeze_at'        => $freezeAt,
				'unused_purge_at'  => $this->shift( $current->unusedPurgeAt, $delta ),
				'purge_at'         => $this->shift( $current->purgeAt, $delta ),
				'frozen_at'        => null,
				'unused_purged_at' => null,
				'updated_at'       => $this->clock->now( 'mysql', true ),
			)
		);

		$this->log->write(
			MasterclassLogAction::MasterclassExtended,
			array(
				'masterclass_id' => $id,
				'actor'          => $actorUserId,
				'context'        => array( 'days' => $days ),
			)
		);

		return $this->reload( $id );
	}

	/**
	 * Помечает мастер-класс к удалению — аккаунты и группу вычистит тик. Повторный вызов безвреден и в журнал не пишется.
	 *
	 * @throws \InvalidArgumentException Мастер-класса нет или он уже вычищен.
	 *
	 * @return int Сколько аккаунтов держат живую заявку (их отвяжут, а не удалят) — для текста ответа.
	 */
	public function requestDelete( int $id, int $actorUserId ): int {
		$current = $this->requireExisting( $id );
		if ( $current->isPurged() ) {
			throw new \InvalidArgumentException( 'Мастер-класс уже вычищен.' );
		}

		$first = ! $current->isDeleteRequested();
		if ( $first ) {
			$this->masterclasses->markDeleteRequested( $id, $this->clock->now( 'mysql', true ) );
		}

		$detached = $this->accountService->countWithLiveApplication( $id );

		if ( $first ) {
			$this->log->write(
				MasterclassLogAction::DeleteRequested,
				array(
					'masterclass_id' => $id,
					'actor'          => $actorUserId,
					'context'        => array( 'detached' => $detached ),
				)
			);
		}

		return $detached;
	}

	public function find( int $id ): ?MasterclassDTO {
		return $this->masterclasses->find( $id );
	}

	/**
	 * Страница списка мастер-классов с воронкой и состоянием.
	 *
	 * @return array{rows: list<array{masterclass: MasterclassDTO, funnel: \Inc\Modules\Masterclass\DTO\MasterclassFunnelDTO, status: \Inc\Modules\Masterclass\Enums\MasterclassStatus}>, total: int}
	 */
	public function overview( int $page, int $perPage ): array {
		$result = $this->masterclasses->page( $page, $perPage );
		$nowUtc = $this->clock->now( 'mysql', true );

		$rows = array();
		foreach ( $result['rows'] as $masterclass ) {
			$rows[] = array(
				'masterclass' => $masterclass,
				'funnel'      => $this->accounts->funnel( $masterclass->id ),
				'status'      => $masterclass->status( $nowUtc ),
			);
		}

		return array( 'rows' => $rows, 'total' => $result['total'] );
	}

	/**
	 * Опубликованные уроки банка предмета. Непригодные (нужна ручная проверка) отдаются с причинами: форма показывает их
	 * неактивными.
	 *
	 * @return list<array{id: int, title: string, problems: list<string>}>
	 */
	public function lessonOptions( string $subjectKey ): array {
		$options = array();
		foreach ( $this->lessons->getBankBySubject( $subjectKey, array( 'status' => 'publish', 'limit' => -1 ) ) as $lesson ) {
			$options[] = array(
				'id'       => $lesson->id,
				'title'    => $lesson->topic,
				'problems' => array_values( $this->validator->lessonProblems( $lesson ) ),
			);
		}

		return $options;
	}

	/**
	 * @return list<array{id: int, name: string}>
	 */
	public function teacherOptions(): array {
		return array_map(
			static fn ( UserDTO $user ): array => array( 'id' => $user->id, 'name' => $user->displayName ),
			$this->teachers->getByRole( UserRole::FSTeacher )
		);
	}

	/**
	 * Сроки от конца окна: заморозка через `freeze_days`, удаление неиспользованных через `unused_purge_days`, остальное —
	 * через `frozen_purge_days` после заморозки.
	 *
	 * @return array{freeze_at: string, unused_purge_at: string, purge_at: string}
	 */
	private function deadlines( string $endsAtUtc ): array {
		$freezeAt = $this->shift( $endsAtUtc, $this->config->freezeDays() * DAY_IN_SECONDS );

		return array(
			'freeze_at'       => $freezeAt,
			'unused_purge_at' => $this->shift( $endsAtUtc, $this->config->unusedPurgeDays() * DAY_IN_SECONDS ),
			'purge_at'        => $this->shift( $freezeAt, $this->config->frozenPurgeDays() * DAY_IN_SECONDS ),
		);
	}

	/** «Мастер-класс · {школа} · {дд.мм.гггг}»; занято в периоде — « (2)», « (3)». */
	private function groupName( string $school, string $startsAtLocal, string $periodId ): string {
		$base = sprintf( 'Мастер-класс · %s · %s', $school, $this->localDate( $startsAtLocal ) );
		$name = $base;

		for ( $n = 2; $this->groups->existsByNameAndPeriod( $name, $periodId ); ++$n ) {
			if ( $n > self::NAME_VARIANTS_MAX ) {
				throw new \InvalidArgumentException( 'Слишком много мастер-классов этой школы на одну дату — измените название школы.' );
			}
			$name = sprintf( '%s (%d)', $base, $n );
		}

		return $name;
	}

	private function localDate( string $local ): string {
		return ( new \DateTimeImmutable( $local ) )->format( 'd.m.Y' );
	}

	/** Период группы: имя перегруппы проверяется на занятость внутри него. */
	private function periodOfGroup( int $groupId ): string {
		$group = $this->groups->findById( $groupId );
		if ( null === $group ) {
			throw new \RuntimeException( 'Группа мастер-класса не найдена.' );
		}

		return (string) $group->academic_period_id;
	}

	/** Сдвигает UTC-время на секунды (в том числе дробное число дней/часов). */
	private function shift( string $utc, int $seconds ): string {
		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )
			->modify( sprintf( '%+d seconds', $seconds ) )
			->format( self::DATE_FORMAT );
	}

	private function validatedSchool( string $school ): string {
		$school = trim( $school );

		if ( '' === $school ) {
			throw new \InvalidArgumentException( 'Укажите школу.' );
		}
		if ( mb_strlen( $school ) > self::SCHOOL_MAX ) {
			throw new \InvalidArgumentException( sprintf( 'Название школы длиннее %d символов.', self::SCHOOL_MAX ) );
		}

		return $school;
	}

	/** @throws \InvalidArgumentException Даты не в формате `Y-m-d H:i:s` или конец не позже начала. */
	private function assertWindow( string $startsAtLocal, string $endsAtLocal ): void {
		foreach ( array( $startsAtLocal, $endsAtLocal ) as $local ) {
			$parsed = \DateTimeImmutable::createFromFormat( '!' . self::DATE_FORMAT, $local );
			if ( false === $parsed || $parsed->format( self::DATE_FORMAT ) !== $local ) {
				throw new \InvalidArgumentException( 'Начало и конец — это дата и время в формате «ГГГГ-ММ-ДД ЧЧ:ММ:СС».' );
			}
		}

		if ( $endsAtLocal <= $startsAtLocal ) {
			throw new \InvalidArgumentException( 'Конец мастер-класса должен быть позже начала.' );
		}
	}

	/** @throws \InvalidArgumentException Пользователя нет или у него нет роли преподавателя. */
	private function assertTeacher( int $userId ): void {
		$user = $userId > 0 ? $this->users->find( $userId ) : null;

		if ( null === $user || ! in_array( UserRole::FSTeacher->value, (array) $user->roles, true ) ) {
			throw new \InvalidArgumentException( 'Выберите преподавателя из списка.' );
		}
	}

	/** @throws \InvalidArgumentException Урока нет, он не опубликован, другого предмета или требует ручной проверки. */
	private function requireUsableLesson( int $lessonId, string $subjectKey ): LessonDTO {
		$lesson = $this->lessons->get( $lessonId );

		if ( null === $lesson ) {
			throw new \InvalidArgumentException( 'Урок не найден.' );
		}
		if ( ! $this->lessons->isPublished( $lessonId ) ) {
			throw new \InvalidArgumentException( 'Урок не опубликован.' );
		}
		if ( $lesson->subjectKey !== $subjectKey ) {
			throw new \InvalidArgumentException( 'Урок другого предмета.' );
		}

		$problems = $this->validator->lessonProblems( $lesson );
		if ( array() !== $problems ) {
			throw new \InvalidArgumentException( 'Урок нельзя взять: он требует ручной проверки. ' . implode( '; ', $problems ) );
		}

		return $lesson;
	}

	/** @throws \InvalidArgumentException */
	private function requireExisting( int $id ): MasterclassDTO {
		$masterclass = $this->masterclasses->find( $id );
		if ( null === $masterclass ) {
			throw new \InvalidArgumentException( 'Мастер-класс не найден.' );
		}

		return $masterclass;
	}

	/** @throws \InvalidArgumentException Нет мастер-класса, он вычищен, помечен к удалению или остался без группы/занятия. */
	private function requireEditable( int $id ): MasterclassDTO {
		$masterclass = $this->requireExisting( $id );

		if ( $masterclass->isPurged() ) {
			throw new \InvalidArgumentException( 'Мастер-класс уже вычищен.' );
		}
		if ( $masterclass->isDeleteRequested() ) {
			throw new \InvalidArgumentException( 'Мастер-класс помечен к удалению — менять его нельзя.' );
		}
		if ( null === $masterclass->groupId || null === $masterclass->groupLessonId ) {
			throw new \InvalidArgumentException( 'У мастер-класса нет группы или занятия.' );
		}

		return $masterclass;
	}

	private function reload( int $id ): MasterclassDTO {
		$masterclass = $this->masterclasses->find( $id );
		if ( null === $masterclass ) {
			throw new \RuntimeException( 'Мастер-класс не найден после сохранения.' );
		}

		return $masterclass;
	}
}
