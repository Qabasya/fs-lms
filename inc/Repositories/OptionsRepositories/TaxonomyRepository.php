<?php

declare(strict_types=1);

namespace Inc\Repositories\OptionsRepositories;

use Inc\DTO\Subject\TaxonomyDataDTO;
use Inc\Enums\OptionName;

/**
 * Class TaxonomyRepository
 *
 * Репозиторий для работы с пользовательскими таксономиями предметов.
 *
 * @package Inc\Repositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — создание, чтение, обновление и удаление таксономий.
 * 2. **Структура данных** — хранение в формате [subject_key][tax_slug] => данные.
 * 3. **Группировка по предмету** — получение всех таксономий для указанного предмета.
 * 4. **Каскадное удаление** — удаление всех таксономий предмета по subject_key.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует работу с опцией `fs_lms_custom_taxonomies` в wp_options.
 * Обрабатывает данные о пользовательских таксономиях (авторы, темы, жанры и т.д.),
 * которые создаются для каждого предмета. Использует DTO TaxonomyDataDTO
 * для типобезопасной передачи данных.
 */
class TaxonomyRepository {

	/**
	 * Конструктор репозитория.
	 */
	public function __construct() {}

	/**
	 * Имя опции в wp_options.
	 */
	private string $option_name = OptionName::Taxonomy->value;

	/**
	 * Получает "сырые" данные из опции.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function getRaw(): array {
		// get_option() — получает опцию из таблицы wp_options
		$all = get_option( $this->option_name, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Возвращает все таксономии, сгруппированные по предметам.
	 *
	 * @return TaxonomyDataDTO [subject_key => DTO[]]
	 */
	public function readAll(): array {
		$result = array();

		foreach ( $this->getRaw() as $subject_key => $taxonomies ) {
			$result[ $subject_key ] = array();
			foreach ( $taxonomies as $slug => $data ) {
				// fromArray() — фабричный метод DTO для создания из массива
				$result[ $subject_key ][] = TaxonomyDataDTO::fromArray( $slug, $data, $subject_key );
			}
		}

		return $result;
	}

	/**
	 * Возвращает все таксономии для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета (например, 'math')
	 *
	 * @return TaxonomyDataDTO[]
	 */
	public function getBySubject( string $subject_key ): array {
		$subject_taxes = $this->getRaw()[ $subject_key ] ?? array();
		$result        = array();

		foreach ( $subject_taxes as $slug => $data ) {
			$result[] = TaxonomyDataDTO::fromArray( $slug, $data, $subject_key );
		}

		return $result;
	}

	/**
	 * Сохраняет (создаёт или обновляет) таксономию.
	 *
	 * @param TaxonomyDataDTO $dto DTO с данными таксономии
	 *
	 * @return bool
	 */
	public function save( TaxonomyDataDTO $dto ): bool {
		$all = $this->getRaw();

		if ( ! isset( $all[ $dto->subject_key ] ) ) {
			$all[ $dto->subject_key ] = array();
		}

		$all[ $dto->subject_key ][ $dto->slug ] = $dto->toArray();

		return update_option( $this->option_name, $all );
	}

	/**
	 * Удаляет конкретную таксономию.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $tax_slug    Слаг таксономии
	 *
	 * @return bool
	 */
	public function remove( string $subject_key, string $tax_slug ): bool {
		$all = $this->getRaw();

		if ( ! isset( $all[ $subject_key ][ $tax_slug ] ) ) {
			return false;
		}

		// unset() — удаляет элемент из массива по ключу
		unset( $all[ $subject_key ][ $tax_slug ] );

		// Если у предмета не осталось таксономий — удаляем весь ключ предмета
		if ( empty( $all[ $subject_key ] ) ) {
			unset( $all[ $subject_key ] );
		}

		return update_option( $this->option_name, $all );
	}

	/**
	 * Удаляет все таксономии указанного предмета (каскадное удаление).
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return bool
	 */
	public function removeBySubject( string $subject_key ): bool {
		$all = $this->getRaw();

		if ( ! isset( $all[ $subject_key ] ) ) {
			return true;  // Нечего удалять
		}

		unset( $all[ $subject_key ] );

		return update_option( $this->option_name, $all );
	}

	/**
	 * Полностью очищает все таксономии (удаляет опцию).
	 *
	 * @return bool
	 */
	public function clear(): bool {
		// delete_option() — удаляет опцию из таблицы wp_options
		return delete_option( $this->option_name );
	}
}