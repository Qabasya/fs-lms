<?php
/**
 * AJAX списка учеников, не записавшихся на проведение экзамена: просмотр, CSV и печать.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Export\ExportTarget;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Exam\ExamUnregisteredService;
use Inc\Services\Export\ExportService;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX списка учеников, не записавшихся на проведение экзамена: просмотр, CSV и печать.
 *
 * Просмотр — `ManageExams`, как и доска. Выгрузка и печать — ПД: обе проверки, раздел и право на выгрузку.
 * Доступ к проведению проверяет сервис; `person_ids` — выбор фильтрами экрана, сервис оставляет из него только незаписавшихся.
 */
class ExamUnregisteredCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	/** Получает список незаписавшихся и экспорт раздельно. */
	public function __construct(
		private readonly ExamUnregisteredService $unregistered,
		private readonly ExportService $exports,
	) {
		parent::__construct();
	}

	/** Незаписавшиеся ученики проведения выбранного сеанса. */
	public function ajaxGetExamUnregistered(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$sessionId = $this->requireInt( 'session_id' );

		$this->run( fn (): array => array( 'rows' => $this->unregistered->rows( get_current_user_id(), $sessionId ) ) );
	}

	/** CSV незаписавшихся: все или выбранные фильтрами. */
	public function ajaxExportExamUnregistered(): void {
		$this->authorizeAll( Nonce::ExamManage, array( Capability::ManageLmsPlatform, Capability::ExportPII ) );

		$sessionId = $this->requireInt( 'session_id' );
		$personIds = array_values( array_filter( $this->sanitizeIntList( 'person_ids' ) ) );

		$this->run(
			fn (): array => array(
				'url' => $this->exports->run(
					ExportTarget::ExamUnregistered,
					array(
						'session_id'    => $sessionId,
						'person_ids'    => $personIds,
						'actor_user_id' => get_current_user_id(),
					),
					'bulk'
				),
			)
		);
	}

	/** Список для печати: ФИО и группа, без контактов. */
	public function ajaxGetExamUnregisteredPrintList(): void {
		$this->authorizeAll( Nonce::ExamManage, array( Capability::ManageLmsPlatform, Capability::ExportPII ) );

		$sessionId = $this->requireInt( 'session_id' );
		$personIds = array_values( array_filter( $this->sanitizeIntList( 'person_ids' ) ) );

		$this->run(
			fn (): array => array(
				'rows' => array_map(
					static fn ( array $r ): array => array(
						'name'    => $r['name'],
						'source'  => $r['source'],
						'session' => $r['status'],
					),
					$this->unregistered->exportRows( get_current_user_id(), $sessionId, $personIds )
				),
			)
		);
	}

	/** Отдаёт результат действия; отказ сервиса превращается в ответ с кодом инцидента. */
	private function run( callable $action ): void {
		try {
			$this->success( $action() );
		} catch ( CodedException $e ) {
			$this->fail( $e->errorCode, $e->getMessage() );
		}
	}
}
