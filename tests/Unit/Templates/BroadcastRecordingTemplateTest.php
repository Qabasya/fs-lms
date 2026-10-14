<?php

declare( strict_types=1 );

namespace Unit\Templates;

use PHPUnit\Framework\TestCase;

class BroadcastRecordingTemplateTest extends TestCase {

	private function render( string $videoUrl, bool $teacher = true ): string {
		$step = array( 'type' => 'broadcast', 'key' => 'broadcast', 'title' => 'Запись занятия' );
		$render = array( 'phase' => 'after', 'video_url' => $videoUrl, 'record_link' => '' );
		$view = array( 'group_lesson_id' => 5 );
		$is_teacher = $teacher;
		$is_preview = false;
		$edit_url = '';
		ob_start();
		include __DIR__ . '/../../../templates/frontend/lesson-player/partials/step-broadcast.php';
		return (string) ob_get_clean();
	}

	public function test_teacher_has_player_without_manual_link_when_recording_is_available(): void {
		$html = $this->render( 'https://s3.example.com/bucket/recording.mp4?signature=test' );
		self::assertStringNotContainsString( 'data-recording-form', $html );
		foreach ( array( 'data-video-root', 'data-vp-volume', 'data-vp-mute', 'data-vp-speed', 'data-vp-pip' ) as $control ) {
			self::assertStringContainsString( $control, $html );
		}
	}

	public function test_teacher_can_add_link_without_recording(): void {
		self::assertStringContainsString( 'data-recording-form', $this->render( '' ) );
	}

	public function test_student_has_no_recording_form(): void {
		self::assertStringNotContainsString( 'data-recording-form', $this->render( '', false ) );
	}
}
