<?php declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamFormatDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Exam\ExamDirection;
use Inc\Services\Exam\ExamFormatRegistry;
use PHPUnit\Framework\TestCase;

class ExamFormatRegistryTest extends TestCase {

	private ExamFormatRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_filter_returns'] = array();
		$this->registry                    = new ExamFormatRegistry();
	}

	public function test_all_returns_formats_keyed_by_kind(): void {
		$formats = [
			new ExamFormatDTO(
				kind          : AssessmentKind::EgeComputer,
				direction     : ExamDirection::Ege,
				unitCount     : 27,
				primaryMax    : 29,
				secondaryMax  : 100,
				gradeMax      : 0,
				durationMinutes: 235,
				scale         : [],
				unitMaxScores : [],
			),
			new ExamFormatDTO(
				kind          : AssessmentKind::OgeComputer,
				direction     : ExamDirection::Oge,
				unitCount     : 16,
				primaryMax    : 21,
				secondaryMax  : null,
				gradeMax      : 5,
				durationMinutes: 150,
				scale         : [],
				unitMaxScores : [],
			),
		];

		$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats'] = $formats;

		$all = $this->registry->all();

		$this->assertCount( 2, $all );
		$this->assertArrayHasKey( 'ege_computer', $all );
		$this->assertArrayHasKey( 'oge_computer', $all );
	}

	public function test_all_ignores_non_dto_values(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats'] = [
			'x',
			[],
			new ExamFormatDTO(
				kind          : AssessmentKind::EgeComputer,
				direction     : ExamDirection::Ege,
				unitCount     : 27,
				primaryMax    : 29,
				secondaryMax  : 100,
				gradeMax      : 0,
				durationMinutes: 235,
				scale         : [],
				unitMaxScores : [],
			),
		];

		$all = $this->registry->all();

		$this->assertCount( 1, $all );
	}

	public function test_unit_count_is_zero_when_module_disabled(): void {
		$count = $this->registry->unitCount( AssessmentKind::EgeComputer );

		$this->assertSame( 0, $count );
	}

	public function test_for_direction_filters_by_direction(): void {
		$formats = [
			new ExamFormatDTO(
				kind          : AssessmentKind::EgeComputer,
				direction     : ExamDirection::Ege,
				unitCount     : 27,
				primaryMax    : 29,
				secondaryMax  : 100,
				gradeMax      : 0,
				durationMinutes: 235,
				scale         : [],
				unitMaxScores : [],
			),
			new ExamFormatDTO(
				kind          : AssessmentKind::OgeComputer,
				direction     : ExamDirection::Oge,
				unitCount     : 16,
				primaryMax    : 21,
				secondaryMax  : null,
				gradeMax      : 5,
				durationMinutes: 150,
				scale         : [],
				unitMaxScores : [],
			),
		];

		$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats'] = $formats;

		$egeFormats = $this->registry->forDirection( ExamDirection::Ege );

		$this->assertCount( 1, $egeFormats );
		$this->assertSame( ExamDirection::Ege, $egeFormats[0]->direction );
	}
}
