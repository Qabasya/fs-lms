<?php

declare(strict_types=1);

namespace Unit\Enums\Auth;

use Inc\Enums\Auth\LoginNotice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Тексты формы входа: ступенчатая блокировка длится от минут до суток. */
class LoginNoticeTest extends TestCase {

	/** @return array<string, array{int, string}> */
	public static function durations(): array {
		return array(
			'минуты'              => array( 15, '15 мин.' ),
			'ровно час'           => array( 60, '1 ч.' ),
			'час и минуты'        => array( 65, '1 ч. 5 мин.' ),
			'почти сутки'         => array( 23 * 60 + 59, '23 ч. 59 мин.' ),
			'сутки'               => array( 24 * 60, '24 ч.' ),
		);
	}

	#[DataProvider( 'durations' )]
	public function test_locked_message_shows_wait_in_minutes_and_hours( int $minutes, string $expected ): void {
		self::assertSame( "Слишком много неудачных попыток. Повторите вход через $expected", LoginNotice::Locked->message( $minutes ) );
	}

	#[DataProvider( 'durations' )]
	public function test_last_message_names_length_of_next_lock( int $minutes, string $expected ): void {
		self::assertSame(
			"Неверный логин или пароль. Осталась одна попытка, после неё вход будет закрыт на $expected",
			LoginNotice::Last->message( $minutes )
		);
	}

	public function test_last_message_without_wait_names_first_step(): void {
		self::assertStringEndsWith( 'закрыт на 15 мин.', LoginNotice::Last->message() );
	}

	public function test_locked_message_without_wait_asks_to_retry_later(): void {
		self::assertSame( 'Слишком много неудачных попыток. Повторите вход позже.', LoginNotice::Locked->message() );
	}

	public function test_message_with_timer_names_the_reason_without_a_wait(): void {
		self::assertSame( 'Слишком много неудачных попыток.', LoginNotice::Locked->messageWithTimer() );
		self::assertStringNotContainsString( 'Повторите', LoginNotice::Locked->messageWithTimer() );
	}

	public function test_message_with_timer_keeps_other_notices(): void {
		self::assertSame( LoginNotice::Failed->message(), LoginNotice::Failed->messageWithTimer() );
		self::assertSame( LoginNotice::Captcha->message(), LoginNotice::Captcha->messageWithTimer() );
	}

	public function test_failed_message_is_the_same_for_every_login(): void {
		self::assertSame( 'Неверный логин или пароль.', LoginNotice::Failed->message( 99 ) );
	}
}
