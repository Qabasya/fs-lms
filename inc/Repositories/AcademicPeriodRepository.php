<?php

declare(strict_types=1);

namespace Inc\Repositories;

use Inc\DTO\AcademicPeriodDTO;
use Inc\Enums\OptionName;

class AcademicPeriodRepository {

	/**
	 * Возвращает все учебные периоды. Фильтрует записи с отсутствующим/несовпадающим ID
	 * или пустым именем. Не выполняет запись и не модифицирует данные при чтении.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function readAll(): array {
		$periods = get_option( OptionName::ACADEMIC_PERIODS->value, array() );

		if ( ! is_array( $periods ) ) {
			return array();
		}

		$clean = array();

		foreach ( $periods as $key => $period ) {
			$id   = isset( $period['id'] ) ? trim( (string) $period['id'] ) : '';
			$name = isset( $period['name'] ) ? trim( (string) $period['name'] ) : '';

			if ( empty( $id ) || empty( $name ) || (string) $key !== $id ) {
				continue;
			}

			$clean[ $id ] = $period;
		}

		return $clean;
	}

	public function getById( string $id ): ?AcademicPeriodDTO {
		$periods = $this->readAll();
		$data    = $periods[ trim( $id ) ] ?? null;

		return $data ? AcademicPeriodDTO::fromArray( $data ) : null;
	}

	public function getCurrentPeriod(): ?AcademicPeriodDTO {
		foreach ( $this->readAll() as $period ) {
			if ( true === ( $period['is_current'] ?? false ) ) {
				return AcademicPeriodDTO::fromArray( $period );
			}
		}

		return null;
	}

	/**
	 * Сохраняет или обновляет учебный период. Если период помечен как текущий,
	 * снимает флаг is_current со всех остальных периодов (уникальность текущего).
	 */
	public function save( AcademicPeriodDTO $dto ): bool {
		$periods = $this->readAll();

		if ( $dto->is_current ) {
			foreach ( $periods as $key => $period ) {
				$periods[ $key ]['is_current'] = false;
			}
		}

		$periods[ $dto->id ] = $dto->toArray();

		return (bool) update_option( OptionName::ACADEMIC_PERIODS->value, $periods );
	}

	public function remove( string $id ): bool {
		$periods = $this->readAll();

		if ( ! isset( $periods[ $id ] ) ) {
			return false;
		}

		unset( $periods[ $id ] );

		return (bool) update_option( OptionName::ACADEMIC_PERIODS->value, $periods );
	}
}
