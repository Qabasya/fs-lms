<?php

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Core\BaseController;
use Inc\DTO\SubjectDTO;

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
	 * ВНУТРЕННИЙ метод. Работает только с сырыми массивами из базы.
	 */
	private function get_raw(): array {
		$subjects = get_option( $this->option_name, [] );
		return is_array( $subjects ) ? $subjects : [];
	}

	/**
	 * Получить все предметы.
	 *
	 * Возвращает ассоциативный массив, где ключ — уникальный
	 * идентификатор предмета, значение — массив с ключом и названием.
	 *
	 * @return array<string, array{key: string, name: string}> Массив предметов
	 */
	public function read_all(): array {
		return array_map( function( $item ) {
			return new SubjectDTO( $item['key'], $item['name'] );
		}, $this->get_raw() );
	}

	/**
	 * Получить предмет по ключу.
	 */
	public function get_by_key( string $key ): ?SubjectDTO {
		$raw = $this->get_raw();
		if ( ! isset( $raw[ $key ] ) ) {
			return null;
		}

		return new SubjectDTO( $raw[ $key ]['key'], $raw[ $key ]['name'] );
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
		// Работаем с сырыми данными для сохранения
		$subjects = $this->get_raw();
		$clean    = $this->sanitize( $data );

		$subjects[ $clean['key'] ] = [
			'key'  => $clean['key'],
			'name' => $clean['name']
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
		$subjects = $this->get_raw();

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