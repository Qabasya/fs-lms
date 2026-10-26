<?php

declare( strict_types=1 );

namespace Unit\DTO\Person;

use Inc\DTO\Person\PersonDTO;
use PHPUnit\Framework\TestCase;

/**
 * Имя WP-аккаунта: у ученика «Фамилия Имя», у родителя полное ФИО (лента «Активность», списки групп).
 */
class PersonAccountNameTest extends TestCase {

	private function person( bool $isStudent, ?string $middle ): PersonDTO {
		return PersonDTO::fromArray(
			array(
				'id'          => 1,
				'wp_user_id'  => 5,
				'last_name'   => 'Белова',
				'first_name'  => 'Ксения',
				'middle_name' => $middle,
				'is_student'  => $isStudent ? 1 : 0,
				'created_at'  => '2026-01-01 00:00:00',
				'updated_at'  => '2026-01-01 00:00:00',
			)
		);
	}

	public function test_student_name_has_no_patronymic(): void {
		self::assertSame( 'Белова Ксения', $this->person( true, 'Игоревна' )->accountName() );
		self::assertSame( 'Белова Ксения', $this->person( true, null )->accountName() );
	}

	public function test_parent_name_is_full(): void {
		self::assertSame( 'Белова Ксения Игоревна', $this->person( false, 'Игоревна' )->accountName() );
		self::assertSame( 'Белова Ксения', $this->person( false, null )->accountName() );
	}
}
