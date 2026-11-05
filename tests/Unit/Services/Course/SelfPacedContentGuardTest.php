<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\ModuleDTO;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Course\SelfPacedContentGuard;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;

class SelfPacedContentGuardTest extends TestCase {
	private function guard( array $posts, array $meta, bool $automatic = true, bool $catalog = false ): SelfPacedContentGuard {
		$manager = $this->createMock( PostManager::class );
		$manager->method( 'get' )->willReturnCallback( static fn( int $id ) => $posts[ $id ] ?? null );
		$manager->method( 'getMeta' )->willReturnCallback( static fn( int $id, string $key ) => $meta[ $id ][ $key ] ?? array() );
		$manager->method( 'search' )->willReturn( $catalog ? array( $posts[1] ) : array() );
		$resolver = $this->createMock( TemplateResolver::class );
		$resolver->method( 'resolveId' )->willReturn( TaskTemplate::Standard->value );
		$registry = $this->createMock( TaskCheckerRegistry::class );
		$registry->method( 'has' )->willReturn( $automatic );
		$subjects = $this->createMock( SubjectRepository::class );
		$subjects->method( 'readAll' )->willReturn( array( new \Inc\DTO\Subject\SubjectDTO( 'inf', 'Информатика' ) ) );
		return new SelfPacedContentGuard( $manager, $subjects, $resolver, $registry );
	}

	private function course( bool $enabled = true ): CourseDTO {
		return new CourseDTO( 1, 'inf', 'Курс', '', array( new ModuleDTO( 'm', 'Модуль', array( 10 ) ) ), 1, 'publish', selfPacedEnabled: $enabled );
	}

	private function post( int $id, string $type ): \WP_Post {
		return new \WP_Post( array( 'ID' => $id, 'post_type' => $type, 'post_title' => 'Контент', 'post_author' => 1, 'post_status' => 'publish' ) );
	}

	public function test_legacy_course_does_not_receive_new_restrictions(): void {
		$this->guard( array(), array() )->assertCourse( $this->course( false ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_missing_lesson_fails_closed(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->guard( array(), array() )->assertCourse( $this->course() );
	}

	public function test_assessment_is_rejected_before_enable(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->guard( array( 10 => $this->post( 10, 'inf_lessons' ) ), array( 10 => array( PostMetaName::Meta->value => array( 'steps' => array( array( 'id' => 's', 'type' => 'assessment', 'payload' => array( 'ref' => 20 ) ) ) ) ) ) )->assertCourse( $this->course() );
	}

	public function test_manual_task_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->guard( array( 10 => $this->post( 10, 'inf_lessons' ), 20 => $this->post( 20, 'inf_tasks' ) ), array( 10 => array( PostMetaName::Meta->value => array( 'steps' => array( array( 'id' => 's', 'type' => 'task', 'payload' => array( 'ref' => 20 ) ) ) ) ) ), false )->assertCourse( $this->course() );
	}

	public function test_automatic_task_is_accepted(): void {
		$this->guard( array( 10 => $this->post( 10, 'inf_lessons' ), 20 => $this->post( 20, 'inf_tasks' ) ), array( 10 => array( PostMetaName::Meta->value => array( 'steps' => array( array( 'id' => 's', 'type' => 'task', 'payload' => array( 'ref' => 20 ) ) ) ) ) ) )->assertCourse( $this->course() );
		$this->addToAssertionCount( 1 );
	}
	public function test_lesson_candidate_is_checked_before_current_valid_state_is_overwritten(): void {
		$meta = array(
			1 => array( PostMetaName::CourseSelfPacedEnabled->value => true, PostMetaName::Meta->value => array( 'modules' => array( array( 'id' => 'm', 'title' => 'M', 'lesson_ids' => array( 10 ) ) ) ) ),
			10 => array( PostMetaName::Meta->value => array( 'steps' => array() ) ),
		);
		$guard = $this->guard( array( 1 => $this->post( 1, 'inf_courses' ), 10 => $this->post( 10, 'inf_lessons' ) ), $meta, true, true );
		$this->expectException( \InvalidArgumentException::class );
		$guard->assertMetadata( 10, PostMetaName::Meta->value, array( 'steps' => array( array( 'id' => 's', 'type' => 'assessment', 'payload' => array( 'ref' => 20 ) ) ) ) );
	}

	public function test_work_candidate_cannot_insert_manual_task_into_published_self_paced_course(): void {
		$meta = array(
			1 => array( PostMetaName::CourseSelfPacedEnabled->value => true, PostMetaName::Meta->value => array( 'modules' => array( array( 'id' => 'm', 'title' => 'M', 'lesson_ids' => array( 10 ) ) ) ) ),
			10 => array( PostMetaName::Meta->value => array( 'steps' => array( array( 'id' => 's', 'type' => 'work', 'payload' => array( 'ref' => 30 ) ) ) ) ),
			30 => array( PostMetaName::Meta->value => array( 'item_ids' => array() ) ),
		);
		$guard = $this->guard( array( 1 => $this->post( 1, 'inf_courses' ), 10 => $this->post( 10, 'inf_lessons' ), 30 => $this->post( 30, 'inf_works' ), 20 => $this->post( 20, 'inf_tasks' ) ), $meta, false, true );
		$this->expectException( \InvalidArgumentException::class );
		$guard->assertMetadata( 30, PostMetaName::Meta->value, array( 'item_ids' => array( 20 ) ) );
	}

	public function test_lesson_manager_refuses_before_any_post_write(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->expects( self::never() )->method( 'update' );
		$posts->expects( self::never() )->method( 'updateMeta' );
		$guard = $this->createMock( SelfPacedContentGuard::class );
		$guard->method( 'assertMetadata' )->willThrowException( new \InvalidArgumentException( 'Blocked' ) );
		$manager = new \Inc\Managers\Course\LessonManager( $posts, $guard );
		$this->expectException( \InvalidArgumentException::class );
		$manager->update( 10, new \Inc\DTO\Course\LessonDTO( 10, 'inf', 'Урок', array(), 1, 'publish' ) );
	}

}
