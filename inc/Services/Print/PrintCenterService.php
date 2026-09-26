<?php

declare( strict_types=1 );

namespace Inc\Services\Print;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Log\Events\EnrollmentStatusEvent;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Export\ExportTarget;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Print\PrintDocument;
use Inc\Enums\Print\PrintField;
use Inc\Repositories\OptionsRepositories\PrintProgramsRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Export\OneTimeDownloadService;
use Inc\Services\Log\ExportLogWriter;

/**
 * Class PrintCenterService
 *
 * «Центр печати»: поиск ученика, его зачисления с родителем и сборка
 * документа по DOCX-шаблону.
 *
 * Шаблон формы — `templates/documents/{PrintDocument::value}.{docx|pdf}`. Родитель
 * берётся из зачисления (student_records.parent_person_id): у ученика в разных
 * группах могут быть разные договоры, поэтому документ собирается по зачислению,
 * а не по ученику. Готовый файл отдаётся одноразовой ссылкой; формирование
 * пишется в журнал экспорта (выгрузка ПД) и в журнал «Зачисления».
 *
 * @package Inc\Services\Print
 */
class PrintCenterService {

	public function __construct(
		private readonly PersonRepository        $persons,
		private readonly StudentRecordRepository $records,
		private readonly GroupsRepository        $groups,
		private readonly SubjectRepository       $subjects,
		private readonly DocxTemplateRenderer    $renderer,
		private readonly PrintDataCollector      $collector,
		private readonly OneTimeDownloadService  $downloads,
		private readonly ExportLogWriter         $exportLog,
		private readonly PrintProgramsRepository $programs,
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly PdfFormFiller           $pdfFiller,
		private readonly TaxDeductionForm        $taxForm,
		private readonly string                  $templatesDir = FS_LMS_PATH . 'templates/documents/',
	) {}

	/**
	 * Путь к шаблону формы.
	 */
	public function templatePath( PrintDocument $document ): string {
		return $this->templatesDir . $document->value . '.' . $document->extension();
	}

	/**
	 * Шаблон формы загружен.
	 */
	public function hasTemplate( PrintDocument $document ): bool {
		return is_readable( $this->templatePath( $document ) );
	}

	/**
	 * Ученики для выпадающего списка поиска.
	 *
	 * @return array<int, array{id: int, name: string, hint: string}>
	 */
	public function searchStudents( string $query ): array {
		if ( mb_strlen( trim( $query ) ) < 2 ) {
			return array();
		}

		return array_map(
			fn( PersonDTO $p ): array => array(
				'id'   => $p->id,
				'name' => $p->fullName(),
				'hint' => $this->studentHint( $p ),
			),
			$this->persons->searchStudents( $query )
		);
	}

	/**
	 * Зачисления ученика, по которым печатается договор, с родителем каждого.
	 *
	 * Только действующие: выбор на странице появляется, лишь когда их несколько
	 * (ученик на двух направлениях — у каждого свой договор и своя программа).
	 * Действующих нет — последнее зачисление, чтобы можно было перепечатать
	 * договор завершившего обучение. Пробные доступы не в счёт: договора у них нет.
	 *
	 * @return array<int, array{id: int, label: string, active: bool, parent: array{id: int, name: string}|null}>
	 */
	public function studentRecords( int $studentId ): array {
		$records = array_values( array_filter(
			$this->records->findByStudent( $studentId ),
			static fn( StudentRecordDTO $r ): bool => ! $r->isTrial
		) );

		$active  = array_values( array_filter( $records, static fn( StudentRecordDTO $r ): bool => $r->isActive() ) );
		$records = $active ?: array_slice( $records, 0, 1 );

		$subjects = $this->subjects->readAll();
		$result   = array();

		foreach ( $records as $record ) {
			$group  = $this->groups->findById( $record->groupId );
			$parent = $record->parentPersonId ? $this->persons->find( $record->parentPersonId ) : null;

			$parts = array_filter( array(
				$group ? ( $subjects[ $group->subject_key ]->name ?? $group->subject_key ) : null,
				$group ? $group->name : null,
				$record->contractNo ? 'договор № ' . $record->contractNo : 'без номера договора',
				EnrollmentStatus::Active === $record->status ? null : $record->status->label(),
			) );

			$result[] = array(
				'id'     => $record->id,
				'label'  => implode( ' · ', $parts ),
				'active' => $record->isActive(),
				'parent' => $parent ? array( 'id' => $parent->id, 'name' => $parent->fullName() ) : null,
			);
		}

		return $result;
	}

	/**
	 * Программа и цена по каждому активному предмету — для таблицы на странице.
	 *
	 * @return array<int, array{key: string, name: string, program: string, price: string}>
	 */
	public function programs(): array {
		$rows = array();
		foreach ( $this->subjects->readActive() as $subject ) {
			$rows[] = array_merge(
				array( 'key' => $subject->key, 'name' => $subject->name ),
				$this->programs->get( $subject->key )
			);
		}

		return $rows;
	}

	/**
	 * Сохраняет программу и цену предмета.
	 *
	 * @throws \DomainException Предмет не найден.
	 */
	public function saveProgram( string $subjectKey, string $program, string $price ): void {
		if ( ! isset( $this->subjects->readAll()[ $subjectKey ] ) ) {
			throw new \DomainException( 'Предмет не найден.' );
		}

		$this->programs->save( $subjectKey, $program, $price );
	}

