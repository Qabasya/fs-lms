<?php

declare( strict_types=1 );

namespace Inc\Services\Print;

use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Person\DocumentType;
use Inc\Enums\Print\PrintField;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\OptionsRepositories\PrintProgramsRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Services\Person\PersonReader;

/**
 * Class PrintDataCollector
 *
 * Значения полей документа для пары «ученик + зачисление».
 *
 * Собирает ТОЛЬКО запрошенные поля: зашифрованные ПДн расшифровываются, лишь
 * если шаблон их использует, и каждое раскрытие уходит в журнал доступа к ПД
 * с причиной `print_document` ({@see PersonReader}).
 *
 * @package Inc\Services\Print
 */
class PrintDataCollector {

	/**
	 * Причина раскрытия ПДн в журнале доступа.
	 */
	private const string PII_REASON = 'print_document';

	private const array MONTHS = array(
		1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
		'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
	);

	public function __construct(
		private readonly PersonReader              $personReader,
		private readonly PersonDocumentsRepository $documents,
		private readonly GroupsRepository          $groups,
		private readonly SubjectRepository         $subjects,
		private readonly AcademicPeriodRepository  $periods,
		private readonly PrintProgramsRepository   $programs,
	) {}

	/**
	 * @param PrintField[]     $fields  Поля, которые нужны шаблону
	 * @param PersonDTO        $student Ученик
	 * @param PersonDTO        $parent  Родитель из зачисления
	 * @param StudentRecordDTO $record  Зачисление
	 *
	 * @return array<string, string> Значения по ключам полей
	 */
	public function collect( array $fields, PersonDTO $student, PersonDTO $parent, StudentRecordDTO $record ): array {
		$wanted = array_map( static fn( PrintField $f ): string => $f->value, $fields );
		$has    = static fn( PrintField ...$any ): bool => (bool) array_intersect( $wanted, array_map( static fn( PrintField $f ): string => $f->value, $any ) );

		$values = array_merge(
			$this->dates( $record ),
			$this->person( 'student', $student ),
			$this->person( 'parent', $parent ),
		);

		if ( $has( PrintField::Subject, PrintField::Program, PrintField::Price, PrintField::Group, PrintField::Period, PrintField::PeriodStart, PrintField::PeriodEnd ) ) {
			$values = array_merge( $values, $this->education( $record ) );
		}

		if ( $has( PrintField::StudentDocType, PrintField::StudentDocNumber, PrintField::StudentDocIssuedBy, PrintField::StudentDocIssuedDate, PrintField::StudentInn ) ) {
			$values = array_merge( $values, $this->pii( 'student', $student->id, $wanted ) );
		}

		if ( $has( PrintField::ParentDocType, PrintField::ParentDocNumber, PrintField::ParentDocIssuedBy, PrintField::ParentDocIssuedDate, PrintField::ParentInn, PrintField::ParentAddress, PrintField::ParentPhone, PrintField::ParentEmail ) ) {
			$values = array_merge( $values, $this->pii( 'parent', $parent->id, $wanted ) );
		}

		return array_intersect_key( $values, array_flip( $wanted ) );
	}

	/**
	 * Даты формирования и реквизиты зачисления.
	 *
	 * @return array<string, string>
	 */
	private function dates( StudentRecordDTO $record ): array {
		$today = wp_date( 'Y-m-d' );

		return array(
			PrintField::Today->value            => $this->date( $today ),
			PrintField::TodayText->value        => $this->dateText( $today ),
			PrintField::ContractNo->value       => (string) $record->contractNo,
			PrintField::ContractDate->value     => $this->date( $record->contractDate ),
			PrintField::ContractDateText->value => $this->dateText( $record->contractDate ),
			PrintField::OrderNo->value          => (string) $record->orderNo,
			PrintField::OrderDate->value        => $this->date( $record->orderDate ),
			PrintField::EnrolledDate->value     => $this->date( $record->enrolledAt ),
		);
	}

