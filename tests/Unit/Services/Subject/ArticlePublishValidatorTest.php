<?php

declare( strict_types=1 );

namespace Unit\Services\Subject;

use Inc\DTO\Subject\TaxonomyDataDTO;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\ArticlePublishValidator;
use PHPUnit\Framework\TestCase;

/**
 * Публикация статьи требует номер задания и обязательные таксономии предмета,
 * помеченные флагом «Использовать в статьях». Необязательные и не привязанные
 * к статьям таксономии публикацию не блокируют.
 */
class ArticlePublishValidatorTest extends TestCase {

	private TaxonomyRepository      $taxonomies;
	private ArticlePublishValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_count_terms'] = 1;

		$this->taxonomies = $this->createMock( TaxonomyRepository::class );
		$this->validator  = new ArticlePublishValidator( $this->taxonomies );
	}

	private function makeTax( string $slug, string $name, bool $required, bool $inArticles ): TaxonomyDataDTO {
		return new TaxonomyDataDTO(
			slug:            $slug,
			name:            $name,
			subject_key:     'math',
			is_required:     $required,
			use_in_articles: $inArticles,
		);
	}

	public function test_missing_task_number_blocks_publication(): void {
		$this->taxonomies->method( 'getBySubject' )->willReturn( array() );

		$error = $this->validator->getBlockingError( 'math_articles', array() );

		self::assertSame( 'Укажите номер задания, к которому относится статья.', $error );
	}

	public function test_task_number_alone_is_enough_without_required_taxonomies(): void {
		$this->taxonomies->method( 'getBySubject' )->willReturn( array() );

		$error = $this->validator->getBlockingError(
			'math_articles',
			array( 'math_task_number' => array( 12 ) )
		);

		self::assertNull( $error );
	}

	public function test_required_article_taxonomy_must_be_filled(): void {
		$this->taxonomies->method( 'getBySubject' )->willReturn( array(
			$this->makeTax( 'math_section', 'Раздел', true, true ),
		) );

		$error = $this->validator->getBlockingError(
			'math_articles',
			array( 'math_task_number' => array( 12 ) )
		);

		self::assertSame( 'Обязательная таксономия «Раздел» не заполнена.', $error );
	}

	public function test_empty_stub_value_does_not_count_as_filled(): void {
		$this->taxonomies->method( 'getBySubject' )->willReturn( array() );

		// Метабокс плагина всегда шлёт пустую строку-заглушку, чтобы снятие всех
		// значений доезжало до сервера — она не должна считаться заполнением.
		$error = $this->validator->getBlockingError(
			'math_articles',
			array( 'math_task_number' => array( '' ) )
		);

		self::assertSame( 'Укажите номер задания, к которому относится статья.', $error );
	}

	public function test_taxonomy_without_terms_reports_missing_terms(): void {
		$GLOBALS['_test_wp_count_terms'] = 0;

		$this->taxonomies->method( 'getBySubject' )->willReturn( array(
			$this->makeTax( 'math_section', 'Раздел', true, true ),
		) );

		$error = $this->validator->getBlockingError(
			'math_articles',
			array( 'math_task_number' => array( 12 ) )
		);

		self::assertSame( 'В таксономии «Раздел» нет термов — добавьте их перед публикацией.', $error );
	}

	public function test_required_task_only_taxonomy_does_not_block_article(): void {
		// Обязательная, но без флага «в статьях» — это правило заданий, статьи её не знают.
		$this->taxonomies->method( 'getBySubject' )->willReturn( array(
			$this->makeTax( 'math_source', 'Источник', true, false ),
		) );

		$error = $this->validator->getBlockingError(
			'math_articles',
			array( 'math_task_number' => array( 12 ) )
		);

		self::assertNull( $error );
	}

	public function test_optional_article_taxonomy_does_not_block_article(): void {
		// Необязательная + в статьях — личная пометка автора, публикацию не трогает.
		$this->taxonomies->method( 'getBySubject' )->willReturn( array(
			$this->makeTax( 'math_favorites', 'Мои любимые', false, true ),
		) );

		$error = $this->validator->getBlockingError(
			'math_articles',
			array( 'math_task_number' => array( 12 ) )
		);

		self::assertNull( $error );
	}

	public function test_filled_required_taxonomy_passes(): void {
		$this->taxonomies->method( 'getBySubject' )->willReturn( array(
			$this->makeTax( 'math_section', 'Раздел', true, true ),
		) );

		$error = $this->validator->getBlockingError(
			'math_articles',
			array(
				'math_task_number' => array( 12 ),
				'math_section'     => array( 3 ),
			)
		);

		self::assertNull( $error );
	}

	public function test_required_for_articles_filters_by_both_flags(): void {
		$this->taxonomies->method( 'getBySubject' )->willReturn( array(
			$this->makeTax( 'math_section', 'Раздел', true, true ),
			$this->makeTax( 'math_source', 'Источник', true, false ),
			$this->makeTax( 'math_favorites', 'Мои любимые', false, true ),
		) );

		$slugs = array_map(
			static fn( TaxonomyDataDTO $tax ): string => $tax->slug,
			$this->validator->requiredForArticles( 'math' )
		);

		self::assertSame( array( 'math_section' ), $slugs );
	}

	public function test_find_empty_required_reports_only_article_taxonomies(): void {
		$GLOBALS['_test_wp_count_terms'] = 0;

		$this->taxonomies->method( 'getBySubject' )->willReturn( array(
			$this->makeTax( 'math_section', 'Раздел', true, true ),
			$this->makeTax( 'math_source', 'Источник', true, false ),
		) );

		$empty = $this->validator->findEmptyRequired( 'math' );

		self::assertCount( 1, $empty );
		self::assertSame( 'math_section', $empty[0]->slug );
	}
}
