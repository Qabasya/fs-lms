<?php

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Core\BaseController;

/**
 * Class SubjectRepository
 *
 * Репозиторий для управления учебными предметами.
 *
 * Реализует хранение данных через WordPress Options API.
 * Каждый предмет имеет уникальный ключ (slug) и название.
 *
 * Структура хранения:
 * [
 *     'subject_key' => ['key' => 'subject_key', 'name' => 'Название'],
 *     'another_key' => ['key' => 'another_key', 'name' => 'Другое название'],
 * ]
 *
 * @package Inc\Repositories
 * @extends RepositoryInterface
 */
class SubjectRepository extends BaseController implements RepositoryInterface {
	/**
	 * Имя опции WordPress для хранения предметов.
	 *
	 * @var string
	 */
	private string $option_name = BaseController::SUBJECTS_OPTION_NAME;

	/**
	 * Получить все предметы.
	 *
	 * Возвращает ассоциативный массив, где ключ — уникальный
	 * идентификатор предмета, значение — массив с ключом и названием.
	 *
	 * @return array<string, array{key: string, name: string}> Массив предметов
	 */
	public function read_all(): array {
		$subjects = get_option( $this->option_name, array() );

		// Если база упала и get_option вернул пустую строку, а не массив
		if ( ! is_array( $subjects ) ) {
			return array();
		}

		return $subjects;
	}

	/**
	 * Получить предмет по ключу.
	 */
	public function get_by_key( string $key ): ?array {
		$subjects = $this->read_all();

		return $subjects[ $key ] ?? null;
	}

	/**
	 * Сохранить или обновить предмет.
	 * Вообще тут лучше назвать upsert update+insert из-за update_option
	 *
	 * @param array{key: string, name: string} $data Данные предмета
	 *
	 * @return bool Успешность сохранения
	 */
	public function update( array $data ): bool {
		$subjects = $this->read_all();

		$clean_data = $this->sanitize( $data );

		$subjects[ $clean_data['key'] ] = [
			'key'  => $clean_data['key'],
			'name' => $clean_data['name']
		];

		return update_option( $this->option_name, $subjects );
	}

	/**
	 * Очистить данные предмета перед сохранением.
	 *
	 * Применяет безопасную обработку:
	 * - key: преобразует в slug (безопасный для URL)
	 * - name: удаляет HTML-теги и экранирует специальные символы
	 *
	 * @param array{key?: string, name?: string} $data Исходные данные
	 *
	 * @return array{key: string, name: string} Очищенные данные
	 */
	protected function sanitize( array $data ): array {
		return [
			'key'  => isset( $data['key'] ) ? sanitize_title( $data['key'] ) : '',
			'name' => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
		];
	}

	/**
	 * Удалить предмет по ключу.
	 *
	 * @param string $key Уникальный идентификатор предмета
	 *
	 * @return bool Успешность удаления (false, если предмет не найден)
	 */
	public function delete( array $data ): bool {
		$key      = $data['key'] ?? '';
		$subjects = $this->read_all();

		if ( ! isset( $subjects[ $key ] ) ) {
			return false;
		}

		unset( $subjects[ $key ] );

		return update_option( $this->option_name, $subjects );
	}

	public function clear(): bool {
		return delete_option( $this->option_name );
	}
}