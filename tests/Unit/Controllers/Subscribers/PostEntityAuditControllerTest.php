<?php

declare(strict_types=1);

namespace Unit\Controllers\Subscribers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Controllers\Subscribers\PostEntityAuditController;
use Inc\Enums\Log\LogEvent;
use PHPUnit\Framework\TestCase;

/**
 * Журнал «Действия»: правка опубликованного задания и задачи банка без смены статуса тоже записывается.
 */
class PostEntityAuditControllerTest extends TestCase {

	/** @var list<LogEvent> */
	private array $events = array();
	private PostEntityAuditController $controller;

	protected function setUp(): void {
		parent::setUp();
		$bus = $this->createMock( LogEventDispatcherInterface::class );
		$bus->method( 'dispatch' )->willReturnCallback( function ( LogEvent $event ): void {
			$this->events[] = $event;
		} );
		$this->controller = new PostEntityAuditController( $bus );
	}

	private function post( string $type, string $status ): \WP_Post {
		return new \WP_Post( array( 'ID' => 7, 'post_type' => $type, 'post_status' => $status, 'post_title' => 'Задача' ) );
	}

	public function test_edit_of_published_task_without_status_change_is_logged(): void {
		$this->controller->onAfterInsertPost( 7, $this->post( 'inf_tasks', 'publish' ), true, $this->post( 'inf_tasks', 'publish' ) );

		self::assertSame( array( LogEvent::TaskUpdated ), $this->events );
	}

	public function test_edit_of_bank_problem_is_logged_as_problem_event(): void {
		$this->controller->onAfterInsertPost( 7, $this->post( 'fs_lms_problems', 'publish' ), true, $this->post( 'fs_lms_problems', 'publish' ) );
		$this->controller->onTransitionPostStatus( 'trash', 'publish', $this->post( 'fs_lms_problems', 'trash' ) );

		self::assertSame( array( LogEvent::ProblemUpdated, LogEvent::ProblemDeleted ), $this->events );
	}

	public function test_status_change_and_creation_are_left_to_the_transition_hook(): void {
		$this->controller->onAfterInsertPost( 7, $this->post( 'inf_tasks', 'publish' ), true, $this->post( 'inf_tasks', 'draft' ) );
		$this->controller->onAfterInsertPost( 7, $this->post( 'inf_tasks', 'publish' ), false, null );

		self::assertSame( array(), $this->events );
	}
}
