<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Subject;

use Inc\Callbacks\Subject\SubjectValidationCallbacks;
use Inc\DTO\Subject\TaxonomyDataDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Task\TaskPublishGuard;
use Inc\Services\Task\TaskPublishValidator;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;

/**
 * Регрессия: публикация задания без данных формы (быстрое/массовое редактирование,
 * программный wp_update_post) откатывалась в черновик, потому что валидатор читал
 * только $_POST и не видел уже сохранённые мету, шаблон и термины.
 */
class SubjectValidationCallbacksTest extends TestCase {

	private TaskPublishValidator $validator;
	private PostManager          $posts;
	private TermManager          $terms;
	private TaxonomyRepository   $taxonomies;
	private TemplateResolver     $templateResolver;
	private SubjectValidationCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		$_POST = array();

		$this->validator        = $this->createMock( TaskPublishValidator::class );
		$this->posts            = $this->createMock( PostManager::class );
		$this->terms            = $this->createMock( TermManager::class );
		$this->taxonomies       = $this->createMock( TaxonomyRepository::class );
		$this->templateResolver = $this->createMock( TemplateResolver::class );

		$this->cb = new SubjectValidationCallbacks(
			$this->validator,
			new TaskPublishGuard(),
			$this->posts,
			$this->terms,
			$this->taxonomies,
			$this->templateResolver
		);
	}

	/** @return array<string, mixed> */
	private function postData( string $status = 'publish', string $type = 'inf_tasks' ): array {
		return array(
			'post_status' => $status,
			'post_type'   => $type,
			'post_title'  => 'Задание',
		);
	}

	public function test_uses_stored_meta_and_template_when_form_is_absent(): void {
		$stored = array( 'task_condition' => 'Условие', 'task_answer' => '42' );
		$post   = new \WP_Post( array( 'ID' => 15, 'post_type' => 'inf_tasks' ) );

		$this->posts->method( 'getMeta' )->willReturnMap( array(
			array( 15, 'fs_lms_meta', true, $stored ),
		) );
		$this->posts->method( 'get' )->with( 15 )->willReturn( $post );
		$this->templateResolver->method( 'resolveId' )->with( $post )->willReturn( 'choice_task' );
		$this->taxonomies->method( 'getBySubject' )->willReturn( array() );

		$this->validator->expects( $this->once() )
			->method( 'getSoftError' )
			->with( $stored, 'choice_task' )
			->willReturn( null );
		$this->validator->method( 'getBlockingError' )->willReturn( null );

		$data = $this->cb->validateRequiredTaxonomies( $this->postData(), array( 'ID' => 15 ) );

		self::assertSame( 'publish', $data['post_status'] );
	}

	/**
	 * Регрессия: шаблон, привязанный к терму «номер задания» (связка 19-21),
	 * в постмете отсутствует — валидатор обязан взять его из TemplateResolver,
	 * а не сваливаться на «Стандартный» с чужим полем «Условие задания».
	 */
	public function test_template_assigned_via_task_number_term_is_used(): void {
		$meta = array(
			'task_19_condition' => '<p>Условие 19</p>',
			'task_19_answer'    => '82',
			'task_20_condition' => '<p>Условие 20</p>',
			'task_20_answer'    => '9',
			'task_21_condition' => '<p>Условие 21</p>',
			'task_21_answer'    => '11',
			'task_code'         => 'print(1)',
		);
		$post = new \WP_Post( array( 'ID' => 15, 'post_type' => 'inf_tasks' ) );

		$_POST = array( 'fs_lms_meta' => $meta );

		$this->posts->method( 'get' )->with( 15 )->willReturn( $post );
		$this->templateResolver->method( 'resolveId' )->with( $post )->willReturn( 'triple_task' );
		$this->taxonomies->method( 'getBySubject' )->willReturn( array() );

		$this->validator->expects( $this->once() )
			->method( 'getSoftError' )
			->with( $meta, 'triple_task' )
			->willReturn( null );
		$this->validator->method( 'getBlockingError' )->willReturn( null );

		$data = $this->cb->validateRequiredTaxonomies( $this->postData(), array( 'ID' => 15 ) );

		self::assertSame( 'publish', $data['post_status'] );
	}

	public function test_form_data_wins_over_stored_state(): void {
		$this->posts->expects( $this->never() )->method( 'getMeta' );
		$this->taxonomies->method( 'getBySubject' )->willReturn( array() );

		$_POST = array(
			'fs_lms_meta'          => array( 'task_condition' => '' ),
			'fs_lms_template_type' => 'standard_task',
		);

		$this->validator->expects( $this->once() )
			->method( 'getSoftError' )
			->with( array( 'task_condition' => '' ), 'standard_task' )
			->willReturn( 'Заполните «Условие задания».' );
		$this->validator->method( 'getBlockingError' )->willReturn( null );

		$data = $this->cb->validateRequiredTaxonomies( $this->postData(), array( 'ID' => 15 ) );

		self::assertSame( 'draft', $data['post_status'] );
	}

	public function test_stored_terms_feed_required_taxonomy_check(): void {
		$this->posts->method( 'getMeta' )->willReturn( array() );
		$this->taxonomies->method( 'getBySubject' )->willReturn( array(
			new TaxonomyDataDTO( 'inf_topics', 'Темы', 'inf', 'select', false, true ),
		) );

		$term          = new \WP_Term( 7, 'Алгоритмы', 'inf_topics' );
		$this->terms->method( 'getPostTerms' )->with( 15, 'inf_topics' )->willReturn( array( $term ) );

		$this->validator->expects( $this->once() )
			->method( 'getBlockingError' )
			->with( 'inf_tasks', array( 'inf_topics' => array( 7 ) ) )
			->willReturn( null );
		$this->validator->method( 'getSoftError' )->willReturn( null );

		$data = $this->cb->validateRequiredTaxonomies( $this->postData(), array( 'ID' => 15 ) );

		self::assertSame( 'publish', $data['post_status'] );
	}

	/**
	 * Регрессия: импорт пакета (PostRestorer) вставляет опубликованное задание при
	 * пустом $_POST — мета и термины пишутся сразу после insert. Валидация по
	 * несуществующему состоянию откатывала бы весь импорт в черновики.
	 */
	public function test_programmatic_insert_without_form_is_not_validated(): void {
		$this->validator->expects( $this->never() )->method( 'getBlockingError' );
		$this->validator->expects( $this->never() )->method( 'getSoftError' );

		$data = $this->cb->validateRequiredTaxonomies( $this->postData(), array( 'ID' => 0 ) );

		self::assertSame( 'publish', $data['post_status'] );
	}

	/**
	 * Первичная вставка из модалки inline-редактора: post_id=0, шаблон приходит
	 * параметром `template` (не fs_lms_template_type), терминов ещё нет нигде —
	 * проверяется только мета, и по правильному шаблону, а не по «Стандартному».
	 */
	public function test_modal_insert_uses_template_param_and_skips_tax_check(): void {
		$meta  = array( 'task_condition' => '<p>Условие</p>', 'task_options' => array( 'options' => array() ) );
		$_POST = array(
			'fs_lms_meta' => $meta,
			'template'    => 'choice_task',
		);

		$this->validator->expects( $this->never() )->method( 'getBlockingError' );
		$this->validator->expects( $this->once() )
			->method( 'getSoftError' )
			->with( $meta, 'choice_task' )
			->willReturn( null );

		$data = $this->cb->validateRequiredTaxonomies( $this->postData(), array( 'ID' => 0 ) );

		self::assertSame( 'publish', $data['post_status'] );
	}

	public function test_incomplete_task_is_still_rolled_back_to_draft(): void {
		$this->posts->method( 'getMeta' )->willReturn( array() );
		$this->taxonomies->method( 'getBySubject' )->willReturn( array() );

		$this->validator->method( 'getBlockingError' )->willReturn( null );
		$this->validator->method( 'getSoftError' )->willReturn( 'Заполните «Условие задания».' );

		$data = $this->cb->validateRequiredTaxonomies( $this->postData(), array( 'ID' => 15 ) );

		self::assertSame( 'draft', $data['post_status'] );
	}

	public function test_draft_save_is_not_validated(): void {
		$this->validator->expects( $this->never() )->method( 'getSoftError' );

		$data = $this->cb->validateRequiredTaxonomies( $this->postData( 'draft' ), array( 'ID' => 15 ) );

		self::assertSame( 'draft', $data['post_status'] );
	}

	public function test_other_post_types_are_untouched(): void {
		$this->validator->expects( $this->never() )->method( 'getSoftError' );

		$data = $this->cb->validateRequiredTaxonomies( $this->postData( 'publish', 'page' ), array( 'ID' => 15 ) );

		self::assertSame( 'publish', $data['post_status'] );
	}
}
