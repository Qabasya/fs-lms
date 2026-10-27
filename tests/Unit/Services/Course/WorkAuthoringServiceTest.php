<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Services\Course\WorkAuthoringService;
use Inc\Enums\Course\WorkType;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Services\Task\TaskBundleService;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;

class WorkAuthoringServiceTest extends TestCase {

	private WorkAuthoringService $service;

	/** @var TemplateResolver&\PHPUnit\Framework\MockObject\MockObject */
	private TemplateResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		$posts          = new PostManager();
		$this->resolver = $this->createMock( TemplateResolver::class );
		$this->service  = new WorkAuthoringService(
			$posts,
			$this->createMock( TermManager::class ),
			new TaskBundleService( $posts, $this->createMock( TermManager::class ) ),
			$this->resolver
		);
	}

	public function test_task_candidates_are_scoped_to_subject(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_tasks', 'post_title' => 'A' ) );
		fs_test_seed_post( array( 'ID' => 2, 'post_type' => 'inf_tasks', 'post_title' => 'B' ) );
		fs_test_seed_post( array( 'ID' => 3, 'post_type' => 'rus_tasks', 'post_title' => 'Чужое' ) );

		$ids = array_column( $this->service->getTaskCandidates( 'inf', 0, 0, 'subject' ), 'id' );

		sort( $ids );
		self::assertSame( array( 1, 2 ), $ids );
	}

	public function test_validate_item_ids_drops_missing_and_non_task_posts(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_tasks' ) );
		fs_test_seed_post( array( 'ID' => 4, 'post_type' => 'inf_lessons' ) );

		self::assertSame( array( 1 ), $this->service->validateItemIds( 'inf', array( 1, 4, 9999 ) ) );
	}

	/** Кросс-предметный импорт: опубликованное задание другого предмета можно поставить в работу. */
	public function test_validate_item_ids_keeps_published_task_of_another_subject(): void {
		fs_test_seed_post( array( 'ID' => 3, 'post_type' => 'rus_tasks', 'post_status' => 'publish' ) );

		self::assertSame( array( 3 ), $this->service->validateItemIds( 'inf', array( 3 ) ) );
	}

	public function test_validate_item_ids_drops_unpublished_task_of_another_subject(): void {
		fs_test_seed_post( array( 'ID' => 3, 'post_type' => 'rus_tasks', 'post_status' => 'draft' ) );
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_tasks', 'post_status' => 'draft' ) );

		// Свой черновик допустим (автор собирает работу до публикации заданий), чужой — нет.
		self::assertSame( array( 1 ), $this->service->validateItemIds( 'inf', array( 3, 1 ) ) );
	}

	public function test_validate_item_ids_keeps_problems(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_tasks' ) );
		fs_test_seed_post( array( 'ID' => 5, 'post_type' => 'fs_lms_problems' ) );

		$ids = $this->service->validateItemIds( 'inf', array( 1, 5 ) );
		self::assertSame( array( 1, 5 ), $ids );
	}

	/**
	 * Дефолтный дропдаун (source=subject) обязан подхватывать задачи банка,
	 * помеченные ЭТИМ предметом (BankTaskSubject) — задача может физически
	 * лежать в fs_lms_problems, оставаясь «предметной» (предмет без своего
	 * CPT-банка, Эпик 18), а не только скрытый до явного «Все задания».
	 */
	public function test_item_candidates_source_subject_includes_subject_tagged_bank(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_tasks', 'post_title' => 'Задача предмета' ) );
		fs_test_seed_post(
			array( 'ID' => 2, 'post_type' => 'fs_lms_problems', 'post_title' => 'Банк, помечен предметом' ),
			array( \Inc\Enums\Wp\PostMetaName::BankTaskSubject->value => 'inf' )
		);
		fs_test_seed_post(
			array( 'ID' => 3, 'post_type' => 'fs_lms_problems', 'post_title' => 'Банк, без метки' )
		);

		$ids = array_column( $this->service->getItemCandidates( 'inf', 0, 'subject', '', 'subject' ), 'id' );

		self::assertSame( array( 1, 2 ), $ids );
	}

	public function test_item_candidates_source_all_ignores_subject_tag(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_tasks', 'post_title' => 'Задача предмета' ) );
		fs_test_seed_post(
			array( 'ID' => 2, 'post_type' => 'fs_lms_problems', 'post_title' => 'Банк, другой предмет' ),
			array( \Inc\Enums\Wp\PostMetaName::BankTaskSubject->value => 'math' )
		);

		$ids = array_column( $this->service->getItemCandidates( 'inf', 0, 'subject', '', 'all' ), 'id' );

		self::assertSame( array( 1, 2 ), $ids );
	}

	/**
	 * Источник «публичные задачи» (кнопка «Выбрать из публичных задач» в работе
	 * и экзамене): только опубликованные задачи предмета, без банка.
	 */
	public function test_item_candidates_source_public_returns_only_published_subject_tasks(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_tasks', 'post_title' => 'Опубликованная', 'post_status' => 'publish' ) );
		fs_test_seed_post( array( 'ID' => 2, 'post_type' => 'inf_tasks', 'post_title' => 'Черновик', 'post_status' => 'draft' ) );
		fs_test_seed_post(
			array( 'ID' => 3, 'post_type' => 'fs_lms_problems', 'post_title' => 'Банк предмета', 'post_status' => 'publish' ),
			array( \Inc\Enums\Wp\PostMetaName::BankTaskSubject->value => 'inf' )
		);

		$ids = array_column( $this->service->getItemCandidates( 'inf', 0, 'mine', '', 'public' ), 'id' );

		self::assertSame( array( 1 ), $ids );
	}

	/**
	 * Публичный источник не сужается по автору, хотя scope пришёл 'mine':
	 * каталог тренажёра общий, и задачу, заведённую коллегой, автор курса
	 * обязан видеть — иначе кнопка бессмысленна на любой непустой команде.
	 */
	public function test_item_candidates_source_public_ignores_author_scope(): void {
		$GLOBALS['_fs_test_user_id'] = 7;
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_tasks', 'post_title' => 'Чужая', 'post_status' => 'publish', 'post_author' => 42 ) );

		try {
			$ids = array_column( $this->service->getItemCandidates( 'inf', 0, 'mine', '', 'public' ), 'id' );
		} finally {
			$GLOBALS['_fs_test_user_id'] = 0;
		}

		// Со scope='mine' запрос ушёл бы с author=7 и задачу автора 42 не нашёл.
		self::assertSame( array( 1 ), $ids );
	}

	/**
	 * Окно массового добавления догружает кандидатов страницами по 50: вторая
	 * страница продолжает первую, а пустая означает конец списка.
	 */
	public function test_item_candidates_are_paged(): void {
		for ( $id = 1; $id <= 60; $id++ ) {
			fs_test_seed_post( array( 'ID' => $id, 'post_type' => 'inf_tasks', 'post_title' => "Задача {$id}", 'post_status' => 'publish' ) );
		}

		$first  = array_column( $this->service->getItemCandidates( 'inf', 0, 'subject', '', 'public' ), 'id' );
		$second = array_column( $this->service->getItemCandidates( 'inf', 0, 'subject', '', 'public', 2 ), 'id' );
		$third  = $this->service->getItemCandidates( 'inf', 0, 'subject', '', 'public', 3 );

		self::assertCount( 50, $first );
		self::assertCount( 10, $second );
		self::assertSame( array(), array_intersect( $first, $second ) );
		self::assertSame( array(), $third );
	}

	/** У смешанного источника страница листает и задания предмета, и банк. */
	public function test_mixed_source_pages_both_tasks_and_bank(): void {
		for ( $id = 1; $id <= 55; $id++ ) {
			fs_test_seed_post( array( 'ID' => $id, 'post_type' => 'inf_tasks', 'post_title' => "Задача {$id}" ) );
		}
		for ( $id = 101; $id <= 153; $id++ ) {
			fs_test_seed_post( array( 'ID' => $id, 'post_type' => 'fs_lms_problems', 'post_title' => "Банк {$id}" ) );
		}

		$first  = $this->service->getItemCandidates( 'inf', 0, 'subject', '', 'all' );
		$second = array_column( $this->service->getItemCandidates( 'inf', 0, 'subject', '', 'all', 2 ), 'id' );

		self::assertCount( 100, $first );
		sort( $second );
		self::assertSame( array( 51, 52, 53, 54, 55, 151, 152, 153 ), $second );
	}

	/** Шаблон задания по ID поста: чётные — выбор варианта (разрешён опросу), нечётные — код (запрещён). */
	private function templatesByParity(): void {
		$this->resolver->method( 'resolveEnum' )->willReturnCallback(
			static fn( \WP_Post $post ): TaskTemplate => 0 === $post->ID % 2 ? TaskTemplate::Choice : TaskTemplate::Code
		);
	}

	public function test_survey_candidates_keep_only_allowed_templates(): void {
		$this->templatesByParity();
		for ( $id = 1; $id <= 6; $id++ ) {
			fs_test_seed_post( array( 'ID' => $id, 'post_type' => 'inf_tasks', 'post_title' => "Задача {$id}" ) );
		}

		$ids = array_column( $this->service->getItemCandidates( 'inf', 0, 'subject', '', 'subject', 1, WorkType::Survey->value ), 'id' );
		sort( $ids );

		self::assertSame( array( 2, 4, 6 ), $ids );
	}

	public function test_other_work_types_are_not_filtered_by_template(): void {
		$this->resolver->expects( self::never() )->method( 'resolveEnum' );
		for ( $id = 1; $id <= 3; $id++ ) {
			fs_test_seed_post( array( 'ID' => $id, 'post_type' => 'inf_tasks', 'post_title' => "Задача {$id}" ) );
		}

		self::assertCount( 3, $this->service->getItemCandidates( 'inf', 0, 'subject', '', 'subject', 1, WorkType::Homework->value ) );
	}

	/** Страницы нарезаются по отобранным заданиям: подходящие из дальних страниц источника не теряются. */
	public function test_survey_candidates_are_paged_after_filtering(): void {
		$this->templatesByParity();
		for ( $id = 1; $id <= 160; $id++ ) {
			fs_test_seed_post( array( 'ID' => $id, 'post_type' => 'inf_tasks', 'post_title' => "Задача {$id}" ) );
		}

		$first  = array_column( $this->service->getItemCandidates( 'inf', 0, 'subject', '', 'subject', 1, 'survey' ), 'id' );
		$second = array_column( $this->service->getItemCandidates( 'inf', 0, 'subject', '', 'subject', 2, 'survey' ), 'id' );

		self::assertCount( 50, $first );
		self::assertCount( 30, $second );
		self::assertSame( array(), array_intersect( $first, $second ) );
	}

	public function test_disallowed_item_ids_for_survey(): void {
		$this->templatesByParity();
		foreach ( array( 1, 2, 3 ) as $id ) {
			fs_test_seed_post( array( 'ID' => $id, 'post_type' => 'inf_tasks' ) );
		}

		self::assertSame( array( 1, 3 ), $this->service->disallowedItemIds( WorkType::Survey, array( 1, 2, 3 ) ) );
		self::assertSame( array(), $this->service->disallowedItemIds( WorkType::Practice, array( 1, 2, 3 ) ) );
	}

	/** Задания другого предмета в приватном источнике — только опубликованные (чужой черновик в работу не пустят). */
	public function test_item_candidates_of_another_subject_exclude_drafts_when_target_given(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'rus_tasks', 'post_title' => 'Опубликованное', 'post_status' => 'publish' ) );
		fs_test_seed_post( array( 'ID' => 2, 'post_type' => 'rus_tasks', 'post_title' => 'Черновик', 'post_status' => 'draft' ) );

		$foreign = array_column( $this->service->getItemCandidates( 'rus', 0, 'subject', '', 'subject', 1, '', 'inf' ), 'id' );
		$own     = array_column( $this->service->getItemCandidates( 'rus', 0, 'subject', '', 'subject', 1, '', 'rus' ), 'id' );
		sort( $own );

		self::assertSame( array( 1 ), $foreign );
		self::assertSame( array( 1, 2 ), $own );
	}
}
