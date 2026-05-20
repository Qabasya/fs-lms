<?php

declare(strict_types=1);
namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Enums\OptionName;

/**
 * Class AcademicPeriodRepository
 *
 * Репозиторий для работы с учебными периодами.
 *
 * @package Inc\Repositories
 */
class AcademicPeriodRepository implements RepositoryInterface {

	/**
	 * Возвращает все учебные периоды с автоматической фильтрацией битых данных.
	 *
	 * @inheritDoc
	 * @return array Массив всех учебных периодов [id => ['id', 'name', 'start_date', 'end_date', 'is_current']]
	 */
	public function readAll(): array {
		$periods = get_option( OptionName::ACADEMIC_PERIODS->value, array() );
		if ( ! is_array( $periods ) ) {
			return array();
		}

		$clean_periods = array();
		$has_corrupted = false;

		// Самоочистка базы: фильтруем записи с неправильными, пустыми ключами или кривыми датами
		foreach ( $periods as $key => $period ) {
			$id   = isset( $period['id'] ) ? trim( (string) $period['id'] ) : '';
			$name = isset( $period['name'] ) ? trim( (string) $period['name'] ) : '';

			// Если ID пустой, или ключ массива не совпадает с ID, или имя пустое — это битая запись
			if ( empty( $id ) || empty( $name ) || (string) $key !== $id ) {
				$has_corrupted = true;
				continue;
			}

			// Проверяем хронологию дат уже существующих записей
			$start_ts = ! empty( $period['start_date'] ) ? strtotime( (string) $period['start_date'] ) : false;
			$end_ts   = ! empty( $period['end_date'] ) ? strtotime( (string) $period['end_date'] ) : false;

			if ( $start_ts && $end_ts && $start_ts > $end_ts ) {
				// Если в базе уже лежит период, где дата начала позже даты конца — меняем их местами
				$tmp = $period['start_date'];
				$period['start_date'] = $period['end_date'];
				$period['end_date'] = $tmp;
				$has_corrupted = true;
			}

			$clean_periods[ $id ] = $period;
		}

		// Если нашли и вычистили мусор — обновляем опцию в БД, чтобы она больше не грузила сервер
		if ( $has_corrupted ) {
			update_option( OptionName::ACADEMIC_PERIODS->value, $clean_periods );
		}

		return $clean_periods;
	}

	/**
	 * Добавляет или обновляет учебный период с жесткой валидацией данных.
	 *
	 * @inheritDoc
	 * @param array $data Массив данных периода
	 *
	 * @return bool Возвращает false, если данные не прошли валидацию
	 */
	public function update( array $data ): bool {
		$id         = isset( $data['id'] ) ? sanitize_key( trim( (string) $data['id'] ) ) : '';
		$name       = isset( $data['name'] ) ? sanitize_text_field( trim( (string) $data['name'] ) ) : '';
		$start_date = isset( $data['start_date'] ) ? sanitize_text_field( trim( (string) $data['start_date'] ) ) : '';
		$end_date   = isset( $data['end_date'] ) ? sanitize_text_field( trim( (string) $data['end_date'] ) ) : '';

		if ( empty( $id ) || empty( $name ) || empty( $start_date ) || empty( $end_date ) ) {
			return false;
		}

		if ( ! preg_match( '/^[a-z0-9_]+$/', $id ) ) {
			return false;
		}

		$start_ts = strtotime( $start_date );
		$end_ts   = strtotime( $end_date );

		if ( false === $start_ts || false === $end_ts || $start_ts > $end_ts ) {
			return false;
		}

		$periods    = $this->readAll();
		$is_current = (bool) ( $data['is_current'] ?? false );

		if ( true === $is_current ) {
			foreach ( $periods as $key => $period ) {
				$periods[ $key ]['is_current'] = false;
			}
		}

		// Записываем чистые, отвалидированные данные
		$periods[ $id ] = array(
			'id'         => $id,
			'name'       => $name,
			'start_date' => $start_date,
			'end_date'   => $end_date,
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

		$id      = trim( (string) $data['id'] );
		$periods = $this->readAll();

		if ( ! isset( $periods[ $id ] ) ) {
			return false;
		}

		unset( $periods[ $id ] );
		update_option( OptionName::ACADEMIC_PERIODS->value, $periods );
		return true;
	}

	// ============================ КАСТОМНЫЕ МЕТОДЫ ============================ //

	/**
	 * Получает конкретный учебный период по его ID.
	 */
	public function getById( string $id ): ?array {
		$periods = $this->readAll();
		return $periods[ trim( $id ) ] ?? null;
	}

	/**
	 * Получает текущий активный учебный период.
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