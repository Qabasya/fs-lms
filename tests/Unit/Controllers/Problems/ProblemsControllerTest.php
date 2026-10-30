<?php

declare( strict_types=1 );

namespace Unit\Controllers\Problems;

use Inc\Controllers\Builders\ProblemListFilters;
use Inc\Controllers\Problems\ProblemsController;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Registrars\ProblemBankRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Task\TaskPublishGuard;
use Inc\Services\Task\TaskPublishValidator;
use Inc\Services\Template\TemplateRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Регрессия: валидация публикации задачи банка читала только $_POST — быстрое и
 * массовое редактирование, а также программные вставки (импорт пакета) не шлют
 * форму метабокса, валидатор сваливался на «Стандартный» шаблон и откатывал
 * опубликованную задачу в черновик («Заполните «Условие задания»»).
 */
class ProblemsControllerTest extends TestCase {

	private TaskPublishValidator $validator;
	private PostManager          $posts;
	private SubjectRepository    $subjects;
	private ProblemsController   $controller;

	protected function setUp(): void {
		parent::setUp();
		$_POST = array();

		$this->validator = $this->createMock( TaskPublishValidator::class );
		$this->posts     = $this->createMock( PostManager::class );
		$this->subjects  = $this->createMock( SubjectRepository::class );

		$this->controller = new ProblemsController(
			$this->createMock( TemplateRegistry::class ),
			$this->posts,
			$this->validator,
			new TaskPublishGuard(),
			$this->createMock( ProblemBankRegistrar::class ),
			$this->createMock( ProblemListFilters::class ),
			$this->subjects,
		);
	}

	/** @return array<string, mixed> */
	private function postData( string $status = 'publish' ): array {
		return array(
			'post_status' => $status,
			'post_type'   => 'fs_lms_problems',
			'post_title'  => 'Задача',
		);
	}

	public function test_quick_edit_uses_stored_meta_and_template(): void {
		$stored = array( 'task_19_condition' => '<p>Условие</p>', 'task_code' => 'print(1)' );

		$this->posts->method( 'getMeta' )->willReturnMap( array(
			array( 15, 'fs_lms_meta', true, $stored ),
			array( 15, 'fs_lms_template_type', true, 'triple_task' ),
		) );

		$this->validator->expects( $this->once() )
			->method( 'getSoftError' )
			->with( $stored, 'triple_task' )
			->willReturn( null );

		$data = $this->controller->validateBeforePublish( $this->postData(), array( 'ID' => 15 ) );

		self::assertSame( 'publish', $data['post_status'] );
	}

	public function test_programmatic_insert_without_form_is_not_validated(): void {
		$this->validator->expects( $this->never() )->method( 'getSoftError' );

		$data = $this->controller->validateBeforePublish( $this->postData(), array( 'ID' => 0 ) );

		self::assertSame( 'publish', $data['post_status'] );
	}

	public function test_form_data_wins_over_stored_state(): void {
		$this->posts->expects( $this->never() )->method( 'getMeta' );

		$_POST = array(
			'fs_lms_meta'          => array( 'task_condition' => '' ),
			'fs_lms_template_type' => 'standard_task',
		);

		$this->validator->expects( $this->once() )
			->method( 'getSoftError' )
			->with( array( 'task_condition' => '' ), 'standard_task' )
			->willReturn( 'Заполните «Условие задания».' );

		$data = $this->controller->validateBeforePublish( $this->postData(), array( 'ID' => 15 ) );

		self::assertSame( 'draft', $data['post_status'] );
	}

	public function test_other_post_types_are_untouched(): void {
		$this->validator->expects( $this->never() )->method( 'getSoftError' );

		$data = $this->controller->validateBeforePublish(
			array( 'post_status' => 'publish', 'post_type' => 'page', 'post_title' => 'Стр.' ),
			array( 'ID' => 15 )
		);

		self::assertSame( 'publish', $data['post_status'] );
	}

	/**
	 * Регрессия (.docs/Tasks.md, задача A): save_post_fs_lms_problems срабатывает
	 * для ID, отличного от post_ID формы — программная вставка child'а связки
	 * ВНУТРИ сохранения parent'а (тот же запрос, тот же $_POST). Мета child'а не
	 * должна писаться из формы parent'а.
	 */
	public function test_save_template_type_ignores_reentrant_call_for_other_post_id(): void {
		$_POST = array(
			'post_ID'              => '15',
			'fs_lms_meta_nonce'    => 'nonce',
			'fs_lms_template_type' => 'triple_task',
		);

		$this->posts->expects( $this->never() )->method( 'updateMeta' );

		$this->controller->saveTemplateType( 201 );
	}

	public function test_save_subject_fields_ignores_reentrant_call_for_other_post_id(): void {
		$_POST = array(
			'post_ID'                 => '15',
			'fs_lms_meta_nonce'       => 'nonce',
			'fs_lms_bank_task_subject' => 'inf',
		);

		$this->posts->expects( $this->never() )->method( 'updateMeta' );

		$this->controller->saveSubjectFields( 201 );
	}

	/* ── Колонка «Предмет» в банке задач (.docs/Tasks.md, 2026-08-26) ──────── */

	public function test_add_columns_places_subject_between_title_and_taxonomy(): void {
		$order = array_keys( $this->controller->addColumns( array(
			'cb'                     => '<input type="checkbox">',
			'title'                  => 'Название',
			'taxonomy-problem_tag'   => 'Тематика',
			'author'                 => 'Автор',
			'date'                   => 'Дата',
		) ) );

		self::assertSame(
			array( 'cb', 'title', 'subject', 'number', 'taxonomy-problem_tag', 'template_type', 'author', 'date' ),
			$order
		);
	}

