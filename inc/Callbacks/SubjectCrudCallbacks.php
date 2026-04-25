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

/**
 * Class SubjectCrudCallbacks
 *
 * AJAX-обработчики для CRUD-операций с предметами.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Создание предмета** — сохранение нового предмета и генерация таксономии номеров заданий.
 * 2. **Обновление предмета** — изменение названия существующего предмета.
 * 3. **Удаление предмета** — каскадное удаление предмета и всех связанных данных.
 *
 * ### Архитектурная роль:
 *
 * Делегирует операции с БД репозиториям, а массовые операции — менеджерам (TermManager, PostManager).
 */
class SubjectCrudCallbacks extends BaseController {
	use Authorizer;
	use Sanitizer;
	use TaxonomySeeder;  // Трейт с методом seedTaskNumbers() для генерации терминов
	
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly TaxonomyRepository $taxonomies,
		private readonly MetaBoxRepository $metaboxes,
		private readonly BoilerplateRepository $boilerplates,
		private readonly TermManager $terms,
		private readonly PostManager $posts,
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
		
		// Валидация входных данных
		$key   = $this->requireKey( 'key', error: 'ID предмета обязателен' );
		$name  = $this->requireText( 'name', error: 'Название предмета обязательно' );
		$count = $this->sanitizeInt( 'tasks_count' );
		
		// Сохранение предмета в БД
		$result = $this->subjects->update(
			array(
				'key'  => $key,
				'name' => $name,
			)
		);
		
		// seedTaskNumbers() — метод трейта TaxonomySeeder
		// Создаёт термины таксономии (1, 2, 3... $count) для номеров заданий
		if ( $result ) {
			$this->seedTaskNumbers( "{$key}_task_number", $count, $key );
			// flush_rewrite_rules() — сбрасывает и пересобирает правила ЧПУ в WordPress
			// Необходимо после регистрации новых таксономий/CPT
			flush_rewrite_rules();
		}
		
		// Отправка JSON-ответа
		$this->respond(
			$result,
			error_msg: 'Ошибка при создании предмета',
			success_msg: "Предмет «{$name}» успешно создан!"
		);
	}
	
	/**
	 * Обновляет название существующего предмета.
	 *
	 * @return void
	 */
	public function ajaxUpdateSubject(): void {
		$this->authorize( Nonce::Subject );
		
		$key  = $this->requireKey( 'key', error: 'ID предмета обязателен' );
		$name = $this->requireText( 'name', error: 'Название предмета обязательно' );
		
		// Проверка, что предмет существует
		$this->requireExists( $key );
		
		$result = $this->subjects->update(
			array(
				'key'  => $key,
				'name' => $name,
			)
		);
		
		$this->respond(
			$result,
			error_msg: 'Ошибка при обновлении предмета',
			success_msg: "Предмет «{$name}» обновлён"
		);
	}
	
	/**
	 * Удаляет предмет из базы данных каскадно (все связанные данные).
	 *
	 * @return void
	 */
	public function ajaxDeleteSubject(): void {
		$this->authorize( Nonce::Subject );
		
		$key = $this->requireKey( 'key', error: 'ID предмета обязателен' );
		
		$this->requireExists( $key );
		
		// Каскадное удаление связанных данных
		$this->cascadeDelete( $key );
		
		// Удаление самого предмета
		$result = $this->subjects->delete( array( 'key' => $key ) );
		
		if ( $result ) {
			flush_rewrite_rules();
		}
		
		$this->respond(
			$result,
			error_msg: 'Ошибка при удалении предмета',
			success_msg: "Предмет удалён"
		);
	}
	
	// ============================ ПРИВАТНЫЕ МЕТОДЫ-ХЕЛПЕРЫ ============================ //
	
	/**
	 * Проверяет существование предмета в БД.
	 *
	 * @param string $key Ключ предмета
	 *
	 * @return void
	 */
	private function requireExists( string $key ): void {
		// getByKey() — возвращает объект предмета или null
		if ( ! $this->subjects->getByKey( $key ) ) {
			// wp_send_json_error() — отправляет JSON-ответ с ошибкой и завершает выполнение
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
	private function cascadeDelete( string $key ): void {
		// Удаление терминов всех пользовательских таксономий этого предмета
		foreach ( $this->taxonomies->getBySubject( $key ) as $tax_dto ) {
			// deleteAll() — удаляет все термины указанной таксономии
			$this->terms->deleteAll( $tax_dto->slug );
		}
		
		// Удаление системной таксономии номеров заданий
		$this->terms->deleteAll( "{$key}_task_number" );
		
		// Удаление всех постов (заданий и статей)
		foreach ( array( "{$key}_tasks", "{$key}_articles" ) as $post_type ) {
			$this->posts->deleteAll( $post_type );
		}
		
		// Очистка записей в таблицах связей
		$this->taxonomies->deleteBySubject( $key );
		$this->metaboxes->deleteBySubject( $key );
		$this->boilerplates->deleteBySubject( $key );
	}
}