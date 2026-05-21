<?php

declare(strict_types=1);

namespace Inc\Repositories;

use Inc\DTO\SubjectDTO;
use Inc\Enums\OptionName;

/**
 * Class SubjectRepository
 *
 * Репозиторий для работы с предметами.
 *
 * @package Inc\Repositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — создание, чтение, обновление и удаление предметов.
 * 2. **Преобразование в DTO** — работа с типобезопасными объектами SubjectDTO.
 * 3. **Санитизация данных** — очистка ключа и названия перед сохранением.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует работу с опцией `fs_lms_subjects_list` в wp_options.
 * Хранит данные предметов в структурированном виде (key → {key, name}).
 * Использует DTO SubjectDTO для передачи данных между слоями приложения.
 */
class SubjectRepository {

	/**
	 * Конструктор репозитория.
	 */
	public function __construct() {}

	/**
	 * Имя опции в wp_options.
	 */
	private string $option_name = OptionName::SUBJECTS->value;

	/**
	 * Получает "сырые" данные из опции.
	 *
	 * @return array<string, array{key: string, name: string}>
	 */
	private function getRaw(): array {
		// get_option() — получает опцию из таблицы wp_options
		$subjects = get_option( $this->option_name, array() );
		return is_array( $subjects ) ? $subjects : array();
	}

	/**
	 * Возвращает все предметы в виде массива DTO.
	 *
	 * @return SubjectDTO[]
	 */
	public function readAll(): array {
		return array_map(
			fn( $item ) => new SubjectDTO( $item['key'], $item['name'] ),
			$this->getRaw()
		);
	}

	/**
	 * Получает предмет по ключу.
	 *
	 * @param string $key Ключ предмета (например, 'math')
	 *
	 * @return SubjectDTO|null
	 */
	public function getByKey( string $key ): ?SubjectDTO {
		$raw = $this->getRaw();
		if ( ! isset( $raw[ $key ] ) ) {
			return null;
		}
		return new SubjectDTO( $raw[ $key ]['key'], $raw[ $key ]['name'] );
	}

	/**
	 * Сохраняет (создаёт или обновляет) предмет.
	 *
	 * @param SubjectDTO $dto DTO с данными предмета
	 *
	 * @return bool
	 */
	public function save( SubjectDTO $dto ): bool {
		// sanitize_title() — преобразует строку в slug (транслитерация, нижний регистр, дефисы)
		$key = sanitize_title( $dto->key );
		// sanitize_text_field() — удаляет теги и спецсимволы
		$name = sanitize_text_field( $dto->name );

		if ( empty( $key ) || empty( $name ) ) {
			return false;
		}

		$subjects         = $this->getRaw();
		$subjects[ $key ] = array(
			'key'  => $key,
			'name' => $name,
		);

		// update_option() — обновляет опцию, возвращает false при ошибке или отсутствии изменений
		return update_option( $this->option_name, $subjects );
	}

	/**
	 * Удаляет предмет по ключу.
	 *
	 * @param string $key Ключ предмета
	 *
	 * @return bool
	 */
	public function remove( string $key ): bool {
		$subjects = $this->getRaw();

		if ( ! isset( $subjects[ $key ] ) ) {
			return false;
		}

		// unset() — удаляет элемент из массива по ключу
		unset( $subjects[ $key ] );

		return update_option( $this->option_name, $subjects );
	}

	/**
	 * Полностью очищает все предметы (удаляет опцию).
	 *
	 * @return bool
	 */
	public function clear(): bool {
		// delete_option() — удаляет опцию из таблицы wp_options
		return delete_option( $this->option_name );
	}
}
