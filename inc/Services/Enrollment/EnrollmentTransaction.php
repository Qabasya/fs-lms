<?php

declare( strict_types=1 );

namespace Inc\Services\Enrollment;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Enrollment\EnrollmentInputDTO;
use Inc\DTO\Enrollment\StudentDataDTO;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\DTO\Person\ParentDataDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Person\ConsentService;
use Inc\Services\Person\PersonService;
use Inc\Shared\Traits\TransactionRunner;

/**
 * Class EnrollmentTransaction
 *
 * Атомарная часть зачисления: физлица ученика и родителя, запись
 * `student_records` и привязка согласий заявки к созданным физлицам.
 *
 * @package Inc\Services\Enrollment
 *
 * Всё, что не откатывается транзакцией (создание WP-учёток, письма, удаление
 * заявки), сюда не входит — этим занимается {@see EnrollmentService} после COMMIT.
 */
readonly class EnrollmentTransaction {

	use TransactionRunner;

	/**
	 * @param StudentRecordRepository $records  Записи о зачислении
	 * @param PersonService           $people   Создание/поиск физлиц
	 * @param ConsentService          $consents Привязка согласий заявки
	 * @param ClockInterface          $clock    Текущее время
	 */
	public function __construct(
		private StudentRecordRepository $records,
		private PersonService           $people,
		private ConsentService          $consents,
		private ClockInterface          $clock,
	) {}

	/**
	 * Создаёт запись о зачислении вместе с недостающими физлицами.
	 *
	 * @param ApplicationDTO     $app             Заявка
	 * @param EnrollmentInputDTO $input           Параметры зачисления (группа, договор, приказ)
	 * @param StudentDataDTO     $student         Данные ученика
	 * @param ParentDataDTO      $parent          Данные родителя
	 * @param PersonDTO|null     $existingStudent Найденный ученик (null — создать)
	 * @param PersonDTO|null     $existingParent  Найденный родитель (null — создать)
	 *
	 * @return array{0:int, 1:int, 2:int} [record_id, student_person_id, guardian_person_id]
	 */
	public function run(
		ApplicationDTO     $app,
		EnrollmentInputDTO $input,
		StudentDataDTO     $student,
		ParentDataDTO      $parent,
		?PersonDTO         $existingStudent,
		?PersonDTO         $existingParent
	): array {
		return $this->inTransaction( function () use ( $app, $input, $student, $parent, $existingStudent, $existingParent ): array {
			$now = $this->clock->now( 'mysql', true );

			$studentPersonId  = $existingStudent?->id ?? $this->people->createOrFindBy( $this->studentInput( $student ) );
			$guardianPersonId = $existingParent?->id ?? $this->people->createOrFindBy( $this->parentInput( $parent ) );

			// Физлицо завёл временный доступ по данным одного ученика — документы и ИНН
			// приехали позже, с анкетой родителя.
			if ( $app->trialOwner && null !== $existingStudent ) {
				$this->people->update( $studentPersonId, $this->studentChanges( $student ), get_current_user_id() );
			}

			// Временный доступ своё отработал: дальше ученик учится по записи зачисления.
			$this->records->deleteTrialByStudent( $studentPersonId );

			$recordId = $this->records->create( $this->recordInput( $input, $student, $studentPersonId, $guardianPersonId, $now ) );

			$this->consents->bindToPersons( $app->id, array(
				'self'     => $studentPersonId,
				'guardian' => $guardianPersonId,
			) );

			return array( $recordId, $studentPersonId, $guardianPersonId );
		} );
	}

	/**
	 * Данные физлица ученика.
	 *
	 * @param StudentDataDTO $student Данные ученика из заявки
	 */
	private function studentInput( StudentDataDTO $student ): PersonInputDTO {
		return PersonInputDTO::fromStudentData( $student );
	}

	/**
	 * Данные ученика для обновления физлица, заведённого временным доступом.
	 * Пустые значения не пишутся — не затираем то, что уже есть.
	 *
	 * @param StudentDataDTO $student Данные ученика из заявки
	 *
	 * @return array<string, string>
	 */
	private function studentChanges( StudentDataDTO $student ): array {
		return array_filter( array(
			'last_name'   => $student->lastName,
			'first_name'  => $student->firstName,
			'middle_name' => $student->middleName,
			'birth_date'  => $student->birthDate,
			'school'      => $student->school,
			'grade'       => $student->grade ? (string) $student->grade : '',
			'email'     => $student->email,
			'phone'       => $student->phone,
			'doc_type'    => $student->docType,
			'doc_number'  => $student->docNumber,
			'inn'         => $student->inn,
		), static fn( string $value ): bool => '' !== $value );
	}

	/**
	 * Данные физлица родителя.
	 *
	 * @param ParentDataDTO $parent Данные родителя из заявки
	 */
	private function parentInput( ParentDataDTO $parent ): PersonInputDTO {
		return new PersonInputDTO(
			lastName:      $parent->lastName,
			firstName:     $parent->firstName,
			docNumber:     $parent->docNumber,
			isStudent:     false,
			middleName:    $parent->middleName,
			docType:       $parent->docType,
			birthDate:     $parent->birthDate,
			inn:           $parent->inn,
			address:       $parent->address,
			phone:         $parent->phone,
			docIssuedBy:   $parent->docIssuedBy,
			docIssuedDate: $parent->docIssuedDate,
			email:         '' !== $parent->email ? $parent->email : null,
		);
	}

	/**
	 * Запись о зачислении: снимок ФИО/школы/класса берётся из заявки.
	 *
	 * @param EnrollmentInputDTO $input            Параметры зачисления
	 * @param StudentDataDTO     $student          Данные ученика
	 * @param int                $studentPersonId  Физлицо ученика
	 * @param int                $guardianPersonId Физлицо родителя
	 * @param string             $now              Текущее время (mysql)
	 */
	private function recordInput(
		EnrollmentInputDTO $input,
		StudentDataDTO     $student,
		int                $studentPersonId,
		int                $guardianPersonId,
		string             $now
	): StudentRecordInputDTO {
		return new StudentRecordInputDTO(
			studentPersonId:    $studentPersonId,
			parentPersonId:     $guardianPersonId,
			status:             'active',
			enrolledAt:         $input->enrolledAt,
			createdAt:          $now,
			updatedAt:          $now,
			groupId:            $input->groupId ?: null,
			snapshotLastName:   $student->lastName,
			snapshotFirstName:  $student->firstName,
			snapshotMiddleName: $student->middleName ?: null,
			snapshotSchool:     $student->school ?: null,
			snapshotGrade:      (string) $student->grade ?: null,
			contractNo:         $input->contractNo ?: null,
			contractDate:       $input->contractDate ?: null,
			orderNo:            $input->orderNo ?: null,
			orderDate:          $input->orderDate ?: null,
			enrolledByUserId:   get_current_user_id() ?: null,
		);
	}
}
