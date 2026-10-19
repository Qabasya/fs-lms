<?php

declare(strict_types=1);

namespace Unit\Services\Person;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\PersonDataChangedEvent;
use Inc\DTO\Person\PersonDTO;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Person\PersonService;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\TestCase;

/**
 * Журнал «Изменения данных»: в него попадают только реально изменившиеся поля, а не все присланные формой.
 */
class PersonServiceChangeLogTest extends TestCase {

	/** @var list<PersonDataChangedEvent> */
	private array $events = array();
	private PersonService $service;

	protected function setUp(): void {
		parent::setUp();

		$person = new PersonDTO(
			id: 5, wpUserId: 9, lastName: 'Иванов', firstName: 'Иван', middleName: 'Петрович', birthDate: null, isStudent: true,
			school: 'Школа 5', grade: '11', expelledAt: null, createdAt: '2024-01-01 00:00:00', updatedAt: '2024-01-01 00:00:00',
		);
		$people = $this->createMock( PersonRepository::class );
		$people->method( 'find' )->willReturn( $person );
		$docs  = $this->createMock( PersonDocumentsRepository::class );
		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2024-01-01 00:00:00' );
		$bus = $this->createMock( LogEventDispatcherInterface::class );
		$bus->method( 'dispatch' )->willReturnCallback( function ( $event, $payload ): void {
			$this->events[] = $payload;
		} );

		$this->service = new PersonService( $people, $docs, $this->createMock( PiiCryptoService::class ), $clock, $bus );
	}

	public function test_unchanged_name_fields_are_not_logged(): void {
		$this->service->update( 5, array( 'last_name' => 'Иванов', 'first_name' => 'Иван', 'middle_name' => 'Петрович', 'school' => 'Школа 5', 'grade' => '11' ), 1 );

		self::assertSame( array(), $this->events );
	}

	public function test_only_the_changed_name_field_is_logged_with_its_old_value(): void {
		$this->service->update( 5, array( 'last_name' => 'Сидоров', 'first_name' => 'Иван' ), 1 );

		self::assertCount( 1, $this->events );
		self::assertSame( 'last_name', $this->events[0]->fieldName );
		self::assertSame( 'Иванов', $this->events[0]->oldValue );
		self::assertSame( 'Сидоров', $this->events[0]->newValue );
	}
}
