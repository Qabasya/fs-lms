<?php

declare( strict_types=1 );

namespace Unit\Services\Import;

use Inc\Enums\DocumentType;
use Inc\Services\Import\DocTypeResolver;
use PHPUnit\Framework\TestCase;

class DocTypeResolverTest extends TestCase {

	private DocTypeResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new DocTypeResolver();
	}

	public function testEmptyReturnsEmpty(): void {
		$this->assertSame( '', $this->resolver->resolve( '' ) );
		$this->assertSame( '', $this->resolver->resolve( '   ' ) );
	}

	public function testPassportLabelResolvesToValue(): void {
		$this->assertSame( DocumentType::Pass->value, $this->resolver->resolve( 'Паспорт' ) );
	}

	public function testBirthCertificateLabelResolvesToValue(): void {
		$this->assertSame(
			DocumentType::BirthCertificate->value,
			$this->resolver->resolve( 'Свидетельство о рождении' )
		);
	}

	public function testResolvesByEnumValueCaseInsensitive(): void {
		$this->assertSame( DocumentType::Pass->value, $this->resolver->resolve( 'PASS' ) );
	}

	public function testUnknownNonEmptyKeptAsIs(): void {
		$this->assertSame( 'Военный билет', $this->resolver->resolve( 'Военный билет' ) );
	}
}
