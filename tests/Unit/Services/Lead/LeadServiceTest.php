<?php

declare( strict_types=1 );

namespace Unit\Services\Lead;

use Inc\DTO\Lead\LeadInputDTO;
use Inc\Repositories\WPDBRepositories\LeadRepository;
use Inc\Services\Lead\LeadService;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LeadServiceTest extends TestCase {

	private LeadRepository&MockObject $leads;
	private PiiCryptoService          $crypto;
	private LeadService               $service;
	private ?LeadInputDTO             $saved = null;

	protected function setUp(): void {
		parent::setUp();
		$this->saved  = null;
		$this->leads  = $this->createMock( LeadRepository::class );
		$this->crypto = new PiiCryptoService();
		$this->service = new LeadService( $this->leads, $this->crypto );

		$this->leads->method( 'create' )->willReturnCallback( function ( LeadInputDTO $input ): int {
			$this->saved = $input;

			return 7;
		} );
	}

	/** @return array<string, mixed> */
	private function lead( array $override = array() ): array {
		return array_merge( array(
			'verdict'           => 'rejected',
			'reason'            => 'name_single_word',
			'name'              => 'Диана',
			'phone'             => '+7 (915) 627-15-97',
			'form_id'           => 'hero',
			'page_url'          => 'https://future-step.ru/',
			'ip'                => '195.209.221.109',
			'user_agent'        => 'Mozilla/5.0',
			'fill_seconds'      => 4,
			'captcha'           => 'passed',
			'captcha_challenge' => false,
			'mobile'            => false,
			'received_at'       => 1791568000,
			'mail_sent'         => null,
		), $override );
	}

	public function test_record_encrypts_personal_data_and_groups_by_subnet(): void {
		self::assertSame( 7, $this->service->record( $this->lead() ) );

		self::assertNotNull( $this->saved );
		self::assertSame( 'rejected', $this->saved->verdict );
		self::assertSame( 'name_single_word', $this->saved->reason );
		self::assertSame( 'Диана', $this->crypto->decrypt( $this->saved->nameEnc ) );
		self::assertSame( '+7 (915) 627-15-97', $this->crypto->decrypt( $this->saved->phoneEnc ) );
		self::assertSame( '195.209.221.0/24', $this->saved->subnet );
		self::assertNull( $this->saved->mailSent );
	}

	public function test_accepted_lead_has_no_reason(): void {
		$this->service->record( $this->lead( array( 'verdict' => 'accepted', 'reason' => 'rate_ip', 'mail_sent' => true ) ) );

		self::assertSame( 'accepted', $this->saved->verdict );
		self::assertSame( '', $this->saved->reason );
		self::assertTrue( $this->saved->mailSent );
	}

	public function test_unknown_reason_is_dropped(): void {
		$this->service->record( $this->lead( array( 'reason' => 'bogus' ) ) );

		self::assertSame( '', $this->saved->reason );
	}

	public function test_unknown_verdict_or_empty_fields_are_ignored(): void {
		self::assertSame( 0, $this->service->record( $this->lead( array( 'verdict' => 'maybe' ) ) ) );
		self::assertSame( 0, $this->service->record( $this->lead( array( 'name' => '  ' ) ) ) );
		self::assertSame( 0, $this->service->record( $this->lead( array( 'phone' => '' ) ) ) );
		self::assertNull( $this->saved );
	}

	public function test_subnet_of_ipv4_ipv6_and_garbage(): void {
		self::assertSame( '10.1.2.0/24', $this->service->subnetOf( '10.1.2.3' ) );
		self::assertSame( '2001:db8:85a3:1::/64', $this->service->subnetOf( '2001:0db8:85a3:0001:0000:8a2e:0370:7334' ) );
		self::assertSame( '', $this->service->subnetOf( 'not-an-ip' ) );
		self::assertSame( '', $this->service->subnetOf( '' ) );
	}
}
