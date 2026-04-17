<?php

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Core\PluginConfig;
use Inc\DTO\SubjectDTO;
use Inc\Enums\OptionName;

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
 * При чтении данных возвращает DTO-объекты (SubjectDTO) для типобезопасной работы.
 *
 * @package Inc\Repositories
 * @implements RepositoryInterface
 */
class SubjectRepository implements RepositoryInterface {
	/**
	 * Имя опции WordPress для хранения предметов.
	 *
	 * @var string
	 */
	private string $option_name = OptionName::SUBJECTS->value;

	/**
	 * Внутренний метод для получения сырых данных из Options API.
	 *
	 * @return array<string, array{key: string, name: string}> Сырые данные предметов
	 */
	private function getRaw(): array {
		$subjects = get_option( $this->option_name, array() );

		// Гарантируем возврат массива даже при повреждённых данных
		return is_array( $subjects ) ? $subjects : array();
	}

	/**
	 * Получить все предметы.
	 *
	 * Возвращает массив DTO-объектов для типобезопасной работы.
	 *
	 * @return SubjectDTO[] Массив DTO-объектов предметов
	 */
	public function readAll(): array {
		return array_map(
			function ( $item ) {
				return new SubjectDTO( $item['key'], $item['name'] );
			},
			$this->getRaw()
		);
	}

	/**
	 * Получить предмет по ключу.
	 *
	 * @param string $key Уникальный идентификатор предмета (slug)
	 *
	 * @return SubjectDTO|null DTO-объект предмета или null, если не найден
	 */
	public function getByKey( string $key ): ?SubjectDTO {
		$raw = $this->getRaw();

		if ( ! isset( $raw[ $key ] ) ) {
			return null;
		}

		return new SubjectDTO( $raw[ $key ]['key'], $raw[ $key ]['name'] );
	}

	/**
	 * Сохранить или обновить предмет (upsert).
	 *
	 * Метод работает как update + insert: если предмет с таким ключом существует —
	 * обновляет, если нет — создаёт новый.
	 *
	 * @param array{key: string, name: string} $data Данные предмета
	 *
	 * @return bool Успешность сохранения
	 */
	public function update( array $data ): bool {
		// Работаем с сырыми данными для сохранения
		$subjects = $this->getRaw();

		// Очищаем входные данные
		$clean = $this->sanitize( $data );

		// Сохраняем предмет в массиве
		$subjects[ $clean['key'] ] = array(
			'key'  => $clean['key'],
			'name' => $clean['name'],
		);

		// Сохраняем обновлённый массив в опции WordPress
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
		return array(
			'key'  => isset( $data['key'] ) ? sanitize_title( $data['key'] ) : '',
			'name' => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
		);
	}

	/**
	 * Удалить предмет по ключу.
	 *
	 * @param array{key: string} $data Данные с ключом предмета
	 *
	 * @return bool Успешность удаления (false, если предмет не найден)
	 */
	public function delete( array $data ): bool {
		$key      = $data['key'] ?? '';
		$subjects = $this->getRaw();

		// Проверяем существование предмета
		if ( ! isset( $subjects[ $key ] ) ) {
			return false;
		}

		// Удаляем предмет из массива
		unset( $subjects[ $key ] );

		// Сохраняем обновлённый массив в опции WordPress
		return update_option( $this->option_name, $subjects );
	}

	/**
	 * Полностью очистить все предметы.
	 *
	 * Удаляет опцию из базы данных целиком.
	 *
	 * @return bool Успешность операции
	 */
	public function clear(): bool {
		return delete_option( $this->option_name );
	}
}
