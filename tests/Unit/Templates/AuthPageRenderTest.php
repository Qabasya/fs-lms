<?php

declare( strict_types=1 );

namespace Unit\Templates;

use PHPUnit\Framework\TestCase;

/** Страница входа: при блокировке поля отключены, под формой — таймер и приписка без контактов. */
class AuthPageRenderTest extends TestCase {

	private function render( int $lockSeconds ): string {
		$error_message = $lockSeconds > 0 ? 'Слишком много неудачных попыток.' : '';
		$lock_seconds  = $lockSeconds;
		$prefill_login = 'ivan';
		$consent_url   = '';
		$logo_url      = 'https://example.test/logo.svg';
		$home_url      = 'https://example.test/';
		$apply_url     = 'https://example.test/apply/';

		ob_start();
		require __DIR__ . '/../../../templates/frontend/auth-page.php';

		return (string) ob_get_clean();
	}

	public function test_open_form_has_enabled_fields_and_no_lock_block(): void {
		$html = $this->render( 0 );

		self::assertSame( 0, preg_match( '/<input[^>]*name="log"[^>]*disabled/', $html ) );
		self::assertSame( 0, preg_match( '/<input[^>]*name="pwd"[^>]*disabled/', $html ) );
		self::assertStringNotContainsString( 'data-login-lock', $html );
		self::assertStringNotContainsString( 'свяжитесь с администратором', $html );
	}

	public function test_locked_form_disables_every_field_and_shows_timer_and_note(): void {
		$html = $this->render( 900 );

		self::assertSame( 1, preg_match( '/<input[^>]*name="log"[^>]*data-lock-field[^>]*disabled/', $html ), 'логин отключён' );
		self::assertSame( 1, preg_match( '/<input[^>]*name="pwd"[^>]*data-lock-field[^>]*disabled/', $html ), 'пароль отключён' );
		self::assertSame( 1, preg_match( '/<button[^>]*js-toggle-secret[^>]*disabled/', $html ), 'глазок пароля отключён' );
		self::assertSame( 1, preg_match( '/<button[^>]*id="wp-submit"[^>]*disabled/', $html ), 'кнопка «Войти» отключена' );
		self::assertStringContainsString( 'data-login-lock="900"', $html );
		self::assertStringContainsString( 'data-login-lock-timer', $html );
		$note = trim( preg_replace( '/\s+/', ' ', strip_tags( $html ) ) );
		self::assertStringContainsString( 'Для разблокировки подождите заданное время или свяжитесь с администратором', $note );
		self::assertStringContainsString( '<span class="fs-auth-card__lock-line">или свяжитесь с администратором</span>', $html, 'вторая половина приписки — на отдельной строке' );
	}

	public function test_locked_form_gives_no_contacts_to_bots(): void {
		$html = $this->render( 900 );

		self::assertStringNotContainsString( 'mailto:', $html );
		self::assertStringNotContainsString( 'tel:', $html );
		self::assertDoesNotMatchRegularExpression( '/@[a-z0-9-]+\.[a-z]{2,}/i', strip_tags( explode( '</form>', $html )[1] ?? '' ) );
	}

	// login-form.js ищет блок через document: он обязан быть и в разметке, и именно под формой, не внутри неё.
	public function test_lock_block_stands_below_the_form(): void {
		$html = $this->render( 900 );

		self::assertSame( 1, substr_count( $html, 'data-login-lock=' ) );
		self::assertGreaterThan( strpos( $html, '</form>' ), strpos( $html, 'data-login-lock=' ), 'блок «подождите» стоит после формы' );
	}

	public function test_every_lockable_control_carries_the_unlock_marker(): void {
		$html = $this->render( 900 );

		// login-form.js включает по концу паузы ровно то, что помечено data-lock-field.
		self::assertSame( 4, substr_count( $html, 'data-lock-field' ), 'логин, пароль, глазок и «Войти»' );
	}
}
