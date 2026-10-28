<?php

declare( strict_types=1 );

namespace Unit\Services\Exam;

use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Services\Exam\ExamReviewProjection;
use Inc\Services\Exam\ExamReviewViewService;
use Inc\Services\Exam\ExamScoreService;
use Inc\Services\Exam\GuestResultViewService;
use Inc\Services\Shared\CenterContactsService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

#[AllowMockObjectsWithoutExpectations]
class ExamReviewViewServiceTest extends TestCase {

	use ExamFixtures;

	/** @param array<string, mixed>|null $review */
	private function service( ?array $review, bool $hasParticipation = true ): ExamReviewViewService {
		$participations = $this->createMock( ExamParticipationRepository::class );
		$participations->method( 'find' )->willReturn( $hasParticipation ? ExamParticipationDTO::fromArray( array( 'id' => 80, 'event_id' => 3, 'participant_id' => 1, 'audience' => 'guest', 'transfer_allowed' => 0, 'version' => 1, 'created_at' => '2026-03-10 07:00:00', 'updated_at' => '2026-03-10 07:00:00' ) ) : null );
		$events = $this->createMock( ExamEventRepository::class );
		$events->method( 'find' )->willReturn( $this->examEvent() );
		$reviews = $this->createMock( ExamReviewProjection::class );
		$reviews->method( 'forParticipation' )->willReturn( $review );
		$scores = $this->createMock( ExamScoreService::class );
		$scores->method( 'caption' )->willReturn( 'Предварительный результат' );

		return new ExamReviewViewService( $reviews, $scores, $participations, $events );
	}

	public function test_missing_or_hidden_review_does_not_build_tasks_or_page_contacts(): void {
		self::assertNull( $this->service( null )->build( 80 ) );
		self::assertNull( $this->service( array(), false )->build( 80 ) );
		$data = $this->service( array( 'revealed' => false, 'tasks' => array( array( 'condition' => 'secret' ) ) ) )->build( 80 );
		self::assertSame( array( 'revealed' => false, 'event_title' => 'Пробный ЕГЭ' ), $data );
	}

	public function test_preliminary_review_preserves_manual_review_and_hides_answers_for_correct_tasks(): void {
		$data = $this->service( array(
			'revealed' => true, 'result' => array( 'final' => false ),
			'units' => array( array( 'status' => 'correct' ), array( 'status' => 'pending' ) ),
			'tasks' => array(
				array( 'verdict' => 'correct', 'correct' => 'answer' ),
				array( 'verdict' => 'pending', 'score' => 0, 'correct' => 'answer', 'solution' => array( 'text' => 'secret' ) ),
				array( 'verdict' => 'incorrect', 'correct' => 'answer' ),
			),
		) )->build( 80 );

		self::assertTrue( $data['preliminary'] );
		self::assertSame( array( 'correct' => 1, 'partial' => 0, 'wrong' => 0, 'pending' => 1 ), $data['counts'] );
		self::assertSame( '', $data['tasks'][0]['correct'] );
		self::assertNull( $data['tasks'][1]['score'] );
		self::assertSame( 'Проверяется преподавателем', $data['tasks'][1]['verdict_text'] );
		self::assertNull( $data['tasks'][1]['solution'] );
		self::assertSame( 'answer', $data['tasks'][2]['correct'] );
		self::assertArrayNotHasKey( 'contacts', $data );
	}

	public function test_final_review_and_guest_wrapper_add_contacts_only_to_guest_page(): void {
		$review = $this->service( array( 'revealed' => true, 'result' => array( 'final' => true ) ) );
		$data = $review->build( 80 );
		self::assertFalse( $data['preliminary'] );
		$contacts = $this->createMock( CenterContactsService::class );
		$contacts->expects( self::once() )->method( 'get' )->willReturn( array( 'phone' => 'test-phone' ) );
		$guestData = $this->createMock( \Inc\Services\Exam\GuestParticipantMaterializer::class );
		$guestData->method( 'nameOfParticipation' )->with( 80 )->willReturn( 'Иванов Пётр' );
		$guest = ( new GuestResultViewService( $review, $contacts, $guestData ) )->build( 80 );
		self::assertSame( array( 'phone' => 'test-phone' ), $guest['contacts'] );
		self::assertSame( 'Иванов Пётр', $guest['guest_name'], 'имя — для заголовка страницы гостя' );
		unset( $guest['contacts'], $guest['guest_name'] );
		self::assertSame( $data, $guest );
	}

	public function test_answer_time_and_duration_are_formatted_as_clock(): void {
		$data = $this->service( array(
			'revealed' => true, 'result' => array( 'final' => true ), 'duration_sec' => 3725,
			'tasks' => array(
				array( 'verdict' => 'correct', 'answer_first_sec' => 65, 'answer_last_sec' => 65 ),
				array( 'verdict' => 'correct', 'answer_first_sec' => 65, 'answer_last_sec' => 3700 ),
				array( 'verdict' => 'unanswered' ),
			),
		) )->build( 80 );

		self::assertSame( '01:02:05', $data['duration_text'] );
		self::assertSame( 'Ответ дан: 00:01:05', $data['tasks'][0]['answer_time']['text'] );
		self::assertSame( 'Ответ дан: 00:01:05 / 01:01:40', $data['tasks'][1]['answer_time']['text'] );
		self::assertNull( $data['tasks'][2]['answer_time'] );
	}
}
