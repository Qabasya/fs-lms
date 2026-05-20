<?php

declare(strict_types=1);
namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Enums\OptionName;

/**
 * Class AcademicPeriodRepository
 *
 * Репозиторий для работы с учебными периодами (сущность "Периоды обучения").
 *
 * @package Inc\Repositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — чтение, добавление/обновление и удаление учебных периодов.
 * 2. **Управление текущим периодом** — автоматическое снятие флага при назначении нового текущего периода.
 * 3. **Поиск по ID** — получение конкретного учебного периода.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует работу с опцией `fs_lms_academic_periods` в wp_options.
 * Обрабатывает данные как структурированный массив согласно правилам проекта.
 */
class AcademicPeriodRepository implements RepositoryInterface {

	/**
	 * Возвращает все учебные периоды.
	 *
	 * @inheritDoc
	 * @return array Массив всех учебных периодов [id => ['id', 'name', 'is_current']]
	 */
	public function readAll(): array {
		$periods = get_option( OptionName::ACADEMIC_PERIODS->value, array() );
		return is_array( $periods ) ? $periods : array();
	}

	/**
	 * Добавляет или обновляет учебный период.
	 *
	 * @inheritDoc
	 * @param array $data Массив данных периода (должен содержать ключи 'id' и 'name')
	 *
	 * @return bool
	 */
	public function update( array $data ): bool {
		if ( ! isset( $data['id'] ) || ! isset( $data['name'] ) ) {
			return false;
		}

		$periods    = $this->readAll();
		$is_current = (bool) ( $data['is_current'] ?? false );

		if ( true === $is_current ) {
			foreach ( $periods as $key => $period ) {
				$periods[ $key ]['is_current'] = false;
			}
		}

		$periods[ $data['id'] ] = array(
			'id'         => $data['id'],
			'name'       => $data['name'],
			'is_current' => $is_current,
		);

		update_option( OptionName::ACADEMIC_PERIODS->value, $periods );

		return true;
	}

	/**
	 * Удаляет учебный период по его ID.
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

		$periods = $this->readAll();

		if ( ! isset( $periods[ $data['id'] ] ) ) {
			return false;
		}

		unset( $periods[ $data['id'] ] );
		update_option( OptionName::ACADEMIC_PERIODS->value, $periods );
		return true;
	}

	// ============================ КАСТОМНЫЕ МЕТОДЫ ============================ //

	/**
	 * Получает конкретный учебный период по его ID.
	 *
	 * @param string $id ID учебного периода (например, '2025_2026')
	 *
	 * @return array|null Массив с ключами 'id', 'name', 'is_current' или null
	 */
	public function getById( string $id ): ?array {
		$periods = $this->readAll();
		return $periods[ $id ] ?? null;
	}

	/**
	 * Получает текущий активный учебный период.
	 *
	 * @return array|null Массив текущего периода или null
	 */
	public function getCurrentPeriod(): ?array {
		$periods = $this->readAll();

		foreach ( $periods as $period ) {
			if ( true === ( $period['is_current'] ?? false ) ) {
				return $period;
			}
		}

		return null;
	}
}
