<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Repositories\SubjectRepository;
use Inc\Services\SubjectExportService;
use Inc\Services\SubjectImportService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class SubjectImportExportCallbacks extends BaseController {
	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly SubjectRepository   $subjects,
		private readonly SubjectExportService $export_service,
		private readonly SubjectImportService $import_service,
	) {
		parent::__construct();
	}

	public function ajaxExportSubject(): void {
		$this->authorize( Nonce::Subject );

		$key     = $this->requireKey( 'key', error: 'ID предмета обязателен' );
		$subject = $this->subjects->getByKey( $key );

		if ( ! $subject ) {
			$this->error( 'Предмет не найден', array( 'key' => $key ) );
		}

		$this->success( array_merge(
			array( 'subject' => array( 'key' => $subject->key, 'name' => $subject->name ) ),
			$this->export_service->export( $key )
		) );
	}

	public function ajaxImportSubject(): void {
		$this->authorize( Nonce::Subject );

		$raw = $this->sanitizeHtml( 'json' );
		if ( empty( $raw ) ) {
			$this->error( 'JSON не передан' );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$this->error( 'Неверный формат файла импорта', array( 'raw_length' => strlen( $raw ) ) );
		}

		try {
			$name = $this->import_service->import( $data );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}

		flush_rewrite_rules();

		$this->success( array( 'message' => "Предмет «{$name}» успешно импортирован" ) );
	}
}
