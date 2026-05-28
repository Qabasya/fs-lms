<?php

namespace Unit\Services;

use PHPUnit\Framework\TestCase;
use Inc\Services\PiiMaskingService;
use Inc\Enums\PiiField;

class PiiMaskingServiceTest extends TestCase {
	private PiiMaskingService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new PiiMaskingService();
	}

	/**
	 * Тест маскирования ФИО (должно отображаться без изменений)
	 */
	public function testMaskFullName(): void {
		$fullName = 'Иванов Иван Иванович';
		$this->assertSame( $fullName, $this->service->mask( $fullName, PiiField::FullName ) );
		$this->assertSame( '', $this->service->mask( '   ', PiiField::FullName ) );
	}

	/**
	 * Тест маскирования Паспорта
	 */
	public function testMaskPass(): void {
// Стандартный паспорт с пробелом (Серия Номер)
		$this->assertSame( '40 •• ••••21', $this->service->mask( '4015 654321', PiiField::Pass ) );

		// Паспорт без пробелов (10 цифр подряд)
		$this->assertSame( '40 •• ••••56', $this->service->mask( '4015123456', PiiField::Pass ) );

		// Пограничный кейс: слишком короткий невалидный ввод
		$this->assertSame( '12 •• ••••34', $this->service->mask( '1234', PiiField::Pass ) );
	}

	/**
	 * Тест маскирования ИНН
	 */
	public function testMaskInn(): void {
// Стандартный ИНН физического лица (12 цифр)
		$this->assertSame( '•••• •••• 1234', $this->service->mask( '770123451234', PiiField::Inn ) );

		// ИНН юридического лица (10 цифр)
		$this->assertSame( '•••• •••• 5678', $this->service->mask( '7701235678', PiiField::Inn ) );

		// Пограничный кейс: слишком короткий невалидный ввод
		$this->assertSame( '•••• •••• 99', $this->service->mask( '99', PiiField::Inn ) );
	}

	/**
	 * Тест маскирования телефона
	 */
	public function testMaskPhone(): void {
		// Чистая строка из 11 цифр без кода страны
		$this->assertSame( '••• ••• 44 86', $this->service->mask( '95279564486', PiiField::Phone ) );

		// Стандартный 10-значный номер с пробелами или дефисами
		$this->assertSame( '••• ••• 12 34', $this->service->mask( '999 888-12-34', PiiField::Phone ) );

		// Пограничный кейс: слишком короткий ввод
		$this->assertSame( '••• ••• •• 77', $this->service->mask( '77', PiiField::Phone ) );
	}

	/**
	 * Тест маскирования адреса
	 */
	public function testMaskAddress(): void {
		$this->assertSame( 'г. Москва, ••••••', $this->service->mask( 'г. Москва, ул. Пушкина, д. Колотушкина', PiiField::Address ) );
		$this->assertSame( 'г. Калининград, ••••••', $this->service->mask( 'г. Калининград', PiiField::Address ) );
	}

	/**
	 * Тест маскирования СНИЛС
	 */
	public function testMaskSnils(): void {
		$this->assertSame( '••• ••• •••-34', $this->service->mask( '123-456-789 34', PiiField::Snils ) );
		$this->assertSame( '••• ••• •••-00', $this->service->mask( '00000000000', PiiField::Snils ) );
	}

	/**
	 * Тест пакетного маскирования (maskBulk)
	 */
	public function testMaskBulk(): void {
		$rawData = array(
			'name'    => 'Петров Петр',
			'pass'=> '4020 998877',
			'some_id' => '12345',
		);

		$types = array(
			'name'     => PiiField::FullName,
			'pass' => PiiField::Pass,
			// 'some_id' умышленно пропускаем, чтобы проверить поведение без указания типа
		);

		$expected = array(
			'name'     => 'Петров Петр',
			'pass' => '40 •• ••••77',
			'some_id'  => '12345',
		);

		$this->assertSame( $expected, $this->service->maskBulk( $rawData, $types ) );
	}

}