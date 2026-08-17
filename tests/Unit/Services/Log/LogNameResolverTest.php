<?php

declare( strict_types=1 );

namespace Unit\Services\Log;

use FakeWpdb;
use Inc\Services\Log\LogNameResolver;
use PHPUnit\Framework\TestCase;

/**
 * Регрессия: personName()/entityName() падали с "Class MetaKeys not found" —
 * отсутствовал use Inc\Enums\Wp\MetaKeys в LogNameResolver (namespace Inc\Services\Log).
 * Ловилось только при реальном рендере вкладок логов с person_id/entityType
 * student|parent|teacher (Действия, Зачисления, Доступ к ПД).
 */
class LogNameResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new FakeWpdb();
	}

	public function test_person_name_falls_back_to_persons_table_when_no_linked_wp_user(): void {
		$GLOBALS['wpdb']->queueVar( null );          // usermeta lookup — нет привязанного WP-пользователя
		$GLOBALS['wpdb']->queueVar( 'Иванов Иван' );  // fallback: таблица persons

		self::assertSame( 'Иванов Иван', LogNameResolver::personName( 29 ) );
	}

	public function test_person_name_returns_placeholder_when_nowhere_found(): void {
		$GLOBALS['wpdb']->queueVar( null );
		$GLOBALS['wpdb']->queueVar( null );

		self::assertSame( 'Person #29', LogNameResolver::personName( 29 ) );
	}

	public function test_person_name_returns_dash_for_empty_id(): void {
		self::assertSame( '—', LogNameResolver::personName( null ) );
	}

	/** entityName() для типа 'parent' делегирует в personName() — тот самый путь к MetaKeys. */
	public function test_entity_name_resolves_parent_via_person_name(): void {
		$GLOBALS['wpdb']->queueVar( null );
		$GLOBALS['wpdb']->queueVar( null );

		self::assertSame( 'Person #29', LogNameResolver::entityName( 29, 'parent', 'fallback label' ) );
	}
}