	public function test_render_column_number_shows_dash_when_none_selected(): void {
		$this->posts->method( 'getMeta' )->willReturn( '' );

		ob_start();
		$this->controller->renderColumn( 'number', 15 );
		$out = ob_get_clean();

		self::assertSame( '—', $out );
	}

	public function test_render_column_number_shows_stored_value(): void {
		$this->posts->method( 'getMeta' )->willReturn( '4' );

		ob_start();
		$this->controller->renderColumn( 'number', 15 );
		$out = ob_get_clean();

		self::assertSame( '4', $out );
	}

	public function test_render_column_subject_shows_dash_when_none_selected(): void {
		$this->posts->method( 'getMeta' )->willReturn( '' );

		ob_start();
		$this->controller->renderColumn( 'subject', 15 );
		$out = ob_get_clean();

		self::assertSame( '—', $out );
	}

	public function test_render_column_subject_resolves_name_from_key(): void {
		$this->posts->method( 'getMeta' )->willReturn( 'inf_ege' );
		$this->subjects->method( 'getByKey' )->with( 'inf_ege' )->willReturn(
			new SubjectDTO( key: 'inf_ege', name: 'Информатика' )
		);

		ob_start();
		$this->controller->renderColumn( 'subject', 15 );
		$out = ob_get_clean();

		self::assertSame( 'Информатика', $out );
	}

	/** Черновик, созданный из конструктора «Опроса»: пометка типа работы в мете, статус — черновик. */
	private function surveyDraft( int $id = 201, string $status = 'draft', string $workType = 'survey' ): void {
		fs_test_seed_post( array( 'ID' => $id, 'post_type' => 'fs_lms_problems', 'post_status' => $status ) );
		$this->posts->method( 'getMeta' )->willReturnCallback(
			static fn( int $postId, string $key ): mixed => 'fs_lms_bank_task_work_type' === $key ? $workType : ''
		);
	}

	private function formFor( int $id, string $template ): void {
		$_POST = array(
			'post_ID'              => (string) $id,
			'fs_lms_meta_nonce'    => 'nonce',
			'fs_lms_template_type' => $template,
		);
	}

	public function test_survey_draft_rejects_template_outside_allowed_list(): void {
		$this->surveyDraft();
		$this->formFor( 201, 'code_task' );

		$this->posts->expects( $this->never() )->method( 'updateMeta' );

		$this->controller->saveTemplateType( 201 );
	}

	public function test_survey_draft_accepts_allowed_template(): void {
		$this->surveyDraft();
		$this->formFor( 201, 'choice_task' );

		$this->posts->expects( $this->once() )->method( 'updateMeta' )->with( 201, 'fs_lms_template_type', 'choice_task' );

		$this->controller->saveTemplateType( 201 );
	}

	/** Опубликованная задача больше не ограничена пометкой: её можно сохранить с любым шаблоном. */
	public function test_published_task_ignores_survey_scope(): void {
		$this->surveyDraft( 202, 'publish' );
		$this->formFor( 202, 'code_task' );

		$this->posts->expects( $this->once() )->method( 'updateMeta' )->with( 202, 'fs_lms_template_type', 'code_task' );

		$this->controller->saveTemplateType( 202 );
	}

	/** Черновик из конструктора практики: опросные шаблоны ему недоступны, обычные — да. */
	public function test_practice_draft_rejects_survey_only_template(): void {
		$this->surveyDraft( 203, 'draft', 'practice' );
		$this->formFor( 203, 'choice_task' );

		$this->posts->expects( $this->never() )->method( 'updateMeta' );

		$this->controller->saveTemplateType( 203 );
	}

	public function test_practice_draft_accepts_regular_template(): void {
		$this->surveyDraft( 203, 'draft', 'practice' );
		$this->formFor( 203, 'code_task' );

		$this->posts->expects( $this->once() )->method( 'updateMeta' )->with( 203, 'fs_lms_template_type', 'code_task' );

		$this->controller->saveTemplateType( 203 );
	}

	/** Новый черновик из конструктора не-опроса получает пометку типа работы и обычный шаблон по умолчанию. */
	public function test_new_practice_draft_is_marked_with_work_type(): void {
		fs_test_seed_post( array( 'ID' => 204, 'post_type' => 'fs_lms_problems', 'post_status' => 'auto-draft' ) );
		$_GET = array( 'fs_lms_subject' => 'inf', 'fs_lms_work_type' => 'practice' );
		$this->subjects->method( 'getByKey' )->willReturn( new SubjectDTO( key: 'inf', name: 'Информатика' ) );
		$this->posts->method( 'getMeta' )->willReturn( '' );

		$marks = array();
		$this->posts->method( 'updateMeta' )->willReturnCallback( function ( int $id, string $key, mixed $value ) use ( &$marks ): void {
			$marks[ $key ] = $value;
		} );

		$this->controller->prefillDraftFromQuery( 204, get_post( 204 ) );

		self::assertSame( 'practice', $marks['fs_lms_bank_task_work_type'] ?? null );
		self::assertNotContains( $marks['fs_lms_template_type'] ?? 'standard_task', array( 'choice_task', 'matching_task', 'ordering_task', 'fill_task', 'drag_fill_task' ) );
	}
}