	/**
	 * Собирает документ и возвращает ссылку на скачивание.
	 *
	 * @param array{number?: string, year?: string, sum?: string} $input Данные со страницы
	 *        для форм с {@see PrintDocument::needsInput()} (справка на вычет)
	 *
	 * @return array{url: string, filename: string, empty: string[]} `empty` — подписи полей шаблона, оставшихся пустыми
	 *
	 * @throws \DomainException Нет шаблона, зачисления, родителя, данных формы или в шаблоне неизвестные поля.
	 */
	public function generate( PrintDocument $document, int $studentId, int $recordId, array $input = array() ): array {
		if ( ! $this->hasTemplate( $document ) ) {
			throw new \DomainException( "Шаблон формы «{$document->label()}» ещё не загружен." );
		}

		$student = $this->persons->find( $studentId );
		$record  = $this->records->find( $recordId );
		if ( null === $student || null === $record || $record->studentPersonId !== $studentId ) {
			throw new \DomainException( 'Зачисление ученика не найдено.' );
		}

		$parent = $record->parentPersonId ? $this->persons->find( $record->parentPersonId ) : null;
		if ( null === $parent ) {
			throw new \DomainException( 'У зачисления не указан родитель — документ не на кого оформить.' );
		}

		[ $content, $empty ] = $document->needsInput()
			? $this->buildPdfForm( $document, $student, $parent, $record, $input )
			: $this->buildDocx( $document, $student, $parent, $record );

		$filename = sprintf( '%s — %s — %s.%s', $document->filePrefix(), $student->shortName(), wp_date( 'd.m.Y' ), $document->extension() );
		$url      = $this->downloads->forContent( $content, $filename, $document->mimeType() );

		$this->exportLog->record( ExportTarget::PrintDocument->value, 'single', array( $student->id, $parent->id ) );
		$this->logEvents->dispatch(
			LogEvent::DocumentPrinted,
			new EnrollmentStatusEvent( get_current_user_id(), $document->auditAction(), $student->id, $record->id, $record->groupId )
		);

		return array( 'url' => $url, 'filename' => $filename, 'empty' => $empty );
	}

	/**
	 * DOCX по шаблону с полями `{{…}}`.
	 *
	 * @return array{0: string, 1: string[]} Содержимое и подписи пустых полей
	 */
	private function buildDocx( PrintDocument $document, PersonDTO $student, PersonDTO $parent, StudentRecordDTO $record ): array {
		$path   = $this->templatePath( $document );
		$keys   = $this->renderer->placeholders( $path );
		$fields = array_filter( array_map( static fn( string $k ): ?PrintField => PrintField::tryFrom( $k ), $keys ) );

		$unknown = array_diff( $keys, array_map( static fn( PrintField $f ): string => $f->value, $fields ) );
		if ( $unknown ) {
			throw new \DomainException( 'В шаблоне неизвестные поля: {{' . implode( '}}, {{', $unknown ) . '}}.' );
		}

		$values = $this->collector->collect( $fields, $student, $parent, $record );

		$empty = array();
		foreach ( $fields as $field ) {
			if ( '' === trim( $values[ $field->value ] ?? '' ) ) {
				$empty[] = $field->group() . ': ' . mb_strtolower( $field->label() );
			}
		}

		return array( $this->renderer->render( $path, $values ), $empty );
	}

	/**
	 * PDF-форма (справка на вычет): поля остаются редактируемыми.
	 *
	 * @param array{number?: string, year?: string, sum?: string} $input
	 *
	 * @return array{0: string, 1: string[]}
	 */
	private function buildPdfForm( PrintDocument $document, PersonDTO $student, PersonDTO $parent, StudentRecordDTO $record, array $input ): array {
		$number = trim( (string) ( $input['number'] ?? '' ) );
		$year   = trim( (string) ( $input['year'] ?? '' ) );
		$sum    = trim( (string) ( $input['sum'] ?? '' ) );

		if ( ! preg_match( '~^\d{1,12}$~', $number ) ) {
			throw new \DomainException( 'Укажите номер справки — до 12 цифр.' );
		}
		if ( ! preg_match( '~^\d{4}$~', $year ) || (int) $year < 2000 || (int) $year > (int) wp_date( 'Y' ) ) {
			throw new \DomainException( 'Укажите отчётный год.' );
		}
		if ( ! preg_match( '~^\d[\d\s]*([.,]\d{1,2})?$~u', $sum ) || (float) str_replace( array( ' ', ',' ), array( '', '.' ), $sum ) <= 0 ) {
			throw new \DomainException( 'Укажите сумму расходов, например 120000 или 120000,50.' );
		}

		$form = $this->taxForm->build( $student, $parent, $record, array( 'number' => $number, 'year' => $year, 'sum' => $sum ) );

		try {
			$content = $this->pdfFiller->fill( $this->templatePath( $document ), $form['values'] );
		} catch ( \RuntimeException $e ) {
			throw new \DomainException( $e->getMessage(), 0, $e );
		}

		return array( $content, $form['empty'] );
	}

	/**
	 * Подсказка под ФИО в списке: дата рождения и класс — различить тёзок.
	 */
	private function studentHint( PersonDTO $p ): string {
		$parts = array();
		if ( $p->birthDate ) {
			$parts[] = wp_date( 'd.m.Y', strtotime( $p->birthDate . ' 12:00:00' ) );
		}
		if ( $p->grade ) {
			$parts[] = $p->grade . ' класс';
		}

		return implode( ', ', $parts );
	}
}
