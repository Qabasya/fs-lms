<?php

declare( strict_types=1 );

namespace Unit\DTO\Task;

use Inc\DTO\Task\TaskCriteriaDTO;
use PHPUnit\Framework\TestCase;

/**
 * Критерии оценивания из меты задания: один разбор для результатов, деталей работы и проверки.
 */
class TaskCriteriaDTOTest extends TestCase {

	private function meta(): array {
		return array( 'task_criteria' => array( 'criteria' => array(
			array( 'label' => 'Идея', 'max_points' => '2' ),
			array( 'label' => 'Код', 'max_points' => 1 ),
		) ) );
	}

	public function test_rows_carry_label_max_and_awarded_points(): void {
		$rows = TaskCriteriaDTO::fromMeta( $this->meta() )->rows( array( 2, '0.5' ) );

		self::assertSame( array(
			array( 'label' => 'Идея', 'max_points' => 2.0, 'awarded' => 2.0 ),
			array( 'label' => 'Код', 'max_points' => 1.0, 'awarded' => 0.5 ),
		), $rows );
	}

	public function test_not_graded_yet_means_null_awarded(): void {
		$rows = TaskCriteriaDTO::fromMeta( $this->meta() )->rows( null );

		self::assertNull( $rows[0]['awarded'] );
	}

	public function test_missing_or_broken_meta_is_empty(): void {
		foreach ( array( null, '', array(), array( 'task_criteria' => 'x' ), array( 'task_criteria' => array( 'criteria' => 5 ) ) ) as $meta ) {
			$dto = TaskCriteriaDTO::fromMeta( $meta );
			self::assertTrue( $dto->isEmpty() );
			self::assertSame( array(), $dto->rows( null ) );
		}
	}

	public function test_defs_keep_original_indexes(): void {
		self::assertSame( array( 0, 1 ), array_keys( TaskCriteriaDTO::fromMeta( $this->meta() )->defs() ) );
	}
}
