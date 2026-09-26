<?php

declare( strict_types=1 );

namespace Inc\Services\Print;

use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Person\DocumentType;
use Inc\Enums\Print\PrintField;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;

/**
 * Class TaxDeductionForm
 *
 * Значения полей справки об оплате образовательных услуг (форма по КНД 1151158,
 * шаблон `templates/documents/tax_deduction.pdf`).
 *
 * Налогоплательщик — родитель из зачисления, обучаемый — ученик (страница 2).
 * Номер справки, отчётный год и сумму вводят в Центре печати. Постоянные поля
 * (ИНН и наименование ИП, ФИО подтверждающего, число страниц) заполнены в самом
 * шаблоне и здесь не трогаются; «Очная форма обучения» пока всегда 0.
 *
 * Имена полей — те, что дал форме КонсультантПлюс (`Text…`): номера у полей
 * сквозные и ничего не говорят, поэтому карта ниже подписана.
 *
 * @package Inc\Services\Print
 */
class TaxDeductionForm {

	/** Коды вида документа (справочник СДУЛ ФНС). */
	private const array DOC_CODES = array(
		'pass'              => '21', // Паспорт гражданина РФ
		'foreign_pass'      => '10', // Паспорт иностранного гражданина
		'birth_certificate' => '03', // Свидетельство о рождении
	);

	public function __construct(
		private readonly PrintDataCollector        $collector,
		private readonly PersonDocumentsRepository $documents,
	) {}

	/**
	 * @param array{number: string, year: string, sum: string} $input Введено в Центре печати
	 *
	 * @return array{values: array<string, string>, empty: string[]} Значения по именам полей
	 *         и подписи незаполненных полей (данных в системе нет).
	 */
	public function build( PersonDTO $student, PersonDTO $parent, StudentRecordDTO $record, array $input ): array {
		$data = $this->collector->collect(
			array(
				PrintField::Today,
				PrintField::ParentLastName, PrintField::ParentFirstName, PrintField::ParentMiddleName,
				PrintField::ParentInn, PrintField::ParentBirthDate,
				PrintField::ParentDocNumber, PrintField::ParentDocIssuedDate,
				PrintField::StudentLastName, PrintField::StudentFirstName, PrintField::StudentMiddleName,
				PrintField::StudentInn, PrintField::StudentBirthDate,
				PrintField::StudentDocNumber, PrintField::StudentDocIssuedDate,
			),
			$student,
			$parent,
			$record
		);
		$get = static fn( PrintField $f ): string => trim( $data[ $f->value ] ?? '' );

		[ $rubles, $kopecks ] = $this->splitSum( $input['sum'] );

		$values = array_merge(
			array(
				'Text4'    => $input['number'],  // Номер справки
				'Text3'    => $input['year'],    // Отчётный год
				'Text14.1' => '0',               // Очная форма обучения: 0 — нет
				'Text14.0' => '0',               // Налогоплательщик и обучаемый — одно лицо: 0 — нет
				'Text15.0' => $rubles,           // Сумма расходов, руб.
				'Text16.0' => $kopecks,          // … коп.
				'Text30'   => $get( PrintField::Today ), // Дата внизу страницы 2
			),
			// Страница 1 — налогоплательщик (родитель)
			array(
				'Text7.0' => $get( PrintField::ParentLastName ),
				'Text7.1' => $get( PrintField::ParentFirstName ),
				'Text7.2' => $get( PrintField::ParentMiddleName ),
				'Text8'   => $this->digits( $get( PrintField::ParentInn ) ),
				'Text12'  => $this->docCode( $parent->id ),
				'Text13'  => $this->docNumber( $get( PrintField::ParentDocNumber ) ),
			),
			$this->date( 'Text9.0', 'Text10.0', 'Text11.0', $get( PrintField::ParentBirthDate ) ),
			$this->date( 'Text9.1.0.0', 'Text10.1.0.0', 'Text11.1.0.0', $get( PrintField::ParentDocIssuedDate ) ),
			$this->date( 'Text9.1.1', 'Text10.1.1', 'Text11.1.1', $get( PrintField::Today ) ), // дата подписи
			// Страница 2 — обучаемый (ученик)
			array(
				'Text18.0' => $get( PrintField::StudentLastName ),
				'Text18.1' => $get( PrintField::StudentFirstName ),
				'Text18.2' => $get( PrintField::StudentMiddleName ),
				'Text20'   => $this->digits( $get( PrintField::StudentInn ) ),
				'Text25'   => $this->docCode( $student->id ),
				'Text260'  => $this->docNumber( $get( PrintField::StudentDocNumber ) ),
			),
			$this->date( 'Text21.0', 'Text22.0', 'Text23.0', $get( PrintField::StudentBirthDate ) ),
			$this->date( 'Text21.1', 'Text22.1', 'Text23.1', $get( PrintField::StudentDocIssuedDate ) ),
		);

		$labels = array(
			'Text8'    => 'Родитель: ИНН',
			'Text9.0'  => 'Родитель: дата рождения',
			'Text12'   => 'Родитель: вид документа',
			'Text13'   => 'Родитель: серия и номер документа',
			'Text9.1.0.0' => 'Родитель: дата выдачи документа',
			'Text7.2'  => 'Родитель: отчество',
			'Text20'   => 'Ученик: ИНН',
			'Text21.0' => 'Ученик: дата рождения',
			'Text25'   => 'Ученик: вид документа',
			'Text260'  => 'Ученик: серия и номер документа',
			'Text21.1' => 'Ученик: дата выдачи документа',
			'Text18.2' => 'Ученик: отчество',
		);
		$empty = array();
		foreach ( $labels as $field => $label ) {
			if ( '' === ( $values[ $field ] ?? '' ) ) {
				$empty[] = $label;
			}
		}

		return array( 'values' => $values, 'empty' => $empty );
	}

