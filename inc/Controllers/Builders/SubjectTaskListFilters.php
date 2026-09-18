<?php

declare( strict_types=1 );

namespace Inc\Controllers\Builders;

use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class SubjectTaskListFilters
 *
 * Фильтры нативной таблицы заданий предмета (`{key}_tasks`): номер задания,
 * таксономии предмета (год, автор, сложность — их заводит сам методист) и
 * автор записи. Аналог {@see ProblemListFilters} для банка задач.
 *
 * @package Inc\Controllers\Builders
 *
 * Разметку печатает шаблон `admin/subject/task-filters` — здесь только данные.
 *
 * Запрос выполнять не нужно: таксономии предмета зарегистрированы с
 * `query_var`, и `edit.php?post_type={key}_tasks&{taxonomy}={slug}` WordPress
 * отбирает сам; то же и с `author`. Поэтому `pre_get_posts` тут нет — в
 * отличие от банка, где фильтры сидят в мете.
 *
 * Список таксономий не зашит: селект строится по каждой таксономии предмета,
 * поэтому «Год» и «Автор» — это те таксономии, что методист завёл у предмета,
 * а не отдельные поля задания.
 */
class SubjectTaskListFilters {

	use Sanitizer;

	/** Статусы, среди которых ищем авторов записей для фильтра «Автор». */
	private const AUTHOR_LOOKUP_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future', 'fs_archived' );

	/**
	 * @param TaxonomyRepository $taxonomies Пользовательские таксономии предмета
	 * @param TermManager        $terms      Термы таксономий
	 * @param PostManager        $posts      Записи заданий (для списка авторов)
	 */
	public function __construct(
		private readonly TaxonomyRepository $taxonomies,
		private readonly TermManager        $terms,
		private readonly PostManager        $posts,
	) {}

	/**
	 * Данные фильтров для шаблона.
	 *
	 * @param string $postType CPT экрана (`{key}_tasks`)
	 *
	 * @return array{
	 *     taxonomies: array<int, array{name: string, options: array<string,string>, selected: string, all_label: string}>,
	 *     author: array{name: string, options: array<int,string>, selected: string, all_label: string}|null
	 * }
	 */
	public function data( string $postType ): array {
		$subjectKey = PostTypeResolver::subjectFromTaskPostType( $postType );

		return array(
			'taxonomies' => $this->taxonomySelects( $subjectKey ),
			'author'     => $this->authorSelect( $postType ),
		);
	}

	/**
	 * Селекты таксономий предмета: сначала номер задания (фиксированная
	 * таксономия), затем пользовательские в порядке их создания.
	 *
	 * Пустая таксономия (ни одного терма) селекта не получает: выбирать в нём
	 * нечего, а строка фильтров и без того длинная.
	 *
	 * @param string $subjectKey Ключ предмета
	 *
	 * @return array<int, array{name: string, options: array<string,string>, selected: string, all_label: string}>
	 */
	private function taxonomySelects( string $subjectKey ): array {
		$selects = array();

		$number = $this->taxonomySelect(
			PostTypeResolver::getTaskTaxonomy( $subjectKey ),
			'Все номера'
		);
		if ( null !== $number ) {
			$selects[] = $number;
		}

		foreach ( $this->taxonomies->getBySubject( $subjectKey ) as $taxonomy ) {
			$select = $this->taxonomySelect( $taxonomy->slug, 'Все: ' . $taxonomy->name );
			if ( null !== $select ) {
				$selects[] = $select;
			}
		}

		return $selects;
	}

	/**
	 * Один селект таксономии: значение — слаг терма, его же ждёт `query_var`
	 * таксономии в адресе экрана.
	 *
	 * @param string $taxonomy Слаг таксономии
	 * @param string $allLabel Подпись пункта «все»
	 *
	 * @return array{name: string, options: array<string,string>, selected: string, all_label: string}|null
	 */
	private function taxonomySelect( string $taxonomy, string $allLabel ): ?array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		$options = array();
		foreach ( $this->terms->getAll( $taxonomy ) as $term ) {
			$options[ $term->slug ] = $term->name;
		}

		if ( array() === $options ) {
			return null;
		}

		return array(
			'name'      => $taxonomy,
			'options'   => $options,
			'selected'  => $this->sanitizeGetText( $taxonomy ),
			'all_label' => $allLabel,
		);
	}

	/**
	 * Селект «Автор» — только когда авторов больше одного: у предмета с единственным
	 * автором фильтр ничего не отбирает.
	 *
	 * @param string $postType CPT экрана
	 *
	 * @return array{name: string, options: array<int,string>, selected: string, all_label: string}|null
	 */
	private function authorSelect( string $postType ): ?array {
		// Без `limit` — значит в пределах потолка PostManager: селект авторов не
		// стоит того, чтобы поднимать в память весь банк предмета целиком
		// (тот же приём у банка задач, {@see ProblemListFilters::authorSelect()}).
		$tasks     = $this->posts->search( $postType, array( 'status' => self::AUTHOR_LOOKUP_STATUSES, 'orderby' => 'ID' ) );
		$authorIds = array_unique( array_map( static fn( $p ): int => (int) $p->post_author, $tasks ) );

		if ( count( $authorIds ) < 2 ) {
			return null;
		}

		$options = array();
		foreach ( $authorIds as $uid ) {
			$user = get_user_by( 'id', $uid );
			if ( false !== $user ) {
				$options[ $uid ] = $user->display_name;
			}
		}

		return array(
			'name'      => 'author',
			'options'   => $options,
			'selected'  => (string) $this->sanitizeGetInt( 'author' ),
			'all_label' => 'Все авторы',
		);
	}
}
