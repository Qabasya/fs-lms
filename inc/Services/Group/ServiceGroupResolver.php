<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

/**
 * Class ServiceGroupResolver
 *
 * Служебные группы: группы, которые модули заводят для своих целей, а не для учебного процесса школы. В них не
 * зачисляют настоящих учеников, они не попадают в выбор группы при зачислении и в выгрузку учеников.
 *
 * Ядро не знает, какие это группы и чьи: список приходит фильтром `fs_lms_service_group_ids`
 * (`list<int>` ID групп) от модулей. Группа остаётся обычной во всём остальном: её видит преподаватель, сотрудник видит и
 * удаляет её на странице «Группы» (удаление группы любым путём проходит через шов `fs_lms_group_deleting`).
 *
 * Фильтр читается один раз за запрос: им пользуются несколько мест (зачисление, добавление в группу, выгрузка).
 *
 * @package Inc\Services\Group
 */
class ServiceGroupResolver {

	/** @var list<int>|null Кеш на запрос. */
	private ?array $ids = null;

	/**
	 * ID всех служебных групп.
	 *
	 * @return list<int>
	 */
	public function ids(): array {
		if ( null === $this->ids ) {
			$raw = apply_filters( 'fs_lms_service_group_ids', array() );

			$ids = array();
			foreach ( is_array( $raw ) ? $raw : array() as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}
			$this->ids = array_values( $ids );
		}

		return $this->ids;
	}

	/**
	 * Служебная ли группа.
	 *
	 * @param int $groupId ID группы.
	 */
	public function isService( int $groupId ): bool {
		return in_array( $groupId, $this->ids(), true );
	}

	/**
	 * Все ли записи ученика — в служебных группах (тогда это не «настоящий» ученик: его не предлагают в списках выбора,
	 * не выгружают и не переносят пакетом). Ученик без записей служебным не считается — архивные остаются видны.
	 *
	 * @param list<\Inc\DTO\Enrollment\StudentRecordDTO> $records Записи ученика.
	 */
	public function onlyServiceRecords( array $records ): bool {
		if ( array() === $records ) {
			return false;
		}

		foreach ( $records as $record ) {
			if ( ! $this->isService( $record->groupId ) ) {
				return false;
			}
		}

		return true;
	}
}
