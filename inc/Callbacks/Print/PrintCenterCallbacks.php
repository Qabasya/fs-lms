<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Print;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Print\PrintDocument;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Print\PrintCenterService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class PrintCenterCallbacks
 *
 * AJAX «Центра печати»: поиск ученика, его зачисления с родителем, сборка документа,
 * программы и цены предметов для договора.
 *
 * Поиск и список зачислений отдают только ФИО — хватает доступа к разделу.
 * Документ содержит ПДн родителя (паспорт, адрес, ИНН), поэтому его сборка —
 * выгрузка ПД и требует обоих прав: `ManageLmsPlatform` + `ExportPII`.
 *
 * @package Inc\Callbacks\Print
 */
class PrintCenterCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly PrintCenterService $printCenter,
	) {
		parent::__construct();
	}

	/**
	 * Поиск учеников по ФИО. Params: query.
	 */
	public function ajaxSearchPrintStudents(): void {
		$this->authorize( Nonce::Manager, Capability::ManageLmsPlatform );

		$this->success( $this->printCenter->searchStudents( $this->sanitizeText( 'query' ) ) );
	}

	/**
	 * Зачисления ученика с родителем каждого. Params: student_id.
	 */
	public function ajaxGetPrintStudentRecords(): void {
		$this->authorize( Nonce::Manager, Capability::ManageLmsPlatform );

		$studentId = $this->requireInt( 'student_id', error: 'Не выбран ученик.' );

		$this->success( $this->printCenter->studentRecords( $studentId ) );
	}

	/**
	 * Сборка документа. Params: document, student_id, record_id.
	 */
	public function ajaxGeneratePrintDocument(): void {
		$this->authorizeAll( Nonce::Manager, array( Capability::ManageLmsPlatform, Capability::ExportPII ) );

		$document = PrintDocument::tryFrom( $this->sanitizeKey( 'document' ) );
		if ( null === $document ) {
			$this->error( 'Неизвестная форма документа.' );
			return;
		}

		$studentId = $this->requireInt( 'student_id', error: 'Не выбран ученик.' );
		$recordId  = $this->requireInt( 'record_id', error: 'Не выбрано зачисление.' );

		try {
			$this->success( $this->printCenter->generate( $document, $studentId, $recordId ) );
		} catch ( \DomainException | \RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	/**
	 * Программа и цена предмета для договора. Params: subject_key, program, price.
	 */
	public function ajaxSavePrintProgram(): void {
		$this->authorize( Nonce::Manager, Capability::ManageLmsPlatform );

		$subjectKey = $this->requireKey( 'subject_key', error: 'Не указан предмет.' );

		try {
			$this->printCenter->saveProgram( $subjectKey, $this->sanitizeText( 'program' ), $this->sanitizeText( 'price' ) );
		} catch ( \DomainException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success( array( 'subject_key' => $subjectKey ) );
	}
}
