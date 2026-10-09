<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

/**
 * Результат обработки гостевой страницы (приглашение, вход, результат): что сделать с ответом.
 *
 * Коллбек решает и возвращает исход, а выполняет его {@see \Inc\Services\Exam\GuestPageResponder}: так логика проверяется без `exit`.
 */
readonly class GuestPageOutcome {

	public const RENDER    = 'render';
	public const REDIRECT  = 'redirect';
	public const NOT_FOUND = 'not_found';

	/**
	 * @param array<string, string> $cookies Куки к установке: имя => значение (сессионные, HttpOnly).
	 * @param list<string>          $clearCookies Куки к удалению.
	 */
	private function __construct(
		public string $kind,
		public string $url = '',
		public array $cookies = array(),
		public array $clearCookies = array(),
	) {}

	public static function render(): self {
		return new self( self::RENDER );
	}

	/** @param array<string, string> $cookies */
	public static function redirect( string $url, array $cookies = array() ): self {
		return new self( self::REDIRECT, $url, $cookies );
	}

	/** Обычная 404 темы: без слов «ссылка недействительна» и без сведений о проведении. */
	public static function notFound(): self {
		return new self( self::NOT_FOUND );
	}

	/** @param list<string> $names */
	public function withClearedCookies( array $names ): self {
		return new self( $this->kind, $this->url, $this->cookies, $names );
	}
}
