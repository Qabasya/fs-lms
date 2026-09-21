<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Subject;

use Inc\Callbacks\Subject\SubjectValidationCallbacks;
use Inc\DTO\Subject\TaxonomyDataDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\ArticlePublishValidator;
use Inc\Services\Task\TaskNumberService;
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

	private TaskPublishValidator    $validator;
	private ArticlePublishValidator $articleValidator;
	private PostManager          $posts;
	private TermManager          $terms;
	private TaxonomyRepository   $taxonomies;
	private TemplateResolver     $templateResolver;
	private TaskNumberService    $numbers;
	private SubjectValidationCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		$_POST = array();

		$this->validator        = $this->createMock( TaskPublishValidator::class );
		$this->articleValidator = $this->createMock( ArticlePublishValidator::class );
		$this->posts            = $this->createMock( PostManager::class );
		$this->terms            = $this->createMock( TermManager::class );
		$this->taxonomies       = $this->createMock( TaxonomyRepository::class );
		$this->templateResolver = $this->createMock( TemplateResolver::class );
		$this->numbers          = $this->createMock( TaskNumberService::class );

		$this->cb = new SubjectValidationCallbacks(
			$this->validator,
			$this->articleValidator,
			new TaskPublishGuard(),
			$this->posts,
			$this->terms,
			$this->taxonomies,
			$this->templateResolver,
			$this->numbers
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

		$this->posts->method( 'taskMeta' )->with( 15 )->willReturn( $stored );
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
		// Из сохранённой меты читается только признак ребёнка связки.
		$this->posts->method( 'getMeta' )
			->with( 15, PostMetaName::TaskBundleParentId->value )
			->willReturn( '' );
		$this->posts->expects( $this->never() )->method( 'taskMeta' );
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
		$this->terms->method( 'getPostTerms' )->willReturnMap( array(
			array( 15, 'inf_topics', array( $term ) ),
			array( 15, 'inf_task_number', array() ),
		) );

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

	// ── Номер задания ───────────────────────────────────────────────────────────

	/**
	 * Занятый номер: публикация откатывается в черновик, а номер, который ядро
	 * уже успело переписать в «5002-2», черновику возвращается.
	 */
	public function test_taken_number_blocks_publish_and_keeps_number(): void {
		$_POST = array( 'tax_input' => array( 'inf_task_number' => array( 'inf_5' ) ) );

		$this->posts->method( 'get' )->with( 15 )->willReturn(
			new \WP_Post( array( 'ID' => 15, 'post_type' => 'inf_tasks', 'post_status' => 'draft', 'post_name' => '5002' ) )
		);
		$this->taxonomies->method( 'getBySubject' )->willReturn( array() );
		$this->validator->method( 'getBlockingError' )->willReturn( null );
		$this->validator->method( 'getSoftError' )->willReturn( null );

		$this->numbers->method( 'resolveTaskNumber' )->with( array( 'inf_5' ), 'inf' )->willReturn( 5 );
		$this->numbers->expects( $this->once() )
			->method( 'publishError' )
			->with( 'inf_tasks', 15, '5002', 5 )
			->willReturn( 'Номер 5002 уже занят заданием «№ 5002. Демоверсия» (ID 7).' );

		$data = $this->cb->validateRequiredTaxonomies(
			$this->postData() + array( 'post_name' => '5002-2' ),
			array( 'ID' => 15, 'post_name' => '5002' )
		);

		self::assertSame( 'draft', $data['post_status'] );
		self::assertSame( '5002', $data['post_name'] );
	}

	/**
	 * Правка уже опубликованного задания номер не перепроверяет: адрес живёт, и
	 * старый номер не повод снимать задание с публикации.
	 */
	public function test_published_task_number_is_not_rechecked(): void {
		$this->posts->method( 'get' )->with( 15 )->willReturn(
			new \WP_Post( array( 'ID' => 15, 'post_type' => 'inf_tasks', 'post_status' => 'publish', 'post_name' => '5002' ) )
		);
		$this->taxonomies->method( 'getBySubject' )->willReturn( array() );
		$this->validator->method( 'getBlockingError' )->willReturn( null );
		$this->validator->method( 'getSoftError' )->willReturn( null );

		$this->numbers->expects( $this->never() )->method( 'publishError' );

		$data = $this->cb->validateRequiredTaxonomies( $this->postData(), array( 'ID' => 15 ) );

		self::assertSame( 'publish', $data['post_status'] );
	}

	public function test_stored_task_number_term_is_used_when_form_is_absent(): void {
		$this->posts->method( 'get' )->with( 15 )->willReturn(
			new \WP_Post( array( 'ID' => 15, 'post_type' => 'inf_tasks', 'post_status' => 'draft', 'post_name' => '5003' ) )
		);
		$this->taxonomies->method( 'getBySubject' )->willReturn( array() );
		$this->terms->method( 'getPostTerms' )->with( 15, 'inf_task_number' )->willReturn(
			array( new \WP_Term( 3, '5', 'inf_task_number' ) )
		);
		$this->validator->method( 'getBlockingError' )->willReturn( null );
		$this->validator->method( 'getSoftError' )->willReturn( null );

		$this->numbers->method( 'resolveTaskNumber' )->with( array( '5' ), 'inf' )->willReturn( 5 );
		$this->numbers->expects( $this->once() )
			->method( 'publishError' )
			->with( 'inf_tasks', 15, '5003', 5 )
			->willReturn( null );

		$data = $this->cb->validateRequiredTaxonomies( $this->postData(), array( 'ID' => 15 ) );

		self::assertSame( 'publish', $data['post_status'] );
	}

	public function test_bundle_child_is_not_validated(): void {
		$this->posts->method( 'getMeta' )
			->with( 15, PostMetaName::TaskBundleParentId->value )
			->willReturn( 100 );
		$this->validator->expects( $this->never() )->method( 'getBlockingError' );
		$this->validator->expects( $this->never() )->method( 'getSoftError' );

		$data = $this->cb->validateRequiredTaxonomies( $this->postData(), array( 'ID' => 15 ) );

		self::assertSame( 'publish', $data['post_status'] );
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

	// ── Статьи ──────────────────────────────────────────────────────────────────

	public function test_article_form_terms_are_passed_to_article_validator(): void {
		$_POST = array( 'tax_input' => array( 'inf_task_number' => array( '12' ) ) );

		$this->validator->expects( $this->never() )->method( 'getBlockingError' );
		$this->articleValidator->expects( $this->once() )
			->method( 'getBlockingError' )
			->with( 'inf_articles', array( 'inf_task_number' => array( '12' ) ) )
			->willReturn( null );

		$data = $this->cb->validateRequiredTaxonomies(
			$this->postData( 'publish', 'inf_articles' ),
			array( 'ID' => 21 )
		);

		self::assertSame( 'publish', $data['post_status'] );
	}

	public function test_article_without_required_taxonomy_is_rolled_back_to_draft(): void {
		$_POST = array( 'tax_input' => array( 'inf_task_number' => array( '12' ) ) );

		$this->articleValidator->method( 'getBlockingError' )
			->willReturn( 'Обязательная таксономия «Раздел» не заполнена.' );

		$data = $this->cb->validateRequiredTaxonomies(
			$this->postData( 'publish', 'inf_articles' ),
			array( 'ID' => 21 )
		);

		self::assertSame( 'draft', $data['post_status'] );
	}

	/**
	 * Быстрое/массовое редактирование статьи формы метабоксов не шлёт — состояние
	 * берётся из БД, иначе заполненная статья откатывалась бы в черновик.
	 */
	public function test_article_stored_terms_are_used_when_form_is_absent(): void {
		$this->articleValidator->method( 'requiredForArticles' )->with( 'inf' )->willReturn( array(
			new TaxonomyDataDTO( 'inf_section', 'Раздел', 'inf', 'select', false, true, true ),
		) );

		$this->terms->method( 'getPostTerms' )->willReturnMap( array(
			array( 21, 'inf_task_number', array( new \WP_Term( 3, '12', 'inf_task_number' ) ) ),
			array( 21, 'inf_section', array( new \WP_Term( 8, 'Алгебра', 'inf_section' ) ) ),
		) );

		$this->articleValidator->expects( $this->once() )
			->method( 'getBlockingError' )
			->with( 'inf_articles', array( 'inf_task_number' => array( 3 ), 'inf_section' => array( 8 ) ) )
			->willReturn( null );

		$data = $this->cb->validateRequiredTaxonomies(
			$this->postData( 'publish', 'inf_articles' ),
			array( 'ID' => 21 )
		);

		self::assertSame( 'publish', $data['post_status'] );
	}

	/**
	 * Регрессия-близнец задания: импорт пакета вставляет опубликованную статью при
	 * пустом $_POST — термины пишутся сразу после insert, проверять ещё нечего.
	 */
	public function test_programmatic_article_insert_is_not_validated(): void {
		$this->articleValidator->expects( $this->never() )->method( 'getBlockingError' );

		$data = $this->cb->validateRequiredTaxonomies(
			$this->postData( 'publish', 'inf_articles' ),
			array( 'ID' => 0 )
		);

		self::assertSame( 'publish', $data['post_status'] );
	}

	public function test_article_draft_is_not_validated(): void {
		$this->articleValidator->expects( $this->never() )->method( 'getBlockingError' );

		$data = $this->cb->validateRequiredTaxonomies(
			$this->postData( 'draft', 'inf_articles' ),
			array( 'ID' => 21 )
		);

		self::assertSame( 'draft', $data['post_status'] );
	}
}
