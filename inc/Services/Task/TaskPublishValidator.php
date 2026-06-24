<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Template\TemplateRegistry;

class TaskPublishValidator {

	private const REQUIRED_META_FIELDS = array(
		'task_condition' => 'Условие задания',
		'task_answer'    => 'Правильный ответ',
		'task_code'      => 'Листинг кода',
		'file'           => 'Файл задания',
	);

	public function __construct(
		private readonly TaxonomyRepository $taxonomies,
		private readonly TemplateRegistry   $templateRegistry,
	) {}

	/**
	 * Blocking check: required taxonomies.
	 * Distinguishes "no terms exist" from "term not selected".
	 */
	public function getBlockingError( string $postType, array $taxInput ): ?string {
		$subjectKey = PostTypeResolver::subjectFromTaskPostType( $postType );

		foreach ( $this->taxonomies->getBySubject( $subjectKey ) as $tax ) {
			if ( ! $tax->is_required ) {
				continue;
			}

			$termCount = (int) wp_count_terms( array( 'taxonomy' => $tax->slug, 'hide_empty' => false ) );

			if ( $termCount === 0 ) {
				return "В таксономии «{$tax->name}» нет термов — добавьте их перед публикацией.";
			}

			if ( empty( array_filter( (array) ( $taxInput[ $tax->slug ] ?? array() ) ) ) ) {
				return "Обязательная таксономия «{$tax->name}» не заполнена.";
			}
		}

		return null;
	}

	/**
	 * Soft check: required meta fields.
	 */
	public function getSoftError( array $postMeta, string $templateId ): ?string {
		$template = $this->templateRegistry->get( $templateId );

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

	/**
	 * Returns required taxonomies that have no terms yet.
	 * Used to warn the user proactively on the task editor screen.
	 *
	 * @return object[] TaxonomyDataDTO[]
	 */
	public function findEmptyRequired( string $subjectKey ): array {
		$empty = array();

		foreach ( $this->taxonomies->getBySubject( $subjectKey ) as $tax ) {
			if ( ! $tax->is_required ) {
				continue;
			}
			$count = (int) wp_count_terms( array( 'taxonomy' => $tax->slug, 'hide_empty' => false ) );
			if ( $count === 0 ) {
				$empty[] = $tax;
			}
		}

		return $empty;
	}
}
