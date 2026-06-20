<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Core\BaseController;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Log\OperationType;
use Inc\Enums\Course\WeekDay;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class StudentGroupCallbacks
 *
 * AJAX-обработчики для управления группами студентов.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Создание группы** — сохранение новой учебной группы (название, период, предмет, расписание).
 * 2. **Получение студентов группы** — список активных студентов, привязанных к группе.
 * 3. **Удаление группы** — удаление группы (студенты открепляются, но не удаляются).
 *
 * ### Архитектурная роль:
 *
 * Делегирует работу с группами GroupsRepository, а со студентами — StudentRecordRepository.
 * Использует WeekDay enum для валидации дней недели в расписании.
 */
class StudentGroupCallbacks extends BaseController {

	use Authorizer;      // Трейт с методами authorize(), error(), success()
	use AjaxResponse;    // Трейт с методами success(), error()
	use Sanitizer;       // Трейт с методами sanitizeInt(), sanitizeText(), requireKey(), requireText()

	/**
	 * Конструктор коллбеков.
	 *
	 * @param GroupsRepository       $groupsRepository       Репозиторий групп
	 * @param StudentRecordRepository $studentRecordRepository Репозиторий записей студентов
	 * @param PersonRepository       $personRepository       Репозиторий лиц
	 */
	public function __construct(
		private readonly GroupsRepository            $groupsRepository,
		private readonly StudentRecordRepository     $studentRecordRepository,
		private readonly PersonRepository            $personRepository,
		private readonly LogEventDispatcherInterface $logEvents,
	) {
		parent::__construct();
	}

	/**
	 * Сохраняет (создаёт) новую группу студентов.
	 *
	 * @return void
	 */
	public function ajaxSaveStudentGroup(): void {
		$this->authorize( Nonce::Manager );

		// Валидация обязательных полей
		$title              = $this->requireText( 'title', error: 'Название группы обязательно для заполнения.' );
		$academic_period_id = $this->requireKey( 'period_id', error: 'Необходимо указать учебный период.' );
		$subject_key        = $this->requireKey( 'subject_id', error: 'Необходимо указать предмет.' );
		$teacher_id         = $this->sanitizeInt( 'teacher_id' ) ?: null;

		// Обработка расписания из JSON
		$schedule_json = $this->sanitizeText( 'schedule_json' );
		// wp_unslash() — удаляет экранирование слешей
		$raw_entries   = is_string( $schedule_json ) ? json_decode( wp_unslash( $schedule_json ), true ) : null;
		$schedule      = array();

		if ( is_array( $raw_entries ) ) {
			foreach ( $raw_entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				// WeekDay::tryFrom() — безопасное преобразование в enum (или null)
				$day = WeekDay::tryFrom( sanitize_key( (string) ( $entry['day'] ?? '' ) ) );
				if ( $day === null ) {
					continue;
				}
				$schedule[] = array(
					'day'   => $day->value,
					'start' => sanitize_text_field( (string) ( $entry['start'] ?? '' ) ),
					'end'   => sanitize_text_field( (string) ( $entry['end']   ?? '' ) ),
				);
			}
		}

		if ( $this->groupsRepository->existsByNameAndPeriod( $title, $academic_period_id ) ) {
			$this->error( 'Группа с таким названием в этом периоде уже существует.' );
		}

		// Создание группы в БД
		$id = $this->groupsRepository->create( array(
			'subject_key'        => $subject_key,
			'academic_period_id' => $academic_period_id,
			'name'               => $title,
			'teacher_id'         => $teacher_id,
			'meetings'           => (string) wp_json_encode( $schedule ),
		) );

		if ( ! $id ) {
			$this->error( 'Не удалось создать группу.' );
		}

		$this->logEvents->dispatch(
			LogEvent::GroupCreated,
			new EntityChangedEvent( get_current_user_id(), OperationType::Create, EntityType::Group, $id )
		);

		$this->success( array( 'id' => $id, 'title' => $title ) );
	}

