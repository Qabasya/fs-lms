<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\SubjectDTO;
use Inc\Enums\Nonce;
use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Repositories\BoilerplateRepository;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\TaxonomyRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TaxonomySeeder;
use Inc\Services\PostTypeResolver;

class SubjectCrudCallbacks extends BaseController {
	use Authorizer;
	use Sanitizer;
	use TaxonomySeeder;

	public function __construct(
		private readonly SubjectRepository   $subjects,
		private readonly TaxonomyRepository  $taxonomies,
		private readonly MetaBoxRepository   $metaboxes,
		private readonly BoilerplateRepository $boilerplates,
		private readonly TermManager         $terms,
		private readonly PostManager         $posts,
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
		$this->cascadeDelete( $key );

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

	private function cascadeDelete( string $key ): void {
		foreach ( $this->taxonomies->getBySubject( $key ) as $tax_dto ) {
			$this->terms->deleteAll( $tax_dto->slug );
		}

		$this->terms->deleteAll( "{$key}_task_number" );

		foreach ( array( PostTypeResolver::tasks( $key ), PostTypeResolver::articles( $key ) ) as $post_type ) {
			$this->posts->deleteAll( $post_type );
		}

		$this->taxonomies->removeBySubject( $key );
		$this->metaboxes->removeBySubject( $key );
		$this->boilerplates->removeBySubject( $key );
	}
}
