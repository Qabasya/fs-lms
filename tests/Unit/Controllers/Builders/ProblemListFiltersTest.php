<?php

declare( strict_types=1 );

namespace Unit\Controllers\Builders;

use Inc\Controllers\Builders\ProblemListFilters;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Course\BankUsageIndex;
use Inc\Services\Course\ContentUsageService;
use PHPUnit\Framework\TestCase;

/**
 * Задача: фильтр и колонка «Предмет» в банке задач (.docs/Tasks.md, 2026-08-26).
 */
class ProblemListFiltersTest extends TestCase {

	private BankUsageIndex&\PHPUnit\Framework\MockObject\MockObject      $index;
	private ContentUsageService&\PHPUnit\Framework\MockObject\MockObject $usage;
	private PostManager&\PHPUnit\Framework\MockObject\MockObject         $posts;
	private SubjectRepository&\PHPUnit\Framework\MockObject\MockObject   $subjects;
	private ProblemListFilters $filters;

	protected function setUp(): void {
		parent::setUp();
		$this->index    = $this->createMock( BankUsageIndex::class );
		$this->usage    = $this->createMock( ContentUsageService::class );
		$this->posts    = $this->createMock( PostManager::class );
		$this->subjects = $this->createMock( SubjectRepository::class );
		$this->index->method( 'consumerOptions' )->willReturn( array() );
		$this->posts->method( 'search' )->willReturn( array() );

		$this->filters = new ProblemListFilters( $this->index, $this->usage, $this->posts, $this->subjects );
	}

	public function test_subject_select_null_when_no_subjects(): void {
		$this->subjects->method( 'readAll' )->willReturn( array() );

		self::assertNull( $this->filters->data()['subject'] );
	}

	public function test_subject_select_includes_no_subject_option_and_all_subjects(): void {
		$this->subjects->method( 'readAll' )->willReturn( array(
			new SubjectDTO( key: 'inf_ege', name: 'Информатика' ),
			new SubjectDTO( key: 'python_prog', name: 'Программирование на Python' ),
		) );

		$subject = $this->filters->data()['subject'];

		self::assertNotNull( $subject );
		self::assertSame( 'fs_problem_subject', $subject['name'] );
		self::assertSame( 'Все предметы', $subject['all_label'] );
		self::assertSame(
			array( 'none' => 'Без предмета', 'inf_ege' => 'Информатика', 'python_prog' => 'Программирование на Python' ),
			$subject['options']
		);
	}

	public function test_subject_select_reads_selected_from_get(): void {
		$this->subjects->method( 'readAll' )->willReturn( array(
			new SubjectDTO( key: 'inf_ege', name: 'Информатика' ),
		) );
		$_GET['fs_problem_subject'] = 'inf_ege';

		self::assertSame( 'inf_ege', $this->filters->data()['subject']['selected'] );

		unset( $_GET['fs_problem_subject'] );
	}
}
