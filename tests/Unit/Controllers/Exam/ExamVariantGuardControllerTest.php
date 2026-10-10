<?php

declare( strict_types=1 );

namespace Unit\Controllers\Exam;

use Inc\Controllers\Exam\ExamVariantGuardController;
use Inc\Enums\Wp\TransientKey;
use Inc\Managers\Wp\TransientManager;
use Inc\Services\Exam\ExamVariantGuard;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Хуки заморозки варианта: правка меты, полей поста, корзина, удаление поста и вложения — только для замороженных объектов.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamVariantGuardControllerTest extends TestCase {

	private ExamVariantGuard&MockObject $guard;
	private TransientManager&MockObject $transients;
	private ExamVariantGuardController $controller;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		$this->guard      = $this->createMock( ExamVariantGuard::class );
		$this->transients = $this->createMock( TransientManager::class );
		$this->guard->method( 'isPostFrozen' )->willReturnCallback( static fn ( int $id ): bool => in_array( $id, array( 500, 11 ), true ) );
		$this->guard->method( 'isAttachmentFrozen' )->willReturnCallback( static fn ( int $id ): bool => 900 === $id );
		$this->guard->method( 'reason' )->willReturn( 'Вариант используется в экзамене «Пробный»: изменение недоступно.' );

		$this->controller = new ExamVariantGuardController( $this->guard, $this->transients );
	}

	public function test_assessment_items_cannot_change_when_frozen(): void {
		// Состав и раскладка работы лежат в `fs_lms_meta`: любая запись, добавление и удаление отклоняются.
		self::assertFalse( $this->controller->guardMeta( null, 500, 'fs_lms_meta' ) );
	}

	public function test_assessment_kind_and_settings_cannot_change_when_frozen(): void {
		self::assertFalse( $this->controller->guardMeta( null, 500, 'fs_lms_assessment_kind' ) );
		self::assertFalse( $this->controller->guardMeta( null, 500, 'fs_lms_template_type' ) );
	}

	public function test_task_meta_cannot_change_when_frozen_via_metabox(): void {
		self::assertFalse( $this->controller->guardMeta( null, 11, 'fs_lms_meta' ) );
	}

	public function test_unfrozen_posts_and_foreign_keys_are_untouched(): void {
		self::assertNull( $this->controller->guardMeta( null, 77, 'fs_lms_meta' ), 'Свободный вариант правится как обычно.' );
		self::assertNull( $this->controller->guardMeta( null, 500, '_edit_lock' ), 'Служебная мета WordPress не блокируется.' );
		self::assertSame( 'уже решено', $this->controller->guardMeta( 'уже решено', 500, 'fs_lms_meta' ), 'Чужое решение фильтра не перетираем.' );
	}

	public function test_bundle_import_does_not_overwrite_frozen_variant(): void {
		// Импорт и перенос предмета пишут те же posts и meta: поля поста возвращаются прежними, мета отклоняется.
		fs_test_seed_post( array( 'ID' => 11, 'post_type' => 'inf_ege_tasks', 'post_title' => 'Прежнее', 'post_content' => 'Старое условие', 'post_status' => 'publish' ) );

		$data = $this->controller->guardPostData(
			array( 'post_title' => 'Из пакета', 'post_content' => 'Новое условие', 'post_status' => 'draft', 'post_name' => '', 'post_excerpt' => '', 'post_parent' => 0, 'menu_order' => 0 ),
			array( 'ID' => 11 )
		);

		self::assertSame( 'Прежнее', $data['post_title'] );
		self::assertSame( 'Старое условие', $data['post_content'] );
		self::assertSame( 'publish', $data['post_status'] );
		self::assertFalse( $this->controller->guardMeta( null, 11, 'fs_lms_meta' ) );
	}

	public function test_new_post_data_passes_through(): void {
		$data = array( 'post_title' => 'Новое', 'post_content' => 'x' );

		self::assertSame( $data, $this->controller->guardPostData( $data, array( 'ID' => 0 ) ) );
		self::assertSame( $data, $this->controller->guardPostData( $data, array( 'ID' => 77 ) ) );
	}

	public function test_frozen_task_cannot_be_trashed_or_deleted(): void {
		$frozen = new \WP_Post( array( 'ID' => 11, 'post_type' => 'inf_ege_tasks' ) );
		$free   = new \WP_Post( array( 'ID' => 77, 'post_type' => 'inf_ege_tasks' ) );

		self::assertFalse( $this->controller->guardTrash( null, $frozen ) );
		self::assertFalse( $this->controller->guardDelete( null, $frozen ) );
		self::assertNull( $this->controller->guardTrash( null, $free ) );
		self::assertNull( $this->controller->guardDelete( null, $free ) );
	}

	public function test_attachment_of_frozen_task_cannot_be_deleted_or_replaced(): void {
		self::assertFalse( $this->controller->guardAttachment( null, new \WP_Post( array( 'ID' => 900, 'post_type' => 'attachment' ) ) ) );
		self::assertNull( $this->controller->guardAttachment( null, new \WP_Post( array( 'ID' => 901, 'post_type' => 'attachment' ) ) ) );
		// Замена файла — это правка меты задания со ссылкой на новое вложение: она отклоняется тем же фильтром меты.
		self::assertFalse( $this->controller->guardMeta( null, 11, 'fs_lms_meta' ) );
	}

	public function test_refusal_reason_is_stored_for_notice(): void {
		$this->transients->expects( self::once() )->method( 'set' )->with( TransientKey::ExamVariantBlocked, self::anything(), self::stringContains( 'изменение недоступно' ), self::anything() );

		$this->controller->guardMeta( null, 500, 'fs_lms_meta' );
	}
}
