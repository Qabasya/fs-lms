<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Assessment;

use Inc\Callbacks\Assessment\ScoreMapCallbacks;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Assessment\ScoreMapParser;
use PHPUnit\Framework\TestCase;

class ScoreMapCallbacksTest extends TestCase {

	private AssessmentManager $assessments;
	private PostManager       $posts;
	private ScoreMapCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->assessments = $this->createMock( AssessmentManager::class );
		$this->posts       = $this->createMock( PostManager::class );
		$this->cb          = new ScoreMapCallbacks( new ScoreMapParser(), $this->assessments, $this->posts );
	}

	private function assessment( int $id, AssessmentKind $kind, array $scoreMap ): AssessmentDTO {
		return new AssessmentDTO(
			id:              $id,
			subjectKey:      'inf_ege',
			title:           'Работа ' . $id,
			taskIds:         array(),
			timeLimit:       0,
			attemptsAllowed: 1,
			passScore:       0.0,
			scoringPolicy:   ScoringPolicy::Highest,
			status:          'publish',
			kind:            $kind,
			taskPoints:      array(),
			scoreMap:        $scoreMap,
		);
	}

	private function post( int $id, string $postType = 'inf_ege_assessments' ): \WP_Post {
		$post            = new \WP_Post();
		$post->ID        = $id;
		$post->post_type = $postType;
		return $post;
	}

	public function test_sources_exclude_current_non_ege_and_empty_maps(): void {
		$this->posts->method( 'get' )->willReturn( $this->post( 10 ) );
		$this->assessments->method( 'getBankBySubject' )->willReturn( array(
			$this->assessment( 10, AssessmentKind::Ege, array( 0 => 0, 34 => 100 ) ),        // текущая — исключается
			$this->assessment( 11, AssessmentKind::Control, array( 0 => 0, 5 => 5 ) ),       // не ЕГЭ — исключается
			$this->assessment( 12, AssessmentKind::Ege, array() ),                           // пустая шкала — исключается
			$this->assessment( 13, AssessmentKind::Ege, array( 0 => 0, 1 => 6, 34 => 100 ) ), // годится
		) );

		$_POST = array( 'assessment_id' => '10' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxGetScoreMapSources() );

		self::assertTrue( $response->success );
		self::assertCount( 1, $response->payload['sources'] );
		self::assertSame( 13, $response->payload['sources'][0]['id'] );
		self::assertSame( 3, $response->payload['sources'][0]['pairs'] );
		self::assertSame( '0–34 → 0–100', $response->payload['sources'][0]['range'] );
	}

	public function test_sources_reject_non_assessment_post(): void {
		$this->posts->method( 'get' )->willReturn( $this->post( 10, 'inf_ege_tasks' ) );
		$this->assessments->expects( $this->never() )->method( 'getBankBySubject' );

		$_POST = array( 'assessment_id' => '10' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetScoreMapSources() )->success );
	}

	public function test_copy_writes_source_map_into_target_meta(): void {
		$map = array( 0 => 0, 1 => 6, 34 => 100 );

		$this->assessments->method( 'get' )->willReturnMap( array(
			array( 1, $this->assessment( 1, AssessmentKind::Ege, $map ) ),
			array( 2, $this->assessment( 2, AssessmentKind::Ege, array() ) ),
		) );
		$this->posts->method( 'get' )->willReturn( $this->post( 2 ) );
		$this->posts->method( 'getMeta' )->willReturn( array( 'kind' => 'ege' ) );

		$this->posts->expects( $this->once() )
			->method( 'updateMeta' )
			->with( 2, 'fs_lms_meta', array( 'kind' => 'ege', 'score_map' => $map ) );

		$_POST = array( 'source_assessment_id' => '1', 'target_assessment_id' => '2' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxCopyScoreMap() );

		self::assertTrue( $response->success );
		self::assertSame( $map, $response->payload['map'] );
	}

	public function test_copy_rejects_source_with_empty_map(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( 1, AssessmentKind::Ege, array() ) );
		$this->posts->expects( $this->never() )->method( 'updateMeta' );

		$_POST = array( 'source_assessment_id' => '1', 'target_assessment_id' => '2' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxCopyScoreMap() )->success );
	}

	public function test_copy_rejects_non_ege_target(): void {
		$this->assessments->method( 'get' )->willReturnMap( array(
			array( 1, $this->assessment( 1, AssessmentKind::Ege, array( 0 => 0 ) ) ),
			array( 2, $this->assessment( 2, AssessmentKind::Control, array() ) ),
		) );
		$this->posts->expects( $this->never() )->method( 'updateMeta' );

		$_POST = array( 'source_assessment_id' => '1', 'target_assessment_id' => '2' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxCopyScoreMap() )->success );
	}

	public function test_parse_returns_pairs_from_pasted_text(): void {
		$_POST = array( 'text' => "первичный\tвторичный\n0\t0\n1\t6\n34\t100" );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxParseScoreMap() );

		self::assertTrue( $response->success );
		self::assertSame( array( 0 => 0, 1 => 6, 34 => 100 ), $response->payload['map'] );
	}
}
