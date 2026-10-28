<?php

declare( strict_types=1 );

namespace Unit\Services\Security;

use Inc\Managers\Person\UserManager;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Services\Security\PasswordGeneratorService;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\TestCase;

/** «Читаемый» пароль для флаеров: без 0/O и 1/l/I, случайный, заданной длины. */
class PasswordGeneratorReadableTest extends TestCase {

	private PasswordGeneratorService $generator;

	protected function setUp(): void {
		parent::setUp();
		$this->generator = new PasswordGeneratorService(
			$this->createStub( UserManager::class ),
			$this->createStub( PiiCryptoService::class ),
			$this->createStub( UserRepository::class )
		);
	}

	public function test_default_length_is_eight(): void {
		self::assertSame( 8, strlen( $this->generator->generateReadable() ) );
	}

	public function test_custom_length_is_respected_and_clamped(): void {
		self::assertSame( 12, strlen( $this->generator->generateReadable( 12 ) ) );
		self::assertSame( 1, strlen( $this->generator->generateReadable( 0 ) ), 'меньше единицы — один знак' );
		self::assertSame( 1, strlen( $this->generator->generateReadable( -5 ) ) );
		self::assertSame( 64, strlen( $this->generator->generateReadable( 1000 ) ), 'больше 64 — 64 знака' );
	}

	public function test_confusable_characters_never_appear(): void {
		$sample = '';
		for ( $i = 0; $i < 2000; $i++ ) {
			$sample .= $this->generator->generateReadable();
		}

		foreach ( array( '0', 'O', '1', 'l', 'I' ) as $bad ) {
			self::assertStringNotContainsString( $bad, $sample, "знак «{$bad}» путается с соседним и не должен попадать на флаер" );
		}
	}

	public function test_only_alphabet_characters_are_used_and_every_one_of_them_occurs(): void {
		$sample = '';
		for ( $i = 0; $i < 3000; $i++ ) {
			$sample .= $this->generator->generateReadable();
		}

		self::assertSame( 0, preg_match( '/[^' . preg_quote( PasswordGeneratorService::READABLE_ALPHABET, '/' ) . ']/', $sample ) );
		// 24 000 знаков на 57 символов: пропавший символ означал бы перекос генератора.
		self::assertSame( 57, count( array_unique( str_split( $sample ) ) ) );
	}

	public function test_alphabet_has_57_unique_characters_without_the_confusable_ones(): void {
		$alphabet = PasswordGeneratorService::READABLE_ALPHABET;

		self::assertSame( 57, strlen( $alphabet ) );
		self::assertSame( strlen( $alphabet ), count( array_unique( str_split( $alphabet ) ) ) );
		self::assertSame( 0, preg_match( '/[01OlI]/', $alphabet ) );
	}

	public function test_passwords_are_random(): void {
		$set = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$set[ $this->generator->generateReadable() ] = true;
		}

		self::assertCount( 200, $set, '200 паролей по 57^8 вариантов не должны совпасть' );
	}
}
