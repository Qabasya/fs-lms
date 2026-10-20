<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Application;

use Inc\Services\Application\ApplicationService;
use PHPUnit\Framework\TestCase;

/**
 * Город формы заявки: для Калининграда школа как есть, для другого — «Город - Школа».
 */
class SchoolWithCityTest extends TestCase {

	public function test_default_city_keeps_school_as_is(): void {
		self::assertSame( 'МАОУ СОШ № 1', ApplicationService::schoolWithCity( 'Калининград', 'МАОУ СОШ № 1' ) );
		self::assertSame( 'МАОУ СОШ № 1', ApplicationService::schoolWithCity( ' калининград ', 'МАОУ СОШ № 1' ) );
		self::assertSame( 'МАОУ СОШ № 1', ApplicationService::schoolWithCity( '', 'МАОУ СОШ № 1' ) );
	}

	public function test_other_city_is_prefixed(): void {
		self::assertSame( 'Москва - МАОУ СОШ №1', ApplicationService::schoolWithCity( 'Москва', 'МАОУ СОШ №1' ) );
	}
}
