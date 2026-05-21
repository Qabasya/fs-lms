<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\SubjectDTO;
use Inc\Enums\Nonce;
use Inc\Repositories\SubjectRepository;
use Inc\Services\SubjectDeletionService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TaxonomySeeder;

class SubjectCrudCallbacks extends BaseController {
	use Authorizer;
	use Sanitizer;
	use TaxonomySeeder;

	public function __construct(
		private readonly SubjectRepository      $subjects,
		private readonly SubjectDeletionService $deletion_service,
	) {
		parent::__construct();
	}

	public function ajaxStoreSubject(): void {
		$this->authorize( Nonce::Subject );

		$key   = $this->requireKey( 'key', error: 'ID предмета обязателен' );
		$name  = $this->requireText( 'name', error: 'Название предмета обязательно' );
		$count = $this->sanitizeInt( 'tasks_count' );

		$result = $this->subjects->save( new SubjectDTO( $key, $name ) );

		if ( $result ) {
			$this->seedTaskNumbers( "{$key}_task_number", $count, $key );
			flush_rewrite_rules();
		}

		$this->respond(
			$result,
			error_msg: 'Ошибка при создании предмета',
			success_msg: "Предмет «{$name}» успешно создан!"
		);
	}

	public function ajaxUpdateSubject(): void {
		$this->authorize( Nonce::Subject );

		$key  = $this->requireKey( 'key', error: 'ID предмета обязателен' );
		$name = $this->requireText( 'name', error: 'Название предмета обязательно' );

		$this->requireExists( $key );

		$result = $this->subjects->save( new SubjectDTO( $key, $name ) );

		$this->respond(
			$result,
			error_msg: 'Ошибка при обновлении предмета',
			success_msg: "Предмет «{$name}» обновлён"
		);
	}

	public function ajaxDeleteSubject(): void {
		$this->authorize( Nonce::Subject );

		$key = $this->requireKey( 'key', error: 'ID предмета обязателен' );

		$this->requireExists( $key );
		$this->deletion_service->deleteWithCascade( $key );

		$result = $this->subjects->remove( $key );

		if ( $result ) {
			flush_rewrite_rules();
		}

		$this->respond(
			$result,
			error_msg: 'Ошибка при удалении предмета',
			success_msg: 'Предмет удалён'
		);
	}

	private function requireExists( string $key ): void {
		if ( ! $this->subjects->getByKey( $key ) ) {
			wp_send_json_error( 'Предмет не найден в базе данных' );
		}
	}
}