	/**
	 * Предмет (с программой и ценой), группа и учебный период зачисления.
	 *
	 * @return array<string, string>
	 */
	private function education( StudentRecordDTO $record ): array {
		$group   = $this->groups->findById( $record->groupId );
		$subject = $group ? ( $this->subjects->readAll()[ $group->subject_key ] ?? null ) : null;
		$period  = $group ? $this->periods->getById( (string) $group->academic_period_id ) : null;
		$program = $group ? $this->programs->get( (string) $group->subject_key ) : array( 'program' => '', 'price' => '' );

		return array(
			PrintField::Subject->value     => $subject ? $subject->name : '',
			PrintField::Program->value     => $program['program'],
			PrintField::Price->value       => $program['price'],
			PrintField::Group->value       => $group ? (string) $group->name : '',
			PrintField::Period->value      => $period ? $period->name : '',
			PrintField::PeriodStart->value => $period ? $this->date( $period->start_date ) : '',
			PrintField::PeriodEnd->value   => $period ? $this->date( $period->end_date ) : '',
		);
	}

	/**
	 * Открытые (незашифрованные) поля лица.
	 *
	 * @param string $prefix student | parent
	 *
	 * @return array<string, string>
	 */
	private function person( string $prefix, PersonDTO $person ): array {
		$values = array(
			"{$prefix}_full_name"   => $person->fullName(),
			"{$prefix}_short_name"  => $person->shortName(),
			"{$prefix}_last_name"   => $person->lastName,
			"{$prefix}_first_name"  => $person->firstName,
			"{$prefix}_middle_name" => (string) $person->middleName,
			"{$prefix}_birth_date"  => $this->date( $person->birthDate ),
		);

		if ( 'student' === $prefix ) {
			$values[ PrintField::StudentSchool->value ] = (string) $person->school;
			$values[ PrintField::StudentGrade->value ]  = (string) $person->grade;
		}

		return $values;
	}

	/**
	 * Зашифрованные поля лица — расшифровываются только запрошенные.
	 *
	 * @param string   $prefix   student | parent
	 * @param int      $personId ID лица
	 * @param string[] $wanted   Ключи полей шаблона
	 *
	 * @return array<string, string>
	 */
	private function pii( string $prefix, int $personId, array $wanted ): array {
		$want   = static fn( string $suffix ): bool => in_array( "{$prefix}_{$suffix}", $wanted, true );
		$values = array();

		// Поле документа PersonReader => суффикс поля шаблона.
		$map = array(
			'doc_number' => 'doc_number',
			'inn'        => 'inn',
			'address'    => 'address',
			'phone'      => 'phone',
			'email'      => 'email',
		);
		foreach ( $map as $source => $suffix ) {
			if ( $want( $suffix ) ) {
				$values[ "{$prefix}_{$suffix}" ] = $this->personReader->readField( $personId, $source, self::PII_REASON );
			}
		}

		if ( $want( 'doc_issued_by' ) || $want( 'doc_issued_date' ) ) {
			$issued = $this->personReader->readDocIssuedParts( $personId, self::PII_REASON );
			$values[ "{$prefix}_doc_issued_by" ]   = $issued['by'];
			$values[ "{$prefix}_doc_issued_date" ] = $this->date( $issued['date'] );
		}

		if ( $want( 'doc_type' ) ) {
			$type = $this->documents->findByPersonId( $personId )?->docType;
			$values[ "{$prefix}_doc_type" ] = $type ? ( DocumentType::tryFrom( $type )?->label() ?? $type ) : '';
		}

		return $values;
	}

	/**
	 * Дата «дд.мм.гггг»; пустая строка для пустой даты.
	 */
	private function date( ?string $value ): string {
		$ts = $this->timestamp( $value );

		return null === $ts ? '' : gmdate( 'd.m.Y', $ts );
	}

	/**
	 * Дата для договора: «01» сентября 2026 г.
	 */
	private function dateText( ?string $value ): string {
		$ts = $this->timestamp( $value );
		if ( null === $ts ) {
			return '';
		}

		return sprintf( '«%s» %s %s г.', gmdate( 'd', $ts ), self::MONTHS[ (int) gmdate( 'n', $ts ) ], gmdate( 'Y', $ts ) );
	}

	/**
	 * Метка времени календарной даты (время отбрасывается — важна только дата).
	 */
	private function timestamp( ?string $value ): ?int {
		if ( null === $value || '' === $value || str_starts_with( $value, '0000' ) ) {
			return null;
		}

		$ts = strtotime( substr( $value, 0, 10 ) . ' 00:00:00 UTC' );

		return false === $ts ? null : $ts;
	}
}
