<?php

declare(strict_types=1);

namespace Unit\Services\Task;

use Inc\DTO\Subject\TaxonomyDataDTO;
use Inc\MetaBoxes\Templates\BaseTemplate;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Task\TaskPublishValidator;
use Inc\Services\Template\TemplateRegistry;
use PHPUnit\Framework\TestCase;

class TaskPublishValidatorTest extends TestCase {

	private TaxonomyRepository $taxonomies;
	private TemplateRegistry $templateRegistry;
	private TaskPublishValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_count_terms'] = 0;

		$this->taxonomies       = $this->createMock( TaxonomyRepository::class );
		$this->templateRegistry = $this->createMock( TemplateRegistry::class );
		$this->validator        = new TaskPublishValidator( $this->taxonomies, $this->templateRegistry );
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	private function makeTax( string $slug, string $name, bool $required ): TaxonomyDataDTO {
		return new TaxonomyDataDTO(
			slug:         $slug,
			name:         $name,
			subject_key:  'math',
			is_required:  $required,
		);
	}

	private function makeTemplate( array $fieldKeys ): BaseTemplate {
		// getSoftError() валидирует поля по editorType(); поля описываем типом + меткой.
		// 'task_answer' → rich_text с меткой «Правильный ответ»; прочие — невалидируемый тип.
		$specs = [
			'task_answer' => [ 'type' => 'rich_text', 'label' => 'Правильный ответ' ],
		];

		$template = new class extends BaseTemplate {
			public function get_id(): string { return 'test_template'; }
			public function get_name(): string { return 'Test Template'; }
		};

		foreach ( $fieldKeys as $key ) {
			$type  = $specs[ $key ]['type']  ?? 'unknown';
			$label = $specs[ $key ]['label'] ?? $key;
			$template->fields[ $key ] = [
				'label'  => $label,
				'object' => new class( $type ) {
					public function __construct( private string $type ) {}
					public function editorType(): string { return $this->type; }
				},
			];
		}

		return $template;
	}

	// ── getBlockingError() ───────────────────────────────────────────────────────

	public function test_blocking_error_when_required_taxonomy_has_no_terms(): void {
		$GLOBALS['_test_wp_count_terms'] = 0;
		$this->taxonomies->method( 'getBySubject' )
			->willReturn( [ $this->makeTax( 'math_topic', 'Тема', true ) ] );

		$error = $this->validator->getBlockingError( 'math_tasks', [] );

		self::assertIsString( $error );
		self::assertStringContainsString( 'Тема', $error );
	}

	public function test_blocking_error_when_required_taxonomy_has_terms_but_none_selected(): void {
		$GLOBALS['_test_wp_count_terms'] = 3;
		$this->taxonomies->method( 'getBySubject' )
			->willReturn( [ $this->makeTax( 'math_topic', 'Тема', true ) ] );

		$error = $this->validator->getBlockingError( 'math_tasks', [] );

		self::assertIsString( $error );
		self::assertStringContainsString( 'Тема', $error );
	}

	public function test_blocking_error_returns_null_when_required_taxonomy_is_selected(): void {
		$GLOBALS['_test_wp_count_terms'] = 3;
		$this->taxonomies->method( 'getBySubject' )
			->willReturn( [ $this->makeTax( 'math_topic', 'Тема', true ) ] );

		$error = $this->validator->getBlockingError( 'math_tasks', [ 'math_topic' => [ 5 ] ] );

		self::assertNull( $error );
	}

	// ── getSoftError() ───────────────────────────────────────────────────────────

	public function test_soft_error_when_template_has_task_answer_field_and_it_is_empty(): void {
		$this->templateRegistry->method( 'get' )->willReturn( $this->makeTemplate( [ 'task_answer' ] ) );

		$error = $this->validator->getSoftError( [ 'task_answer' => '' ], 'some_template' );

		self::assertIsString( $error );
		self::assertStringContainsString( 'Правильный ответ', $error );
	}

	public function test_soft_error_returns_null_when_template_has_no_task_answer_field(): void {
		$this->templateRegistry->method( 'get' )->willReturn( $this->makeTemplate( [ 'other_field' ] ) );

		$error = $this->validator->getSoftError( [], 'some_template' );

		self::assertNull( $error );
	}

	public function test_soft_error_returns_null_for_unknown_template_id(): void {
		$this->templateRegistry->method( 'get' )->willReturn( null );

		$error = $this->validator->getSoftError( [], 'nonexistent_template' );

		self::assertNull( $error );
	}

	public function test_soft_error_skips_optional_field_even_when_empty(): void {
		// #9: поле с флагом 'optional' (эталон FileAnswer) не блокирует публикацию,
		// хотя его editorType требовал бы непустое значение.
		$template = new class extends BaseTemplate {
			public function get_id(): string { return 'test_optional'; }
			public function get_name(): string { return 'Test Optional'; }
		};
		$field = new class {
			public function editorType(): string { return 'rich_text'; }
		};
		$template->fields['solution_text'] = [
			'label'    => 'Решение для проверяющего',
			'object'   => $field,
			'optional' => true,
		];
		$this->templateRegistry->method( 'get' )->willReturn( $template );

		self::assertNull( $this->validator->getSoftError( [ 'solution_text' => '' ], 'test_optional' ) );
	}

	// ── findEmptyRequired() ──────────────────────────────────────────────────────

	public function test_find_empty_required_returns_only_required_taxonomies_with_zero_terms(): void {
		$GLOBALS['_test_wp_count_terms'] = 0;
		$required    = $this->makeTax( 'math_topic', 'Тема', true );
		$notRequired = $this->makeTax( 'math_level', 'Уровень', false );
		$this->taxonomies->method( 'getBySubject' )->willReturn( [ $required, $notRequired ] );

		$result = $this->validator->findEmptyRequired( 'math' );

		self::assertCount( 1, $result );
		self::assertSame( 'math_topic', $result[0]->slug );
	}
}
