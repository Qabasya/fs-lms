<?php

declare( strict_types=1 );

namespace Tests\Unit\DTO\Exam;

use Inc\DTO\Exam\ExamSourceDTO;
use PHPUnit\Framework\TestCase;

/** Скидка по ссылке преподавателя: 100 % — запись без оплаты, у открытой записи скидки нет, строка до миграции читается по `is_free`. */
class ExamSourceDiscountTest extends TestCase {

	/** @param array<string, mixed> $override */
	private function source( array $override = array() ): ExamSourceDTO {
		return ExamSourceDTO::fromArray( array_merge( array(
			'id' => '1', 'event_id' => '3', 'school_key' => 'sch5', 'school_name' => 'Школа 5', 'school_name_normalized' => 'школа 5',
			'grade' => '11', 'teacher_name' => 'Иванова', 'label' => 'Школа 5, 11 класс', 'is_active' => '1', 'key_generation' => '1',
			'created_by_user_id' => '2', 'version' => '1', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00',
		), $override ) );
	}

	public function test_partial_discount_is_not_free(): void {
		$source = $this->source( array( 'discount_percent' => '30' ) );

		self::assertSame( 30, $source->effectiveDiscount() );
		self::assertFalse( $source->isFree() );
	}

	public function test_hundred_percent_is_free(): void {
		self::assertTrue( $this->source( array( 'discount_percent' => '100' ) )->isFree() );
	}

	public function test_public_registration_never_has_discount(): void {
		$source = $this->source( array( 'school_key' => ExamSourceDTO::PUBLIC_KEY, 'discount_percent' => '100' ) );

		self::assertSame( 0, $source->effectiveDiscount() );
		self::assertFalse( $source->isFree() );
	}

	public function test_legacy_free_flag_reads_as_full_discount(): void {
		self::assertTrue( $this->source( array( 'is_free' => '1' ) )->isFree() );
		self::assertSame( 0, $this->source( array( 'is_free' => '0' ) )->effectiveDiscount() );
	}
}
