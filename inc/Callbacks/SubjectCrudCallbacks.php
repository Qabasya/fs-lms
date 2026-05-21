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
 * Делегирует операции с БД SubjectRepository, а каскадное удаление — SubjectDeletionService.
 */
class SubjectCrudCallbacks extends BaseController {
	use Authorizer;      // Трейт с методами authorize(), respond(), error()
	use Sanitizer;       // Трейт с методами sanitizeInt(), requireKey(), requireText()
	use TaxonomySeeder;  // Трейт с методом seedTaskNumbers() для генерации терминов

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository      $subjects         Репозиторий предметов
	 * @param SubjectDeletionService $deletion_service Сервис каскадного удаления
	 */
	public function __construct(
		private readonly SubjectRepository      $subjects,
		private readonly SubjectDeletionService $deletion_service,
	) {
		parent::__construct();
	}

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

		// Сохранение предмета через репозиторий
		$result = $this->subjects->save( new SubjectDTO( $key, $name ) );

		// seedTaskNumbers() — создаёт термины таксономии (1, 2, 3... $count)
		if ( $result ) {
			$this->seedTaskNumbers( "{$key}_task_number", $count, $key );
			// flush_rewrite_rules() — перестраивает правила ЧПУ после регистрации новых CPT/таксономий
			flush_rewrite_rules();
		}

		// Отправка ответа
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

		$result = $this->subjects->save( new SubjectDTO( $key, $name ) );

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

		// Каскадное удаление всех связанных данных (таксономии, термины, посты, boilerplate)
		$this->deletion_service->deleteWithCascade( $key );

		// Удаление самого предмета
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

	/**
	 * Проверяет существование предмета в БД.
	 * При отсутствии отправляет JSON-ошибку и завершает выполнение.
	 *
	 * @param string $key Ключ предмета
	 *
	 * @return void
	 */
	private function requireExists( string $key ): void {
		if ( ! $this->subjects->getByKey( $key ) ) {
			$this->error( 'Предмет не найден в базе данных' );
		}
	}
}