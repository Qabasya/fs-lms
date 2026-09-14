<?php

declare(strict_types=1);

namespace Unit\Services\Security;

use DomainException;
use Inc\Services\Security\CredentialsPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CredentialsPolicyTest extends TestCase {

	// ── Пароль ───────────────────────────────────────────────────────────────────

	/** @return array<string, array{string}> */
	public static function validPasswords(): array {
		return array(
			'латиница и цифры'        => array( 'Pass123' ),
			'все спецсимволы'         => array( '_%*?!№#@' ),
			'процент с hex-цифрами'   => array( 'Pass%12ab' ),
			'минимум 3 символа'       => array( 'a1!' ),
			'максимум 16 символов'    => array( str_repeat( 'a', 15 ) . '№' ),
		);
	}

	#[DataProvider( 'validPasswords' )]
	public function test_accepts_valid_password( string $password ): void {
		( new CredentialsPolicy() )->assertPassword( $password );
		$this->addToAssertionCount( 1 );
	}

	/** @return array<string, array{string}> */
	public static function invalidPasswords(): array {
		return array(
			'короче 3'           => array( 'a1' ),
			'длиннее 16'         => array( str_repeat( 'a', 17 ) ),
			'пробел'             => array( 'pass word' ),
			'пробел по краю'     => array( ' pass' ),
			'кириллица'          => array( 'пароль1' ),
			'символ вне набора'  => array( 'pass$1' ),
			'кавычка'            => array( 'pa"ss' ),
			'пустой'             => array( '' ),
		);
	}

	#[DataProvider( 'invalidPasswords' )]
	public function test_rejects_invalid_password( string $password ): void {
		$this->expectException( DomainException::class );
		$this->expectExceptionMessage( CredentialsPolicy::PASSWORD_ERROR );

		( new CredentialsPolicy() )->assertPassword( $password );
	}

	// ── Логин ────────────────────────────────────────────────────────────────────

	public function test_accepts_valid_login(): void {
		$policy = new CredentialsPolicy();
		$policy->assertLogin( 'ivan_2010' );
		$policy->assertLogin( 'abc' );
		$policy->assertLogin( str_repeat( 'a', 20 ) );
		$this->addToAssertionCount( 3 );
	}

	/** @return array<string, array{string}> */
	public static function invalidLogins(): array {
		return array(
			'короче 3'     => array( 'ab' ),
			'длиннее 20'   => array( str_repeat( 'a', 21 ) ),
			'email'        => array( 'ivan@mail.ru' ),
			'спецсимвол'   => array( 'ivan!' ),
			'кириллица'    => array( 'иван' ),
			'пустой'       => array( '' ),
		);
	}

	#[DataProvider( 'invalidLogins' )]
	public function test_rejects_invalid_login( string $login ): void {
		$this->expectException( DomainException::class );
		$this->expectExceptionMessage( CredentialsPolicy::LOGIN_ERROR );

		( new CredentialsPolicy() )->assertLogin( $login );
	}
}
