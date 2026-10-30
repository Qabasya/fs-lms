<?php

declare( strict_types=1 );

namespace Unit\Controllers\Course;

use Inc\Controllers\Course\WorkMetaBoxController;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\MetaBoxManager;
use Inc\Managers\Wp\PostManager;
use Inc\MetaBoxes\Templates\WorkTemplate;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Course\WorkAuthoringService;
use Inc\Services\Task\TaskPublishGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Смена типа работы: нельзя перевести работу в тип, который не принимает её задания (опросные из «Опроса» — в практику и т. п.).
 * Тип остаётся прежним, остальные поля сохраняются, автору показывается предупреждение со списком заданий.
 */
class WorkMetaBoxControllerTypeChangeTest extends TestCase {

	private MetaBoxManager&MockObject $metaBoxes;
	private WorkManager&MockObject $works;
	private WorkAuthoringService&MockObject $authoring;
	private TaskPublishGuard&MockObject $guard;
	private WorkMetaBoxController $controller;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		fs_test_seed_post( array( 'ID' => 5, 'post_type' => 'inf_works', 'post_status' => 'draft' ) );

		$this->metaBoxes = $this->createMock( MetaBoxManager::class );
		$this->works     = $this->createMock( WorkManager::class );
		$this->authoring = $this->createMock( WorkAuthoringService::class );
		$this->guard     = $this->createMock( TaskPublishGuard::class );

		$this->controller = new WorkMetaBoxController(
			$this->createMock( SubjectRepository::class ),
			$this->createMock( MetaBoxRegistrar::class ),
			$this->metaBoxes,
			new WorkTemplate(),
			$this->works,
			$this->createMock( PostManager::class ),
			$this->guard,
			$this->authoring,
		);
	}

	private function currentWork( WorkType $type, array $items ): void {
		$this->works->method( 'get' )->willReturn( new WorkDTO(
			id: 5, subjectKey: 'inf', title: 'Работа', workType: $type, itemIds: $items, instructions: '', authorId: 1, status: 'draft',
		) );
	}

	private function postType( string $type ): void {
		$_POST = array( 'fs_lms_meta_nonce' => 'nonce', 'fs_lms_meta' => array( 'work_type' => $type, 'max_attempts' => '2' ) );
	}

	/** @return array<string, mixed>|null Данные, с которыми сохранилась мета */
	private function capturedMeta(): ?array {
		$captured = null;
		$this->metaBoxes->method( 'saveFieldsMerge' )->willReturnCallback(
			function ( int $id, string $key, array $raw ) use ( &$captured ): void {
				$captured = $raw;
			}
		);
		$this->controller->handleWorkSave( 5 );

		return $captured;
	}

	public function test_survey_with_question_items_cannot_become_practice(): void {
		$this->currentWork( WorkType::Survey, array( 10, 20 ) );
		$this->postType( 'practice' );
		$this->authoring->method( 'disallowedItemIds' )->willReturnCallback(
			static fn( WorkType $type, array $ids ): array => WorkType::Practice === $type ? array( 20 ) : array()
		);
		$this->guard->expects( self::once() )->method( 'warn' )->with( 'fs_lms_work_type_change_', self::stringContains( '20' ) );

		$meta = $this->capturedMeta();

		self::assertNotNull( $meta );
		self::assertArrayNotHasKey( 'work_type', $meta );
		self::assertSame( '2', $meta['max_attempts'] );
	}

	public function test_allowed_type_change_is_saved(): void {
		$this->currentWork( WorkType::Practice, array( 10 ) );
		$this->postType( 'survey' );
		$this->authoring->method( 'disallowedItemIds' )->willReturn( array() );
		$this->guard->expects( self::never() )->method( 'warn' );

		self::assertSame( 'survey', $this->capturedMeta()['work_type'] );
	}

	/** Старые работы с опросными заданиями (до запрета) можно переводить между обычными типами: новых нарушений нет. */
	public function test_legacy_violations_do_not_block_a_change_between_regular_types(): void {
		$this->currentWork( WorkType::Practice, array( 10, 20 ) );
		$this->postType( 'homework' );
		$this->authoring->method( 'disallowedItemIds' )->willReturn( array( 20 ) ); // 20 мешает и практике, и домашней
		$this->guard->expects( self::never() )->method( 'warn' );

		self::assertSame( 'homework', $this->capturedMeta()['work_type'] );
	}

	public function test_unchanged_type_is_not_checked(): void {
		$this->currentWork( WorkType::Practice, array( 10 ) );
		$this->postType( 'practice' );
		$this->authoring->expects( self::never() )->method( 'disallowedItemIds' );

		self::assertSame( 'practice', $this->capturedMeta()['work_type'] );
	}
}
