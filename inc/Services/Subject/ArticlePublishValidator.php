<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

use Inc\DTO\Subject\TaxonomyDataDTO;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;

/**
 * Class ArticlePublishValidator
 *
 * Блокирующие проверки статьи перед публикацией: номер задания и обязательные
 * таксономии предмета, помеченные флагом «Использовать в статьях».
 *
 * @package Inc\Services\Subject
 *
 * Близнец {@see \Inc\Services\Task\TaskPublishValidator} для CPT статей, но без
 * мета-полей: у статьи нет шаблона с полями, проверяются только термины. Набор
 * таксономий у статей уже, чем у заданий — необязательные пользовательские
 * таксономии остаются служебными пометками автора и публикацию не блокируют.
 */
class ArticlePublishValidator {

	public function __construct(
		private readonly TaxonomyRepository $taxonomies,
	) {}

	/**
	 * Первая блокирующая ошибка публикации статьи или null, если всё заполнено.
	 *
	 * @param string               $postType CPT статей ({key}_articles).
	 * @param array<string, mixed> $taxInput Карта [слаг таксономии => привязки].
	 *
	 * @return string|null
	 */
	public function getBlockingError( string $postType, array $taxInput ): ?string {
		$subjectKey = PostTypeResolver::subjectFromArticlePostType( $postType );

		$numberTaxonomy = PostTypeResolver::getTaskTaxonomy( $subjectKey );
		if ( ! $this->isFilled( $taxInput, $numberTaxonomy ) ) {
			return 'Укажите номер задания, к которому относится статья.';
		}

		foreach ( $this->requiredForArticles( $subjectKey ) as $tax ) {
			// wp_count_terms() — количество терминов таксономии
			$termCount = (int) wp_count_terms( array( 'taxonomy' => $tax->slug, 'hide_empty' => false ) );

			if ( 0 === $termCount ) {
				return "В таксономии «{$tax->name}» нет термов — добавьте их перед публикацией.";
			}

			if ( ! $this->isFilled( $taxInput, $tax->slug ) ) {
				return "Обязательная таксономия «{$tax->name}» не заполнена.";
			}
		}

		return null;
	}

	/**
	 * Обязательные для статей таксономии, в которых ещё нет ни одного терма.
	 *
	 * Предупреждение на экране статьи: автор видит проблему до попытки публикации.
	 *
	 * @param string $subjectKey Ключ предмета.
	 *
	 * @return TaxonomyDataDTO[]
	 */
	public function findEmptyRequired( string $subjectKey ): array {
		$empty = array();

		foreach ( $this->requiredForArticles( $subjectKey ) as $tax ) {
			if ( 0 === (int) wp_count_terms( array( 'taxonomy' => $tax->slug, 'hide_empty' => false ) ) ) {
				$empty[] = $tax;
			}
		}

		return $empty;
	}

	/**
	 * Таксономии предмета, обязательные именно для статей: обязательная + привязанная к статьям.
	 *
	 * @param string $subjectKey Ключ предмета.
	 *
	 * @return TaxonomyDataDTO[]
	 */
	public function requiredForArticles( string $subjectKey ): array {
		return array_values(
			array_filter(
				$this->taxonomies->getBySubject( $subjectKey ),
				static fn( TaxonomyDataDTO $tax ): bool => $tax->is_required && $tax->use_in_articles
			)
		);
	}

	/**
	 * Есть ли у статьи непустая привязка к указанной таксономии.
	 *
	 * Форма редактора шлёт пустую строку-заглушку в `tax_input` (метабокс плагина
	 * добавляет её, чтобы снятие всех значений доезжало до сервера), поэтому голого
	 * `isset()` мало — значения фильтруются.
	 *
	 * @param array<string, mixed> $taxInput Карта [слаг таксономии => привязки].
	 * @param string               $slug     Слаг таксономии.
	 *
	 * @return bool
	 */
	private function isFilled( array $taxInput, string $slug ): bool {
		return ! empty( array_filter( (array) ( $taxInput[ $slug ] ?? array() ) ) );
	}
}
