<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course {
	function get_current_user_id(): int { return 99; }
	function status_header( int $status ): void { $GLOBALS['_self_paced_status'] = $status; }
	function nocache_headers(): void {}
	function get_404_template(): string { return '404.php'; }
}

namespace Unit\Controllers\Course {
	use Inc\Controllers\Course\LessonPlayerController;
	use Inc\DTO\Course\GroupLessonDTO;
	use Inc\DTO\Person\PersonDTO;
	use Inc\Repositories\WPDBRepositories\GroupsRepository;
	use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
	use Inc\Repositories\WPDBRepositories\PersonRepository;
	use Inc\Services\Course\GroupAccessGuard;
	use Inc\Services\Course\LessonPlayerService;
	use Inc\Services\Course\PlayerModeSwitchService;
	use Inc\Services\Log\StudentActivityLogger;
	use PHPUnit\Framework\TestCase;

	class LessonPlayerSelfPacedAccessTest extends TestCase {
		public function test_parent_direct_url_never_builds_student_content_or_signed_urls(): void {
			$GLOBALS['_fs_test_page_slug'] = 'lesson';
			$_GET['gl'] = '7';
			$GLOBALS['wp_query'] = new class { public function set_404(): void {} };
			$persons = $this->createMock( PersonRepository::class );
			$persons->method( 'findByWpUserId' )->willReturn( PersonDTO::fromArray( array( 'id' => 99, 'last_name' => 'Parent', 'first_name' => 'P', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01' ) ) );
			$guard = $this->createMock( GroupAccessGuard::class );
			$guard->method( 'isParentOf' )->willReturn( true );
			$lessons = $this->createMock( GroupLessonRepository::class );
			$row = new GroupLessonDTO( 7, 3, 10, 1, null, array(), null, null, false, null, 'open', null, null, true, null, null, null );
			$lessons->method( 'find' )->willReturn( $row );
			$player = $this->createMock( LessonPlayerService::class );
			$player->expects( self::never() )->method( 'buildRouteView' );
			$groups = $this->createMock( GroupsRepository::class );
			$groups->method( 'findById' )->willReturn( (object) array( 'self_paced_course_id' => 10 ) );
			$controller = new LessonPlayerController( $persons, $guard, $lessons, $player, $this->createMock( PlayerModeSwitchService::class ), $this->createMock( StudentActivityLogger::class ), $groups );
			self::assertSame( '404.php', $controller->loadTemplate( 'theme.php' ) );
			self::assertSame( 404, $GLOBALS['_self_paced_status'] );
			unset( $_GET['gl'], $GLOBALS['_fs_test_page_slug'], $GLOBALS['wp_query'], $GLOBALS['_self_paced_status'] );
		}
	}
}
