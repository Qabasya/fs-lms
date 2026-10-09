<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Course\RoomDTO;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Services\Exam\ExamFormatRegistry;
use Inc\Services\Exam\ExamLaunchChecklist;
use Inc\Services\Exam\ExamNotificationComposer;
use Inc\Services\Exam\Payment\WooGateway;
use Inc\Services\Person\ConsentService;
use Inc\Services\Shared\CenterContactsService;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Чек-лист запуска: по тесту на каждый пункт.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamLaunchChecklistTest extends TestCase {

	use ExamFixtures;

	private WooGateway&MockObject $woo;
	private PluginConfig&MockObject $config;
	private RoomRepository&MockObject $rooms;
	private ExamSessionRepository&MockObject $sessions;
	private ConsentService&MockObject $consents;
	private CenterContactsService&MockObject $contacts;
	private ExamNotificationComposer&MockObject $composer;
	private ExamLaunchChecklist $checklist;

	protected function setUp(): void {
		parent::setUp();

		$this->woo      = $this->createMock( WooGateway::class );
		$this->config   = $this->createMock( PluginConfig::class );
		$this->rooms    = $this->createMock( RoomRepository::class );
		$this->sessions = $this->createMock( ExamSessionRepository::class );
		$this->consents = $this->createMock( ConsentService::class );
		$this->contacts = $this->createMock( CenterContactsService::class );
		$this->composer = $this->createMock( ExamNotificationComposer::class );

		// Всё хорошо по умолчанию; каждый тест ломает один пункт.
		$this->woo->method( 'isActive' )->willReturn( true );
		$this->woo->method( 'product' )->willReturn( array( 'id' => 5, 'name' => 'Экзамен', 'virtual' => true, 'purchasable' => true, 'price' => '500' ) );
		$this->config->method( 'examProductId' )->willReturn( 5 );
		$this->config->method( 'examHoldMinutes' )->willReturn( 20 );
		$this->config->method( 'examIpActiveHoldsLimit' )->willReturn( 40 );
		$this->config->method( 'examIpHourlyLimit' )->willReturn( 60 );
		$this->config->method( 'examSourceActiveHoldsLimit' )->willReturn( 60 );
		$this->contacts->method( 'get' )->willReturn( array( 'phone' => '+7', 'email' => '', 'hours' => '', 'city' => 'Калининград', 'street' => 'ул. Х' ) );
		$this->consents->method( 'getPageForType' )->willReturn( new \WP_Post() );
		$this->composer->method( 'paymentRecipients' )->willReturn( array( 1 ) );
		$this->sessions->method( 'findByEvent' )->willReturn( array( $this->examSession() ) );
		$this->rooms->method( 'find' )->willReturn( RoomDTO::fromArray( array( 'id' => '2', 'name' => '305', 'seats' => '12', 'allowed_subjects' => '[]', 'is_active' => '1' ) ) );

		$this->checklist = $this->build();
	}

	private function build( ?WooGateway $woo = null, ?PluginConfig $config = null, ?RoomRepository $rooms = null, ?ConsentService $consents = null, ?CenterContactsService $contacts = null, ?ExamNotificationComposer $composer = null ): ExamLaunchChecklist {
		return new ExamLaunchChecklist(
			$woo ?? $this->woo, $config ?? $this->config, $rooms ?? $this->rooms, $this->sessions, $consents ?? $this->consents,
			$contacts ?? $this->contacts, $composer ?? $this->composer, $this->createMock( AssessmentManager::class ), $this->createMock( ExamFormatRegistry::class )
		);
	}

	/** @return array<string, array{ok: bool, hint: string}> */
	private function byKey( ExamLaunchChecklist $c ): array {
		$out = array();
		foreach ( $c->check( $this->examEvent() ) as $item ) {
			$out[ $item['key'] ] = $item;
		}

		return $out;
	}

	public function test_ready_when_all_ok(): void {
		self::assertTrue( $this->checklist->isReady( $this->examEvent() ) );
	}

	public function test_woocommerce_inactive(): void {
		$woo = $this->createMock( WooGateway::class );
		$woo->method( 'isActive' )->willReturn( false );
		$items = $this->byKey( $this->build( $woo ) );

		self::assertFalse( $items['woo']['ok'] );
		self::assertSame( 'Включите WooCommerce.', $items['woo']['hint'] );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'unusableProducts' )]
	public function test_product_must_be_usable( ?array $product ): void {
		$woo = $this->createMock( WooGateway::class );
		$woo->method( 'isActive' )->willReturn( true );
		$woo->method( 'product' )->willReturn( $product );
		$items = $this->byKey( $this->build( $woo ) );

		self::assertFalse( $items['product']['ok'] );
		self::assertSame( 'Выберите товар экзамена в настройках.', $items['product']['hint'] );
	}

	/** @return array<string, array{0: ?array}> */
	public static function unusableProducts(): array {
		$base = array( 'id' => 5, 'name' => 'Э', 'virtual' => true, 'purchasable' => true, 'price' => '500' );

		return array(
			'нет товара'        => array( null ),
			'не виртуальный'    => array( array_merge( $base, array( 'virtual' => false ) ) ),
			'недоступен'        => array( array_merge( $base, array( 'purchasable' => false ) ) ),
			'нулевая цена'      => array( array_merge( $base, array( 'price' => '0' ) ) ),
		);
	}

	public function test_room_without_seats(): void {
		$rooms = $this->createMock( RoomRepository::class );
		$rooms->method( 'find' )->willReturn( RoomDTO::fromArray( array( 'id' => '2', 'name' => '305', 'seats' => '0', 'allowed_subjects' => '[]', 'is_active' => '1' ) ) );
		$items = $this->byKey( $this->build( null, null, $rooms ) );

		self::assertFalse( $items['rooms']['ok'] );
		self::assertSame( 'Укажите вместимость кабинета 305.', $items['rooms']['hint'] );
	}

	public function test_empty_contacts(): void {
		$contacts = $this->createMock( CenterContactsService::class );
		$contacts->method( 'get' )->willReturn( array( 'phone' => '', 'email' => '', 'hours' => '', 'city' => '', 'street' => '' ) );
		$items = $this->byKey( $this->build( null, null, null, null, $contacts ) );

		self::assertFalse( $items['contacts']['ok'] );
		self::assertSame( 'Заполните контакты центра.', $items['contacts']['hint'] );
	}

	public function test_missing_consent_page(): void {
		$consents = $this->createMock( ConsentService::class );
		$consents->method( 'getPageForType' )->willReturnCallback( static fn ( string $t ) => 'pd_transfer' === $t ? null : new \WP_Post() );
		$items = $this->byKey( $this->build( null, null, null, $consents ) );

		self::assertFalse( $items['consents']['ok'] );
		self::assertSame( 'Создайте страницу согласия.', $items['consents']['hint'] );
	}

	public function test_limits_out_of_bounds(): void {
		$config = $this->createMock( PluginConfig::class );
		$config->method( 'examProductId' )->willReturn( 5 );
		$config->method( 'examHoldMinutes' )->willReturn( 3 );
		$config->method( 'examIpActiveHoldsLimit' )->willReturn( 40 );
		$config->method( 'examIpHourlyLimit' )->willReturn( 60 );
		$config->method( 'examSourceActiveHoldsLimit' )->willReturn( 60 );
		$items = $this->byKey( $this->build( null, $config ) );

		self::assertFalse( $items['limits']['ok'] );
		self::assertSame( 'Проверьте срок брони и лимиты.', $items['limits']['hint'] );
	}

	public function test_no_payment_recipients(): void {
		$composer = $this->createMock( ExamNotificationComposer::class );
		$composer->method( 'paymentRecipients' )->willReturn( array() );
		$items = $this->byKey( $this->build( null, null, null, null, null, $composer ) );

		self::assertFalse( $items['recipients']['ok'] );
		self::assertSame( 'Назначьте администратора платформы.', $items['recipients']['hint'] );
	}
}
