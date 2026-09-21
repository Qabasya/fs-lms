<?php

declare( strict_types=1 );

namespace Unit\Modules\ArticleBlocks;

use Inc\DTO\Subject\SubjectDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Modules\ArticleBlocks\Services\TaskSuggestions;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Task\TaskNumberService;
use PHPUnit\Framework\TestCase;

/**
 * Поиск в элементе «Задание» идёт в контексте статьи: её предмет и выбранный номер,
 * иначе «4000» находил и ОГЭ, и ЕГЭ, и 14000/24000.
 */
class TaskSuggestionsTest extends TestCase {

	/** @var array<int, array{0: string, 1: array}> Вызовы PostManager::search() */
	private array $searches = array();

	private TermManager $terms;

	protected function setUp(): void {
		parent::setUp();
		$_POST          = array();
		$this->searches = array();
		$this->terms    = $this->createMock( TermManager::class );
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	private function service(): TaskSuggestions {
		$posts = $this->createMock( PostManager::class );
		$posts->method( 'get' )->willReturnCallback(
			static fn( int $id ): ?\WP_Post => 50 === $id ? new \WP_Post( array( 'ID' => 50, 'post_type' => 'inf_ege_articles' ) ) : null
		);
		$posts->method( 'bundleChildExclusion' )->willReturn( array() );
		$posts->method( 'search' )->willReturnCallback( function ( string $type, array $opts ): array {
			$this->searches[] = array( $type, $opts );
			return array();
		} );

		$subjects = $this->createMock( SubjectRepository::class );
		$subjects->method( 'readAll' )->willReturn( array(
			new SubjectDTO( 'inf_ege', 'ЕГЭ' ),
			new SubjectDTO( 'inf_oge', 'ОГЭ' ),
		) );

		return new TaskSuggestions( $posts, $subjects, $this->terms, new TaskNumberService( $posts ) );
	}

	public function test_without_article_context_all_subjects_are_searched(): void {
		$this->service()->search( '4000' );

		self::assertSame( array( 'inf_ege_tasks', 'inf_oge_tasks' ), array_column( $this->searches, 0 ) );
		self::assertArrayNotHasKey( 'tax_query', $this->searches[0][1] );
	}

	public function test_article_subject_and_form_number_narrow_the_search(): void {
		$_POST = array(
			TaskSuggestions::POST_PARAM   => '50',
			TaskSuggestions::NUMBER_PARAM => array( 'inf_ege_4' ),
		);

		$this->service()->search( '4000' );

		self::assertSame( array( 'inf_ege_tasks' ), array_column( $this->searches, 0 ) );
		self::assertSame(
			array( array( 'taxonomy' => 'inf_ege_task_number', 'field' => 'name', 'terms' => '4' ) ),
			$this->searches[0][1]['tax_query']
		);
	}

	public function test_empty_form_number_searches_whole_subject(): void {
		$this->terms->expects( self::never() )->method( 'getPostTerms' );
		$_POST = array(
			TaskSuggestions::POST_PARAM   => '50',
			TaskSuggestions::NUMBER_PARAM => array( '' ),
		);

		$this->service()->search( '4000' );

		self::assertSame( array( 'inf_ege_tasks' ), array_column( $this->searches, 0 ) );
		self::assertArrayNotHasKey( 'tax_query', $this->searches[0][1] );
	}

	public function test_stored_number_is_used_when_form_is_absent(): void {
		$this->terms->method( 'getPostTerms' )->with( 50, 'inf_ege_task_number' )
			->willReturn( array( new \WP_Term( 9, '4', 'inf_ege_task_number' ) ) );
		$_POST = array( TaskSuggestions::POST_PARAM => '50' );

		$this->service()->search( '4000' );

		self::assertSame( '4', $this->searches[0][1]['tax_query'][0]['terms'] );
	}
}
