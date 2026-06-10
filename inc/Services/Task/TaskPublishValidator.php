<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Enums\PostMetaName;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\PostTypeResolver;
use Inc\Services\Template\TemplateRegistry;

/**
 * Validates that all required fields and taxonomies are filled before a task is published.
 *
 * Returns the first validation error as a string, or null if everything is valid.
 */
class TaskPublishValidator {

	/** Meta fields that are required whenever present in the active template. */
	private const REQUIRED_META_FIELDS = array(
		'task_answer' => 'Правильный ответ',
		'task_code'   => 'Листинг кода',
		'file'        => 'Файл задания',
	);

	public function __construct(
		private readonly TaxonomyRepository $taxonomies,
		private readonly TemplateRegistry   $templateRegistry,
	) {}

	/**
	 * @param string $postType  CPT slug (e.g. «math_tasks»)
	 * @param array  $postMeta  $_POST['fs_lms_meta']
	 * @param string $templateId $_POST['fs_lms_template_type']
	 * @param array  $taxInput  $_POST['tax_input']
	 *
	 * @return string|null First validation error, or null if valid.
	 */
	public function validate( string $postType, array $postMeta, string $templateId, array $taxInput ): ?string {
		return $this->validateMetaFields( $postMeta, $templateId )
			?? $this->validateTaxonomies( $postType, $taxInput );
	}

	// ── Private ──────────────────────────────────────────────────────────────────

	private function validateMetaFields( array $postMeta, string $templateId ): ?string {
		$template = $this->templateRegistry->getTemplate( $templateId );

		if ( null === $template ) {
			return null;
		}

		foreach ( self::REQUIRED_META_FIELDS as $fieldKey => $label ) {
			if ( ! array_key_exists( $fieldKey, $template->fields ) ) {
				continue;
			}
			if ( '' === trim( (string) ( $postMeta[ $fieldKey ] ?? '' ) ) ) {
				return "Поле «{$label}» обязательно для заполнения.";
			}
		}

		return null;
	}

	private function validateTaxonomies( string $postType, array $taxInput ): ?string {
		$subjectKey = PostTypeResolver::subjectFromTaskPostType( $postType );

		foreach ( $this->taxonomies->getBySubject( $subjectKey ) as $tax ) {
			if ( ! $tax->is_required ) {
				continue;
			}
			if ( empty( array_filter( (array) ( $taxInput[ $tax->slug ] ?? array() ) ) ) ) {
				return "Обязательная таксономия «{$tax->name}» не заполнена.";
			}
		}

		return null;
	}
}
