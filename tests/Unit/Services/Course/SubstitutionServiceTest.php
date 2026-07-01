<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;
use Inc\Services\Course\SubstitutionService;
use PHPUnit\Framework\TestCase;

class SubstitutionServiceTest extends TestCase {

	private SubstitutionRepository&\PHPUnit\Framework\MockObject\MockObject $repo;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private SubstitutionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->repo    = $this->createMock( SubstitutionRepository::class );
		$this->groups  = $this->createMock( GroupsRepository::class );
		$this->service = new SubstitutionService( $this->repo, $this->groups );
	}

	public function test_assign_creates_with_original_teacher_snapshot(): void {
		$this->groups->method( 'findById' )->with( 7 )->willReturn( (object) array( 'teacher_id' => 42 ) );
		$this->repo->expects( self::once() )
			->method( 'create' )
			->with( self::callback(
				static fn( $data ) => 7 === $data['group_id']
					&& 42 === $data['original_teacher_id']
					&& 55 === $data['substitute_teacher_id']
					&& '2026-05-01' === $data['valid_from']
					&& '2026-05-31' === $data['valid_to']
			) )
			->willReturn( 3 );

		self::assertSame( 3, $this->service->assign( 7, 55, '2026-05-01', '2026-05-31', 'Отпуск', 9 ) );
	}

	public function test_assign_throws_when_group_missing(): void {
		$this->groups->method( 'findById' )->willReturn( null );
		$this->repo->expects( self::never() )->method( 'create' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->assign( 7, 55, '2026-05-01', '2026-05-31', null, 9 );
	}

	public function test_assign_throws_when_from_after_to(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'teacher_id' => 42 ) );
		$this->repo->expects( self::never() )->method( 'create' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->assign( 7, 55, '2026-05-31', '2026-05-01', null, 9 );
	}

	public function test_assign_throws_on_invalid_date(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'teacher_id' => 42 ) );
		$this->repo->expects( self::never() )->method( 'create' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->assign( 7, 55, '2026-13-40', '2026-05-31', null, 9 );
	}
}
