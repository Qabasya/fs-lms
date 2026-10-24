<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Task;

use Inc\Core\BaseController;
use Inc\DTO\Task\TaskBulkPlanItemDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Person\UserManager;
use Inc\Services\Task\TaskBulkCsvService;
use Inc\Services\Task\TaskBulkEditService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class TaskBulkEditCallbacks
 *
 * AJAX массовой правки заданий через CSV (Предметы → Менеджер заданий).
 * Только транспорт — логика в {@see TaskBulkEditService}, формат файла в {@see TaskBulkCsvService}.
 *
 * @package Inc\Callbacks\Task
 */
class TaskBulkEditCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly TaskBulkCsvService $csv,
		private readonly TaskBulkEditService $service,
		private readonly UserManager $users,
	) {
		parent::__construct();
	}

	/** Шаблон CSV с текущими значениями заданий предмета (всех или одного номера задания: `task_number` — слаг терма). */
	public function ajaxTaskBulkTemplate(): void {
		$this->authorize( Nonce::Manager, Capability::ManageSubjects );

		$subjectKey = $this->requireKey( 'subject_key', error: 'Не указан предмет.' );
		$taskNumber = $this->sanitizeKey( 'task_number' );

		$this->success(
			array(
				'filename' => 'tasks-' . $subjectKey . ( '' !== $taskNumber ? "-{$taskNumber}" : '' ) . '-template.csv',
				'csv'      => $this->csv->buildTemplate( $subjectKey, $taskNumber ),
			)
		);
	}

	/** Предпросмотр: проверка файла без записи. */
	public function ajaxTaskBulkPreview(): void {
		$this->authorize( Nonce::Manager, Capability::ManageSubjects );

		[ $subjectKey, $items ] = $this->planFromRequest();

		$this->success( $this->summarize( $items ) + array( 'subject_key' => $subjectKey ) );
	}

	/** Применение: правятся строки без ошибок, снимок партии сохраняется (по нему откат и выгрузка). */
	public function ajaxTaskBulkApply(): void {
		$this->authorize( Nonce::Manager, Capability::ManageSubjects );

		[ $subjectKey, $items ] = $this->planFromRequest();

		try {
			$result = $this->service->apply( $subjectKey, $items, get_current_user_id() );
		} catch ( \Throwable $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success( $this->summarize( $items ) + $result );
	}

	/** Снимки предмета: номер, дата, автор, число заданий, статус. */
	public function ajaxTaskBulkSnapshots(): void {
		$this->authorize( Nonce::Manager, Capability::ManageSubjects );

		$subjectKey = $this->requireKey( 'subject_key', error: 'Не указан предмет.' );

		$list = array_map(
			fn( array $row ): array => $row + array( 'author' => $this->users->find( $row['actor_user_id'] )?->display_name ?? '—' ),
			$this->service->snapshotList( $subjectKey )
		);

		$this->success( array( 'snapshots' => $list ) );
	}

	/** Снимок в CSV: старый номер → новые данные. */
	public function ajaxTaskBulkSnapshotCsv(): void {
		$this->authorize( Nonce::Manager, Capability::ManageSubjects );

		$id       = $this->requireInt( 'snapshot_id', error: 'Не указан снимок.' );
		$snapshot = $this->service->snapshot( $id );
		if ( null === $snapshot ) {
			$this->error( "Снимок №{$id} не найден." );
			return;
		}

		$this->success(
			array(
				'filename' => "tasks-snapshot-{$id}.csv",
				'csv'      => $this->csv->buildSnapshotCsv( $snapshot ),
			)
		);
	}

	/** Откат заданий к состоянию снимка. */
	public function ajaxTaskBulkRollback(): void {
		$this->authorize( Nonce::Manager, Capability::ManageSubjects );

		$id = $this->requireInt( 'snapshot_id', error: 'Не указан снимок.' );

		try {
			$result = $this->service->rollback( $id, get_current_user_id() );
		} catch ( \Throwable $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success( $result );
	}

	/**
	 * Разбирает файл запроса и строит план.
	 *
	 * @return array{0: string, 1: TaskBulkPlanItemDTO[]}
	 */
	private function planFromRequest(): array {
		$subjectKey = $this->requireKey( 'subject_key', error: 'Не указан предмет.' );
		$csv        = $this->unslashRawString( 'csv' );

		try {
			$rows  = $this->csv->parse( $csv, $subjectKey );
			$items = $this->service->plan( $subjectKey, $rows );
		} catch ( \Throwable $e ) {
			$this->error( $e->getMessage() );
			return array( $subjectKey, array() );
		}

		return array( $subjectKey, $items );
	}

	/**
	 * @param TaskBulkPlanItemDTO[] $items
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, changes: int, errors: int, unchanged: int}
	 */
	private function summarize( array $items ): array {
		$reports = array_map( static fn( TaskBulkPlanItemDTO $i ): array => $i->toReport(), $items );

		return array(
			'items'     => $reports,
			'total'     => count( $items ),
			'changes'   => count( array_filter( $reports, static fn( array $r ): bool => 'ok' === $r['status'] ) ),
			'errors'    => count( array_filter( $reports, static fn( array $r ): bool => 'error' === $r['status'] ) ),
			'unchanged' => count( array_filter( $reports, static fn( array $r ): bool => 'skip' === $r['status'] ) ),
		);
	}
}