	/**
	 * Получает список активных студентов в указанной группе.
	 *
	 * @return void
	 */
	public function ajaxGetStudentsByGroup(): void {
		$this->authorize( Nonce::Manager );

		$group_id = $this->sanitizeInt( 'group_id' );

		// findActiveByGroupId() — поиск активных записей студентов в группе
		$records = $this->studentRecordRepository->findActiveByGroupId( $group_id );

		$students = array();
		foreach ( $records as $record ) {
			$person = $this->personRepository->find( $record->studentPersonId );
			$wpUser = $person?->wpUserId ? get_userdata( $person->wpUserId ) : null;
			$students[] = array(
				'id'   => $record->studentPersonId,
				'name' => $wpUser ? $wpUser->display_name : ( $person?->fullName() ?: "Person #{$record->studentPersonId}" ),
			);
		}

		$this->success( $students );
	}

	/**
	 * Удаляет группу студентов.
	 *
	 * @return void
	 */
	public function ajaxDeleteStudentGroup(): void {
		$this->authorize( Nonce::Manager );

		$id = $this->sanitizeInt( 'id' );

		if ( ! $id ) {
			$this->error( 'Идентификатор группы не указан.' );
		}

		$group   = $this->groupsRepository->findById( $id );
		$deleted = $this->groupsRepository->delete( $id );

		if ( ! $deleted ) {
			$this->error( 'Ошибка удаления. Группа не найдена или уже удалена.' );
		}

		$this->logEvents->dispatch(
			LogEvent::GroupDeleted,
			new EntityChangedEvent( get_current_user_id(), OperationType::Delete, EntityType::Group, $id, $group?->name )
		);

		$this->success( array( 'id' => $id ) );
	}

	public function ajaxGetGroupStudentsDetail(): void {
		$this->authorize( Nonce::Manager );

		$group_id = $this->sanitizeInt( 'group_id' );
		$records  = $this->studentRecordRepository->findAllByGroup( $group_id );

		$parent_ids = array_values( array_unique(
			array_filter( array_map( fn( $r ) => $r->parentPersonId, $records ) )
		) );
		$parent_map = $this->personRepository->findByIds( $parent_ids );

		usort( $records, function ( $a, $b ) {
			$aOrd = EnrollmentStatus::Active === $a->status ? 0 : 1;
			$bOrd = EnrollmentStatus::Active === $b->status ? 0 : 1;
			if ( $aOrd !== $bOrd ) {
				return $aOrd <=> $bOrd;
			}
			return strcmp(
				$a->snapshotLastName . ' ' . $a->snapshotFirstName,
				$b->snapshotLastName . ' ' . $b->snapshotFirstName
			);
		} );

		$active_count = count( array_filter( $records, fn( $r ) => EnrollmentStatus::Active === $r->status ) );

		$students = array_map( function ( $record ) use ( $parent_map ) {
			$parent = $parent_map[ $record->parentPersonId ] ?? null;
			return array(
				'name'        => trim( $record->snapshotLastName . ' ' . $record->snapshotFirstName . ' ' . ( $record->snapshotMiddleName ?? '' ) ),
				'parent_name' => $parent ? $parent->fullName() : '—',
				'status'      => $record->status->label(),
				'status_key'  => $record->status->value,
				'contract_no' => $record->contractNo ?? '—',
			);
		}, $records );

		$this->success( array( 'active_count' => $active_count, 'students' => $students ) );
	}

	public function ajaxUpdateStudentGroup(): void {
		$this->authorize( Nonce::Manager );

		$id = $this->sanitizeInt( 'id' );
		if ( ! $id ) {
			$this->error( 'ID группы не указан.' );
		}

		$teacher_id    = $this->sanitizeInt( 'teacher_id' ) ?: null;
		$schedule_json = $this->sanitizeText( 'schedule_json' );
		$raw_entries   = is_string( $schedule_json ) ? json_decode( wp_unslash( $schedule_json ), true ) : null;
		$schedule      = array();

		if ( is_array( $raw_entries ) ) {
			foreach ( $raw_entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$day = WeekDay::tryFrom( sanitize_key( (string) ( $entry['day'] ?? '' ) ) );
				if ( null === $day ) {
					continue;
				}
				$schedule[] = array(
					'day'   => $day->value,
					'start' => sanitize_text_field( (string) ( $entry['start'] ?? '' ) ),
					'end'   => sanitize_text_field( (string) ( $entry['end']   ?? '' ) ),
				);
			}
		}

		$updated = $this->groupsRepository->update( $id, array(
			'teacher_id' => $teacher_id,
			'meetings'   => (string) wp_json_encode( $schedule ),
		) );

		if ( ! $updated ) {
			$this->error( 'Ошибка обновления группы.' );
		}

		$this->success( array( 'id' => $id ) );
	}
}