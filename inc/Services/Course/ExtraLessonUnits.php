<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;

/**
 * Class ExtraLessonUnits
 *
 * Дополнительное занятие для части группы хранится строкой `group_lessons` на каждого ученика
 * (`kind = individual`, общие `extra_key` и `scheduled_at`), а для преподавателя это одно занятие.
 * Помощник сворачивает строки в «единицы»: журнал показывает единицу одним столбцом, отметка
 * посещаемости при этом лежит под строкой самого ученика — так её читают кабинеты ученика и родителя.
 *
 * Правило свёртки то же, что на экране «Работы» (`PassedWorksService::unitRows()`): строка, перенесённая
 * на другое время, считается своим занятием; ключ единицы — строка с наибольшим ID.
 *
 * @package Inc\Services\Course
 */
final class ExtraLessonUnits {

	/**
	 * Единицы по строкам группы: каждая — строки одного занятия, первой идёт строка с наибольшим ID.
	 * Обычные, индивидуальные без `extra_key` и недатированные строки не входят.
	 *
	 * @param GroupLessonDTO[] $rows Строки группы
	 *
	 * @return list<non-empty-list<GroupLessonDTO>> По дате и ключу
	 */
	public static function group( array $rows ): array {
		$units = array();
		foreach ( $rows as $row ) {
			if ( ! $row->kind->isIndividual() || null === $row->extraKey || null === $row->scheduledAt || null === $row->studentPersonId ) {
				continue;
			}
			$units[ $row->extraKey . '|' . $row->scheduledAt ][] = $row;
		}

		$units = array_map(
			static function ( array $unit ): array {
				usort( $unit, static fn( GroupLessonDTO $a, GroupLessonDTO $b ): int => $b->id <=> $a->id );

				return $unit;
			},
			array_values( $units )
		);
		usort(
			$units,
			static fn( array $a, array $b ): int => array( $a[0]->scheduledAt, $a[0]->id ) <=> array( $b[0]->scheduledAt, $b[0]->id )
		);

		return $units;
	}

	/**
	 * Ключ единицы — ID её строки с наибольшим ID (по нему журнал адресует столбец).
	 *
	 * @param non-empty-list<GroupLessonDTO> $unit
	 */
	public static function key( array $unit ): int {
		return $unit[0]->id;
	}

	/**
	 * Строка ученика в единице; null — ученик в этом занятии не участвует.
	 *
	 * @param non-empty-list<GroupLessonDTO> $unit
	 */
	public static function rowOf( array $unit, int $personId ): ?GroupLessonDTO {
		foreach ( $unit as $row ) {
			if ( $row->studentPersonId === $personId ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Строка ученика → ключ единицы, для всех дополнительных занятий группы.
	 *
	 * @param GroupLessonDTO[] $rows Строки группы
	 *
	 * @return array<int, int>
	 */
	public static function keyMap( array $rows ): array {
		$map = array();
		foreach ( self::group( $rows ) as $unit ) {
			foreach ( $unit as $row ) {
				$map[ $row->id ] = self::key( $unit );
			}
		}

		return $map;
	}
}
