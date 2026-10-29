<?php

declare( strict_types=1 );

namespace Unit\Services\Group;

use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Services\Group\ServiceGroupResolver;
use PHPUnit\Framework\TestCase;

/** Служебные группы модулей: список приходит фильтром `fs_lms_service_group_ids`, без подписчиков его нет. */
class ServiceGroupResolverTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_filter_returns'] );
		parent::tearDown();
	}

	private function subscriberReturns( mixed $value ): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_service_group_ids'] = $value;
	}

	public function test_without_subscribers_no_group_is_service(): void {
		$resolver = new ServiceGroupResolver();

		self::assertSame( array(), $resolver->ids() );
		self::assertFalse( $resolver->isService( 5 ) );
	}

	public function test_ids_from_the_filter_are_service_groups(): void {
		$this->subscriberReturns( array( 5, 9 ) );
		$resolver = new ServiceGroupResolver();

		self::assertTrue( $resolver->isService( 5 ) );
		self::assertTrue( $resolver->isService( 9 ) );
		self::assertFalse( $resolver->isService( 6 ) );
	}

	public function test_ids_are_normalised_to_unique_positive_integers(): void {
		$this->subscriberReturns( array( '7', 7, 0, -3, 'abc', 11.0 ) );

		self::assertSame( array( 7, 11 ), ( new ServiceGroupResolver() )->ids() );
	}

	public function test_a_non_array_answer_is_ignored(): void {
		foreach ( array( 'abc', null, 5, false ) as $bad ) {
			$this->subscriberReturns( $bad );

			self::assertSame( array(), ( new ServiceGroupResolver() )->ids() );
		}
	}

	public function test_the_filter_is_read_once_per_resolver(): void {
		$this->subscriberReturns( array( 5 ) );
		$resolver = new ServiceGroupResolver();
		self::assertTrue( $resolver->isService( 5 ) );

		$this->subscriberReturns( array( 6 ) );

		self::assertTrue( $resolver->isService( 5 ), 'ответ запомнен на запрос' );
		self::assertFalse( $resolver->isService( 6 ) );
	}
	private function record( int $groupId ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array(
			'id' => $groupId, 'student_person_id' => 1, 'parent_person_id' => 0, 'group_id' => $groupId, 'status' => 'active',
			'enrolled_at' => '2026-09-01 00:00:00', 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00',
		) );
	}

	public function test_a_student_is_service_only_when_every_record_is_in_a_service_group(): void {
		$this->subscriberReturns( array( 5, 9 ) );
		$resolver = new ServiceGroupResolver();

		self::assertTrue( $resolver->onlyServiceRecords( array( $this->record( 5 ) ) ) );
		self::assertTrue( $resolver->onlyServiceRecords( array( $this->record( 5 ), $this->record( 9 ) ) ) );
		self::assertFalse( $resolver->onlyServiceRecords( array( $this->record( 5 ), $this->record( 6 ) ) ), 'есть настоящая группа — ученик настоящий' );
		self::assertFalse( $resolver->onlyServiceRecords( array( $this->record( 6 ) ) ) );
	}

	public function test_a_student_without_records_is_not_service_only(): void {
		$this->subscriberReturns( array( 5 ) );

		self::assertFalse( ( new ServiceGroupResolver() )->onlyServiceRecords( array() ), 'архивный ученик без записей остаётся виден' );
	}

	public function test_without_service_groups_nobody_is_service_only(): void {
		self::assertFalse( ( new ServiceGroupResolver() )->onlyServiceRecords( array( $this->record( 5 ) ) ) );
	}
}
