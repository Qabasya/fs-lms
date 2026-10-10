<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Article\ArticleCardDTO;
use Inc\DTO\Subject\SubjectLinksDTO;
use Inc\Services\Course\PublicCourseService;
use Inc\Services\Exam\GuestResultSidebarService;
use Inc\Services\Subject\ArticleService;
use Inc\Services\Subject\SubjectPagesService;
use PHPUnit\Framework\TestCase;

/**
 * Подбор статей сайдбара результата: ошибочные номера по кругу, добор детерминированными «случайными», пустой ОГЭ.
 */
class GuestResultSidebarServiceTest extends TestCase {

	private function card( int $id, string $slug ): ArticleCardDTO {
		return new ArticleCardDTO( id: $id, title: "A$id", url: "/a$id/", terms: array( 'inf_ege_task_number' => array( $slug ) ) );
	}

	/** @param list<ArticleCardDTO> $cards */
	private function service( array $cards ): GuestResultSidebarService {
		$articles = $this->createMock( ArticleService::class );
		$articles->method( 'getCatalogCards' )->willReturn( $cards );
		$courses = $this->createMock( PublicCourseService::class );
		$courses->method( 'getSidebarCourses' )->willReturn( array() );
		$pages = $this->createMock( SubjectPagesService::class );
		$pages->method( 'links' )->willReturn( new SubjectLinksDTO( subject: '/inf/', articles: '/inf/articles/' ) );

		return new GuestResultSidebarService( $articles, $courses, $pages );
	}

	private function unit( string $number, string $status, float $score, float $max = 1.0 ): array {
		return array( 'number' => $number, 'status' => $status, 'score' => $score, 'max' => $max );
	}

	/** @return list<int> */
	private function ids( array $result ): array {
		return array_map( static fn( array $a ): int => $a['id'], $result['articles'] );
	}

	public function test_articles_follow_errors_round_robin_from_lowest_score(): void {
		$cards = array(
			$this->card( 1, 'inf_ege_5' ), $this->card( 2, 'inf_ege_5' ),
			$this->card( 3, 'inf_ege_9' ), $this->card( 4, 'inf_ege_9' ),
			$this->card( 5, 'inf_ege_2' ),
		);
		$units = array(
			$this->unit( '5', 'incorrect', 1.0, 2.0 ),
			$this->unit( '9', 'unanswered', 0.0, 2.0 ),
			$this->unit( '2', 'correct', 1.0 ),
		);

		$result = $this->service( $cards )->build( 'inf_ege', $units, 7 );

		self::assertSame( array( 3, 1, 4, 2 ), $this->ids( $result ), 'Сначала №9 (0 баллов), потом №5; затем по второй статье.' );
	}

	public function test_pending_is_not_an_error_and_shortfall_is_filled_deterministically(): void {
		$cards = array( $this->card( 1, 'inf_ege_5' ), $this->card( 2, 'inf_ege_6' ), $this->card( 3, 'inf_ege_7' ), $this->card( 4, 'inf_ege_8' ), $this->card( 5, 'inf_ege_9' ) );
		$units = array( $this->unit( '6', 'pending', 0.0 ), $this->unit( '5', 'partial', 1.0, 2.0 ) );

		$first  = $this->ids( $this->service( $cards )->build( 'inf_ege', $units, 7 ) );
		$second = $this->ids( $this->service( $cards )->build( 'inf_ege', $units, 7 ) );

		self::assertSame( 1, $first[0] );
		self::assertCount( 4, $first );
		self::assertSame( $first, $second, 'Один и тот же набор при обновлении страницы.' );
	}

	public function test_no_errors_gives_four_random_articles(): void {
		$cards = array_map( fn( int $i ): ArticleCardDTO => $this->card( $i, 'inf_ege_' . $i ), range( 1, 8 ) );

		$result = $this->service( $cards )->build( 'inf_ege', array( $this->unit( '1', 'correct', 1.0 ) ), 3 );

		self::assertCount( 4, $result['articles'] );
		self::assertSame( '/inf/articles/', $result['articles_url'] );
	}

	public function test_subject_without_articles_gives_empty_block(): void {
		$result = $this->service( array() )->build( 'inf_oge', array( $this->unit( '1', 'incorrect', 0.0 ) ), 3 );

		self::assertSame( array(), $result['articles'] );
	}

	public function test_empty_subject_key_gives_nothing(): void {
		self::assertSame( array(), $this->service( array() )->build( '', array(), 1 )['articles'] );
	}
}