	/**
	 * Дата «дд.мм.гггг» — в три поля (день, месяц, год).
	 *
	 * @return array<string, string>
	 */
	private function date( string $day, string $month, string $year, string $date ): array {
		$parts = preg_match( '~^(\d{2})\.(\d{2})\.(\d{4})$~', $date, $m ) ? array( $m[1], $m[2], $m[3] ) : array( '', '', '' );

		return array_combine( array( $day, $month, $year ), $parts );
	}

	/** Код вида документа лица; '' — вид не указан. */
	private function docCode( int $personId ): string {
		$type = $this->documents->findByPersonId( $personId )?->docType;

		return null !== $type && null !== DocumentType::tryFrom( $type ) ? ( self::DOC_CODES[ $type ] ?? '' ) : '';
	}

	/**
	 * Серия и номер: паспорт РФ (10 цифр) — «ХХ ХХ ХХХХХХ», как требует форма;
	 * остальные документы — как записаны.
	 */
	private function docNumber( string $number ): string {
		$digits = $this->digits( $number );

		return 10 === strlen( $digits ) && $digits === preg_replace( '~\D~', '', $number )
			? substr( $digits, 0, 2 ) . ' ' . substr( $digits, 2, 2 ) . ' ' . substr( $digits, 4 )
			: mb_strtoupper( preg_replace( '~\s+~u', ' ', $number ) );
	}

	private function digits( string $value ): string {
		return (string) preg_replace( '~\D~', '', $value );
	}

	/**
	 * Сумма «12 345,6» → ['12345', '60'].
	 *
	 * @return array{0: string, 1: string}
	 */
	private function splitSum( string $sum ): array {
		$normalized = str_replace( ',', '.', preg_replace( '~[^\d.,]~', '', $sum ) );
		$kopecks    = (int) round( (float) $normalized * 100 );

		return array( (string) intdiv( $kopecks, 100 ), sprintf( '%02d', $kopecks % 100 ) );
	}
}
