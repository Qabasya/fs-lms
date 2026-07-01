<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\RoomDTO;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Course\RoomAvailabilityService;
use PHPUnit\Framework\TestCase;

class RoomAvailabilityServiceTest extends TestCase {

	private RoomRepository&\PHPUnit\Framework\MockObject\MockObject $rooms;
	private RoomAvailabilityService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->rooms   = $this->createMock( RoomRepository::class );
		$this->service = new RoomAvailabilityService( $this->rooms );
	}

	public function test_is_free_negates_is_busy(): void {
		$this->rooms->method( 'isBusy' )->willReturn( false );
		self::assertTrue( $this->service->isFree( 1, '2026-05-20 09:00:00', '2026-05-20 10:00:00' ) );
	}

	public function test_list_free_rooms_filters_by_subject_and_busy(): void {
		$this->rooms->method( 'findAll' )->with( true )->willReturn( array(
			new RoomDTO( 1, 'Инф-1', 20, array( 'inf' ), true ),
			new RoomDTO( 2, 'Рус-1', 20, array( 'rus' ), true ), // не тот предмет
			new RoomDTO( 3, 'Инф-2', 20, array( 'inf' ), true ), // занят
		) );
		$this->rooms->method( 'isBusy' )->willReturnCallback( static fn( $id ) => 3 === $id );

		$free = $this->service->listFreeRooms( '2026-05-20 09:00:00', '2026-05-20 10:00:00', 'inf' );

		self::assertCount( 1, $free );
		self::assertSame( 1, $free[0]->id );
	}
}
