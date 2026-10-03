<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Contracts\ServiceInterface;
use Inc\Repositories\WPDBRepositories\ExamOutboxEventRepository;

class ExamEventDispatcher implements ServiceInterface {

	private ExamOutboxEventRepository $repo;

	public function __construct( ?ExamOutboxEventRepository $repo = null ) {
		$this->repo = $repo ?? new ExamOutboxEventRepository();
	}

	public function register(): void {
		add_action( 'wp_loaded', [ $this, 'scheduleIfNeeded' ] );
		add_action( 'fs_lms_exam_dispatch_minute', [ $this, 'dispatch' ] );
	}

	public function scheduleIfNeeded(): void {
		if ( ! wp_next_scheduled( 'fs_lms_exam_dispatch_minute' ) ) {
			wp_schedule_event( time(), 'every_15_minutes', 'fs_lms_exam_dispatch_minute' );
		}
	}

	public function dispatch(): void {
		$pending = $this->repo->findPending();
		foreach ( $pending as $event ) {
			$this->processEvent( $event );
		}
	}

	private function processEvent( \Inc\DTO\Exam\ExamOutboxEventDTO $event ): void {
		$this->repo->update( $event->id, [
			'processed_at' => current_time( 'mysql', true ),
			'attempts'     => $event->attempts + 1,
		] );
	}
}
