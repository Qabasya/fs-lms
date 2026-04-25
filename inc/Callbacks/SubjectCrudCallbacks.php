<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
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

class SubjectCrudCallbacks extends BaseController {
	use Authorizer;
	use Sanitizer;
	use TaxonomySeeder;
	
	public function __construct(
		private SubjectRepository $subjects,
		private TaxonomyRepository $taxonomies,
		private MetaBoxRepository $metaboxes,
		private BoilerplateRepository $boilerplates,
		private TermManager $terms,
		private PostManager $posts,
	) {
		parent::__construct();
	}
	
	// ============================ AJAX-КОЛЛБЕКИ (CRUD) ============================ //
	
	/**
	 * Создаёт новый предмет и засевает таксономию номеров заданий.
	 *
	 * @return void
	 */
	public function ajaxStoreSubject(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );
		
		$key  = $this->requireKey( 'key', error: 'ID предмета обязателен' );
		$name = $this->requireText( 'name', error: 'Название предмета обязательно' );
		
		// Получение количества заданий (по умолчанию 0)
		$count = $this->sanitizeInt( 'tasks_count' );
		
		// Сохранение предмета через репозиторий
		$success = $this->subjects->update( [
			'key'  => $key,
			'name' => $name,
		] );
		
		if ( ! $success ) {
			wp_send_json_error( 'Ошибка при создании предмета' );
			
			return;
		}
		
		// Засев таксономии номерами заданий
		$this->seedTaskNumbers( "{$key}_task_number", $count, $key );
		
		// Сброс правил перезаписи для активации новых CPT
		flush_rewrite_rules();
		
		wp_send_json_success( "Предмет «{$name}» успешно создан!" );
	}
	
	/**
	 * Обновляет название существующего предмета.
	 *
	 * @return void
	 */
	public function ajaxUpdateSubject(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );
		
		$key  = $this->requireKey( 'key', error: 'ID предмета обязателен' );
		$name = $this->requireText( 'name', error: 'Название предмета обязательно' );
		
		// Проверка существования предмета
		$this->requireExists( $key );
		
		// Обновление предмета через репозиторий
		$success = $this->subjects->update( [
			'key'  => $key,
			'name' => $name,
		] );
		
		if ( ! $success ) {
			wp_send_json_error( 'Ошибка при обновлении предмета' );
			
			return;
		}
		
		wp_send_json_success( "Предмет «{$name}» обновлён" );
	}
	
	/**
	 * Удаляет предмет из базы данных каскадно (все связанные данные).
	 *
	 * @return void
	 */
	public function ajaxDeleteSubject(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );
		
		$key = $this->requireKey( 'key', error: 'ID предмета обязателен' );
		
		// Проверка существования предмета
		$this->requireExists( $key );
		
		// Каскадное удаление всех связанных данных
		$this->cascadeDelete( $key );
		
		// Удаление предмета через репозиторий
		$success = $this->subjects->delete( [ 'key' => $key ] );
		
		if ( ! $success ) {
			wp_send_json_error( 'Ошибка при удалении предмета' );
			
			return;
		}
		
		// Сброс правил перезаписи после удаления CPT
		flush_rewrite_rules();
		
		wp_send_json_success( 'Предмет удалён' );
	}
	
	// ============================ ПРИВАТНЫЕ МЕТОДЫ-ХЕЛПЕРЫ ============================ //
	
	/**
	 * Проверяет существование предмета в БД.
	 * Завершает выполнение, если предмет не найден.
	 *
	 * @param string $key Ключ предмета
	 *
	 * @return void
	 */
	private function requireExists( string $key ): void {
		if ( ! $this->subjects->getByKey( $key ) ) {
			wp_send_json_error( 'Предмет не найден в базе данных' );
		}
	}
	
	/**
	 * Каскадное удаление всех данных, связанных с предметом.
	 *
	 * @param string $key Ключ предмета
	 *
	 * @return void
	 */
	private function cascadeDelete(string $key): void
	{
		// Удаление терминов пользовательских таксономий
		foreach ($this->taxonomies->getBySubject($key) as $tax_dto) {
			$this->terms->deleteAll($tax_dto->slug);
		}
		
		// Удаление терминов системной таксономии номеров заданий
		$this->terms->deleteAll("{$key}_task_number");
		
		// Удаление всех постов заданий и статей
		foreach (["{$key}_tasks", "{$key}_articles"] as $post_type) {
			$this->posts->deleteAll($post_type);
		}
		
		// Удаление записей из репозиториев
		$this->taxonomies->deleteBySubject($key);
		$this->metaboxes->deleteBySubject($key);
		$this->boilerplates->deleteBySubject($key);
	}
	
}