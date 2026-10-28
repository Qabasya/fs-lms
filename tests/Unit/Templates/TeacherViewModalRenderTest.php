<?php

declare( strict_types=1 );

namespace Unit\Templates;

use PHPUnit\Framework\TestCase;

/** Карточка преподавателя: «Снять блокировку входа» есть в разметке и скрыта, пока JS не получил ID аккаунта. */
class TeacherViewModalRenderTest extends TestCase {

	private function render(): string {
		ob_start();
		require __DIR__ . '/../../../templates/admin/components/modals/enrollment/teacher-view-modal.php';

		return (string) ob_get_clean();
	}

	public function test_unlock_button_is_in_the_footer_and_hidden_by_default(): void {
		$html = $this->render();

		self::assertSame( 1, preg_match( '/<button[^>]*js-tvm-unlock[^>]*hidden[^>]*>/', $html ), 'кнопка скрыта до установки аккаунта' );
		self::assertStringContainsString( 'Снять блокировку входа', $html );
		self::assertGreaterThan(
			strpos( $html, 'fs-lms-modal-footer' ),
			strpos( $html, 'js-tvm-unlock' ),
			'кнопка стоит в футере модалки, рядом с «Закрыть»'
		);
	}

	public function test_modal_stays_read_only(): void {
		self::assertStringNotContainsString( '<input', $this->render(), 'карточка по-прежнему только для просмотра' );
	}
}
