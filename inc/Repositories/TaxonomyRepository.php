<?php

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\DTO\TaxonomyDataDTO;
use Inc\Enums\OptionName;

/**
 * Class TaxonomyRepository
 *
 * Репозиторий для хранения пользовательских таксономий, привязанных к предметам.
 *
 * Данные хранятся в WordPress-опции в формате:
 * [
 *     'subject_key' => [
 *         'tax_slug' => ['name' => 'Название таксономии'],
 *         ...
 *     ],
 *     ...
 * ]
 *
 * При чтении данных возвращает DTO-объекты (TaxonomyDataDTO) для типобезопасной работы.
 *
 * @package Inc\Repositories
 * @implements RepositoryInterface
 */
class TaxonomyRepository implements RepositoryInterface {
	/**
	 * Имя опции WordPress для хранения кастомных таксономий.
	 *
	 * @var string
	 */
	private string $option_name = OptionName::TAXONOMY->value;

	/**
	 * Внутренний метод для получения сырых данных из Options API.
	 *
	 * @return array<string, array<string, array{name: string}>> Сырые данные из опции
	 */
	private function getRaw(): array {
		$all = get_option( $this->option_name, [] );

		// Гарантируем возврат массива даже при повреждённых данных
		return is_array( $all ) ? $all : [];
	}

	/**
	 * Получить все кастомные таксономии всех предметов.
	 *
	 * Возвращает структурированный массив, где каждый элемент таксономии
	 * представлен объектом TaxonomyDataDTO.
	 *
	 * @return array<string, array<int, TaxonomyDataDTO>>
	 *         Массив всех таксономий, сгруппированных по предметам,
	 *         значения — массив DTO-объектов
	 */
	public function readAll(): array {
		$raw_all = $this->getRaw();
		$result  = [];

		foreach ( $raw_all as $subject_key => $taxonomies ) {
			$result[ $subject_key ] = [];

			foreach ( $taxonomies as $slug => $data ) {
				// Преобразуем сырые данные в DTO
				$result[ $subject_key ][] = TaxonomyDataDTO::fromArray( $slug, $data, $subject_key );
			}
		}

		return $result;
	}

	/**
	 * Обновить или создать таксономию для предмета.
	 *
	 * Ожидает в $data:
	 * - subject_key: ключ предмета (slug)
	 * - tax_slug: уникальный идентификатор таксономии
	 * - name: отображаемое название таксономии
	 *
	 * @param array{subject_key: string, tax_slug: string, name: string} $data
	 *        Данные для сохранения таксономии
	 *
	 * @return bool Успешность операции
	 */
	public function update( array $data ): bool {
		$all = $this->getRaw();

		$subject_key = $data['subject_key'] ?? '';
		$tax_slug    = $data['tax_slug'] ?? '';

		// Валидация обязательных полей
		if ( empty( $subject_key ) || empty( $tax_slug ) ) {
			return false;
		}

		// Инициализируем массив предмета, если его ещё нет
		if ( ! isset( $all[ $subject_key ] ) ) {
			$all[ $subject_key ] = [];
		}


		$all[ $subject_key ][ $tax_slug ] = [
			'name'         => sanitize_text_field( $data['name'] ?? '' ),
			'display_type' => sanitize_text_field( $data['display_type'] ?? 'select' ),
		];

		// Сохраняем обновлённый массив в опции WordPress
		return update_option( $this->option_name, $all );
	}

	/**
	 * Удалить таксономию предмета.
	 *
	 * Ожидает в $data:
	 * - subject_key: ключ предмета (slug)
	 * - tax_slug: уникальный идентификатор таксономии
	 *
	 * @param array{subject_key: string, tax_slug: string} $data
	 *        Данные для удаления таксономии
	 *
	 * @return bool Успешность операции (false, если таксономия не найдена)
	 */
	public function delete( array $data ): bool {
		$all         = $this->getRaw();
		$subject_key = $data['subject_key'] ?? '';
		$tax_slug    = $data['tax_slug'] ?? '';

		// Проверяем существование таксономии перед удалением
		if ( isset( $all[ $subject_key ][ $tax_slug ] ) ) {
			unset( $all[ $subject_key ][ $tax_slug ] );

			// Сохраняем обновлённый массив в опции WordPress
			return update_option( $this->option_name, $all );
		}

		return false;
	}

	/**
	 * Получить таксономии конкретного предмета.
	 *
	 * Хелпер для контроллеров — возвращает только таксономии указанного предмета
	 * в виде массива DTO-объектов.
	 *
	 * @param string $subject_key Ключ предмета (slug)
	 *
	 * @return array<int, TaxonomyDataDTO>
	 *         Массив DTO-объектов таксономий предмета или пустой массив
	 */
	public function getBySubject( string $subject_key ): array {
		$raw_all       = $this->getRaw();
		$subject_taxes = $raw_all[ $subject_key ] ?? [];

		$result = [];
		foreach ( $subject_taxes as $slug => $data ) {
			// Преобразуем сырые данные в DTO
			$result[] = TaxonomyDataDTO::fromArray( $slug, $data, $subject_key );
		}

		return $result;
	}

	/**
	 * Удалить все таксономии указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return bool Успешность операции
	 */
	public function deleteBySubject( string $subject_key ): bool {
		$all = $this->getRaw();

		if ( ! isset( $all[ $subject_key ] ) ) {
			return true;
		}

		unset( $all[ $subject_key ] );

		return update_option( $this->option_name, $all );
	}

	/**
	 * Вернуть сырые данные таксономий указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array<string, array{name: string, display_type: string}>
	 */
	public function getRawForSubject( string $subject_key ): array {
		return $this->getRaw()[ $subject_key ] ?? [];
	}

	/**
	 * Полностью очистить все кастомные таксономии.
	 *
	 * Удаляет опцию из базы данных целиком.
	 *
	 * @return bool Успешность операции
	 */
	public function clear(): bool {
		return delete_option( $this->option_name );
	}
}