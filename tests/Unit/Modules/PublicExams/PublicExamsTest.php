<?php

declare( strict_types=1 );

namespace Unit\Modules\PublicExams;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Enums\Wp\SubjectPageType;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Modules\PublicExams\Callbacks\PublicResultCallbacks;
use Inc\Modules\PublicExams\Config\PublicExamsConfig;
use Inc\Modules\PublicExams\PublicExamsModule;
use Inc\Modules\PublicExams\Services\PublicExamCatalog;
use Inc\Modules\PublicExams\Services\PublicExamPolicy;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\SubjectPagesService;
use PHPUnit\Framework\TestCase;

/**
 * Публичные экзамены: решение «открыт всем» (PublicExamPolicy), каталог по годам,
 * требование года при публикации и состав разделов лендинга.
 */
class PublicExamsTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta = array();

	private PublicExamPolicy $policy;

	protected function setUp(): void {
		parent::setUp();

		$posts = $this->createMock( PostManager::class );
		$posts->method( 'taskMeta' )->willReturnCallback( fn( int $id ): array => $this->meta[ $id ] ?? array() );
		$this->policy = new PublicExamPolicy( $posts );
	}

	private function exam( int $id, AssessmentKind $kind = AssessmentKind::EgeComputer, string $status = 'publish', string $title = 'Экзамен' ): AssessmentDTO {
		return new AssessmentDTO(
			id: $id, subjectKey: 'inf', title: $title, taskIds: array( 1 ),
			timeLimit: 235, attemptsAllowed: 1, passScore: 6.0,
			scoringPolicy: ScoringPolicy::Highest, status: $status,
			kind: $kind, taskPoints: array(), scoreMap: array(),
		);
	}

	public function test_only_published_public_ege_is_open(): void {
		$this->meta[1] = array( 'is_public' => 1, 'exam_year' => '2026' );
		$this->meta[2] = array( 'is_public' => 0 );
		$this->meta[3] = array( 'is_public' => 1 );

		self::assertTrue( $this->policy->isPublic( $this->exam( 1 ) ) );
		self::assertFalse( $this->policy->isPublic( $this->exam( 2 ) ), 'флажок выключен' );
		self::assertFalse( $this->policy->isPublic( $this->exam( 3, status: 'draft' ) ), 'черновик' );
		self::assertFalse( $this->policy->isPublic( $this->exam( 3, AssessmentKind::OgeComputer ) ), 'ОГЭ' );
		self::assertFalse( $this->policy->isPublic( $this->exam( 3, AssessmentKind::Control ) ), 'контрольная' );
		self::assertFalse( $this->policy->isPublic( $this->exam( 99 ) ), 'меты нет' );
	}

	public function test_year_must_be_four_digits(): void {
		$this->meta[1] = array( 'exam_year' => '2026' );
		$this->meta[2] = array( 'exam_year' => '26' );
		$this->meta[3] = array( 'exam_year' => 'abcd' );

		self::assertSame( '2026', $this->policy->year( 1 ) );
		self::assertSame( '', $this->policy->year( 2 ) );
		self::assertSame( '', $this->policy->year( 3 ) );
		self::assertSame( '', $this->policy->year( 4 ) );
	}

	public function test_catalog_groups_public_exams_by_year_newest_first(): void {
		$this->meta = array(
			1 => array( 'is_public' => 1, 'exam_year' => '2025' ),
			2 => array( 'is_public' => 1, 'exam_year' => '2026' ),
			3 => array( 'is_public' => 1, 'exam_year' => '2026' ),
			4 => array( 'is_public' => 0, 'exam_year' => '2026' ), // не публичный
			5 => array( 'is_public' => 1 ),                        // без года
		);

		$assessments = $this->createMock( AssessmentManager::class );
		$assessments->method( 'getBankBySubject' )->willReturn( array(
			$this->exam( 1, title: 'Демо 2025' ),
			$this->exam( 2, title: 'Досрочная' ),
			$this->exam( 3, title: 'Основная волна' ),
			$this->exam( 4, title: 'Скрытый' ),
			$this->exam( 5, title: 'Без года' ),
		) );

		$groups = ( new PublicExamCatalog( $assessments, $this->policy ) )->groups( 'inf' );

		self::assertSame( array( '2026', '2025' ), array_map( 'strval', array_keys( $groups ) ) );
		self::assertSame( array( 'Досрочная', 'Основная волна' ), array_column( $groups['2026'], 'title' ) );
		self::assertSame( array( 'Демо 2025' ), array_column( $groups['2025'], 'title' ) );
	}

	private function module(): PublicExamsModule {
		return new PublicExamsModule(
			$this->createMock( PublicExamsConfig::class ),
			$this->policy,
			$this->createMock( PublicExamCatalog::class ),
			$this->createMock( PublicResultCallbacks::class ),
			$this->createMock( SubjectPagesService::class ),
			$this->createMock( SubjectRepository::class ),
		);
	}

	public function test_public_ege_without_year_cannot_be_published(): void {
		$module = $this->module();

		self::assertNotNull( $module->requireYear( null, 1, array( 'kind' => 'ege_computer', 'is_public' => '1', 'exam_year' => '' ) ) );
		self::assertNotNull( $module->requireYear( null, 1, array( 'kind' => 'ege_computer', 'is_public' => '1', 'exam_year' => '26' ) ) );
		self::assertNull( $module->requireYear( null, 1, array( 'kind' => 'ege_computer', 'is_public' => '1', 'exam_year' => '2026' ) ) );
	}

	public function test_year_not_required_when_not_public_or_not_ege(): void {
		$module = $this->module();

		self::assertNull( $module->requireYear( null, 1, array( 'kind' => 'ege_computer', 'is_public' => '0', 'exam_year' => '' ) ) );
		self::assertNull( $module->requireYear( null, 1, array( 'kind' => 'control', 'is_public' => '1', 'exam_year' => '' ) ) );
	}

	public function test_earlier_error_is_passed_through(): void {
		self::assertSame( 'Ошибка комплектности', $this->module()->requireYear( 'Ошибка комплектности', 1, array() ) );
	}

	public function test_exams_section_is_only_in_landing_when_module_enabled(): void {
		self::assertNotContains( SubjectPageType::Exams, SubjectPageType::forSubject( true ) );
		self::assertContains( SubjectPageType::Exams, SubjectPageType::forSubject( true, true ) );
		// Экзамены строятся из заданий банка — у предмета без банка раздела нет.
		self::assertNotContains( SubjectPageType::Exams, SubjectPageType::forSubject( false, true ) );
	}

	public function test_hide_intro_flag_is_read_from_meta(): void {
		$post = fs_test_seed_post( array( 'post_type' => 'inf_assessments', 'post_title' => 'E' ) );

		self::assertTrue( AssessmentDTO::fromPost( $post, array( 'hide_intro' => '1' ) )->hideIntro );
		self::assertFalse( AssessmentDTO::fromPost( $post, array( 'hide_intro' => '0' ) )->hideIntro );
		self::assertFalse( AssessmentDTO::fromPost( $post, array() )->hideIntro, 'старые экзамены — экраны на месте' );
	}
}
