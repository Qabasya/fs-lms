<?php

declare(strict_types=1);

namespace Unit\Services;

use Inc\Services\PiiCryptoService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PiiCryptoServiceTest extends TestCase {

	private PiiCryptoService $service;

	protected function setUp(): void {
		$this->service = new PiiCryptoService();
	}

	// ── Round-trip ────────────────────────────────────────────────────────────

	public function test_encrypt_decrypt_round_trip(): void {
		$plaintext = 'Иванов Иван Иванович';
		self::assertSame( $plaintext, $this->service->decrypt( $this->service->encrypt( $plaintext ) ) );
	}

	public function test_encrypt_round_trip_with_empty_string(): void {
		self::assertSame( '', $this->service->decrypt( $this->service->encrypt( '' ) ) );
	}

	public function test_encrypt_produces_different_ciphertext_each_call(): void {
		$plaintext = 'test value';
		// Разные nonce при каждом вызове — ciphertext не должен совпадать
		self::assertNotSame( $this->service->encrypt( $plaintext ), $this->service->encrypt( $plaintext ) );
	}

	// ── Corrupted data ────────────────────────────────────────────────────────

	public function test_decrypt_throws_on_too_short_blob(): void {
		$this->expectException( RuntimeException::class );
		$this->service->decrypt( 'short' );
	}

	public function test_decrypt_throws_on_corrupted_blob(): void {
		// Blob достаточной длины, но с невалидным MAC
		$corruptedBlob = str_repeat( "\x00", SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES );
		$this->expectException( RuntimeException::class );
		$this->service->decrypt( $corruptedBlob );
	}

	public function test_decrypt_throws_on_tampered_ciphertext(): void {
		$blob    = $this->service->encrypt( 'original' );
		$tampered = substr_replace( $blob, "\xff", -1 ); // Меняем последний байт
		$this->expectException( RuntimeException::class );
		$this->service->decrypt( $tampered );
	}

	// ── Hash determinism ──────────────────────────────────────────────────────

	public function test_hash_is_deterministic(): void {
		$value = 'Иванов';
		self::assertSame( $this->service->hash( $value ), $this->service->hash( $value ) );
	}

	public function test_hash_normalizes_whitespace_and_case(): void {
		self::assertSame(
			$this->service->hash( 'иванов' ),
			$this->service->hash( '  Иванов  ' )
		);
	}

	public function test_hash_returns_64_char_hex(): void {
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $this->service->hash( 'test' ) );
	}

	// ── isAvailable ───────────────────────────────────────────────────────────

	public function test_is_available_returns_true_with_valid_config(): void {
		self::assertTrue( PiiCryptoService::isAvailable() );
	}
}