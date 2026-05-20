<?php

declare(strict_types=1);
namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Enums\OptionName;

/**
 * Class AcademicYearRepository
 *
 * Репозиторий для работы с учебными годами (сущность "Года обучения").
 *
 * @package Inc\Repositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — чтение, добавление/обновление и удаление учебных годов.
 * 2. **Управление текущим годом** — автоматическое снятие флага при назначении нового текущего года.
 * 3. **Поиск по ID** — получение конкретного учебного года.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует работу с опцией `fs_lms_academic_years` в wp_options.
 * Обрабатывает данные как структурированный массив согласно правилам проекта.
 */
class AcademicYearRepository implements RepositoryInterface {

	/**
	 * Возвращает все учебные года.
	 *
	 * @inheritDoc
	 * @return array Массив всех учебных годов [id => ['id', 'name', 'is_current']]
	 */
	public function readAll(): array {
		// get_option() — получает опцию из таблицы wp_options
		$years = get_option( OptionName::ACADEMIC_YEARS->value, array() );
		return is_array( $years ) ? $years : array();
	}

	/**
	 * Добавляет или обновляет учебный год.
	 *
	 * @inheritDoc
	 * @param array $data Массив данных года (должен содержать ключи 'id' и 'name')
	 *
	 * @return bool
	 */
	public function update( array $data ): bool {
		if ( ! isset( $data['id'] ) || ! isset( $data['name'] ) ) {
			return false;
		}

		$years      = $this->readAll();
		$is_current = (bool) ( $data['is_current'] ?? false );

		// Если этот год назначается текущим, сбрасываем флаг у всех остальных годов
		if ( true === $is_current ) {
			foreach ( $years as $key => $year ) {
				$years[ $key ]['is_current'] = false;
			}
		}

		// Сохраняем или обновляем запись
		$years[ $data['id'] ] = array(
			'id'         => $data['id'],
			'name'       => $data['name'],
			'is_current' => $is_current,
		);

		// update_option() — обновляет опцию (возвращает false, если данные идентичны старым)
		update_option( OptionName::ACADEMIC_YEARS->value, $years );

		// Возвращаем true, так как структура успешно обработана
		return true;
	}

	/**
	 * Удаляет учебный год по его ID.
	 *
	 * @inheritDoc
	 * @param array $data Массив, содержащий ключ 'id'
	 *
	 * @return bool
	 */
	public function delete( array $data ): bool {
		if ( ! isset( $data['id'] ) ) {
			return false;
		}

		$years = $this->readAll();

		if ( ! isset( $years[ $data['id'] ] ) ) {
			return false;
		}

		// unset() — удаляет элемент из массива по ключу
		unset( $years[ $data['id'] ] );
		update_option( OptionName::ACADEMIC_YEARS->value, $years );
		return true;
	}

	// ============================ КАСТОМНЫЕ МЕТОДЫ ============================ //

	/**
	 * Получает конкретный учебный год по его ID.
	 *
	 * @param string $id ID учебного года (например, '2025_2026')
	 *
	 * @return array|null Массив с ключами 'id', 'name', 'is_current' или null
	 */
	public function getById( string $id ): ?array {
		$years = $this->readAll();
		return $years[ $id ] ?? null;
	}

	/**
	 * Получает текущий активный учебный год.
	 *
	 * @return array|null Массив текущего года или null
	 */
	public function getCurrentYear(): ?array {
		$years = $this->readAll();

		foreach ( $years as $year ) {
			if ( true === ( $year['is_current'] ?? false ) ) {
				return $year;
			}
		}

		return null;
	}
}