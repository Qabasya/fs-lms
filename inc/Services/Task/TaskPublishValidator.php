<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Template\TemplateRegistry;

class TaskPublishValidator {

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
	 * Validates all meta fields for the given template.
	 * Returns the first error string found, or null if valid.
	 */
	public function getSoftError( array $postMeta, string $templateId ): ?string {
		$template = $this->templateRegistry->get( $templateId );
		if ( null === $template ) {
			return null;
		}

		foreach ( $template->get_fields() as $fieldKey => $config ) {
			$editorType = $config['object']->editorType();
			$label      = $config['label'];
			$value      = $postMeta[ $fieldKey ] ?? null;

			$error = match ( $editorType ) {
				'rich_text'   => trim( strip_tags( (string) $value ) ) === ''
					? "Заполните «{$label}»." : null,
				'text', 'code', 'link' => trim( (string) $value ) === ''
					? "Заполните «{$label}»." : null,
				'options'     => $this->checkOptions( $value ),
				'pairs'       => $this->checkPairs( $value ),
				'order_items' => $this->checkOrderItems( $value ),
				'gap_text'    => $this->checkGapText( $value ),
				'audio'       => $this->checkAudio( $value ),
				default       => null,
			};

			if ( null !== $error ) {
				return $error;
			}
		}

		return null;
	}

	private function checkOptions( mixed $value ): ?string {
		$opts = is_array( $value['options'] ?? null ) ? $value['options'] : array();
		if ( count( $opts ) < 2 ) {
			return 'Добавьте не менее двух вариантов ответа.';
		}
		foreach ( $opts as $opt ) {
			if ( ! empty( $opt['correct'] ) ) {
				return null;
			}
		}
		return 'Отметьте хотя бы один правильный вариант ответа.';
	}

	private function checkPairs( mixed $value ): ?string {
		$pairs = is_array( $value['pairs'] ?? null ) ? $value['pairs'] : array();
		return count( $pairs ) < 2 ? 'Добавьте не менее двух пар для сопоставления.' : null;
	}

	private function checkOrderItems( mixed $value ): ?string {
		$items = is_array( $value['items'] ?? null ) ? $value['items'] : array();
		return count( $items ) < 2 ? 'Добавьте не менее двух элементов для сортировки.' : null;
	}

	private function checkGapText( mixed $value ): ?string {
		$text = (string) ( $value['text'] ?? '' );
		if ( trim( $text ) === '' ) {
			return 'Введите текст задания с пропусками.';
		}
		if ( ! preg_match( '/\[\[.+?\]\]/', $text ) ) {
			return 'Добавьте хотя бы один пропуск [[...]] в тексте.';
		}
		return null;
	}

	private function checkAudio( mixed $value ): ?string {
		return (int) ( $value['attachment_id'] ?? 0 ) === 0 ? 'Прикрепите аудиофайл.' : null;
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
