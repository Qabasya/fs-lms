<?php

declare( strict_types=1 );

namespace Inc\Controllers\Builders;

use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Course\WorkType;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\BankUsageIndex;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class BankListFilters
 *
 * Фильтры над нативными таблицами банков контента: описание выпадающих списков
 * (тип работы, вид контрольной, использование, автор) и применение их к запросу.
 *
 * @package Inc\Controllers\Builders
 *
 * Разметку печатает шаблон `admin/learning/bank-filters` — здесь только данные
 * и работа с `WP_Query`.
 */
class BankListFilters {

	use Sanitizer;

	/** Статусы, среди которых ищем авторов для фильтра «Автор». */
	private const AUTHOR_LOOKUP_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'fs_archived' );

	/**
	 * @param BankUsageIndex $usage Индексы «потребитель → ссылки»
	 * @param PostManager    $posts Доступ к записям банка
	 */
	public function __construct(
		private readonly BankUsageIndex $usage,
		private readonly PostManager    $posts,
	) {}

	/**
	 * Описание выпадающих фильтров для экрана банка.
	 *
	 * @param string $postType CPT текущего экрана
	 *
	 * @return array<int, array{name: string, options: array, selected: string, all_label: string}>
	 */
	public function selectsFor( string $postType ): array {
		if ( PostTypeResolver::isWorkPostType( $postType ) ) {
			$subject = PostTypeResolver::subjectFromWorkPostType( $postType );

			return array_values( array_filter( array(
				$this->select( 'fs_work_type', WorkType::options(), 'Все типы' ),
				$this->usageSelect( 'fs_work_usage', $this->usage->lessonsByWork( $subject ), 'Все работы' ),
				$this->authorSelect( $postType ),
			) ) );
		}

		if ( PostTypeResolver::isAssessmentPostType( $postType ) ) {
			$subject = PostTypeResolver::subjectFromAssessmentPostType( $postType );

			return array_values( array_filter( array(
				$this->select( 'fs_assessment_kind', array_column( AssessmentKind::options(), 'label', 'value' ), 'Все виды' ),
				$this->usageSelect( 'fs_assessment_usage', $this->usage->lessonsByAssessment( $subject ), 'Все контрольные' ),
				$this->authorSelect( $postType ),
			) ) );
		}

		if ( PostTypeResolver::isLessonPostType( $postType ) ) {
			$subject = PostTypeResolver::subjectFromLessonPostType( $postType );

			return array_values( array_filter( array(
				$this->usageSelect( 'fs_lesson_usage', $this->usage->coursesByLesson( $subject ), 'Все уроки' ),
				$this->authorSelect( $postType ),
			) ) );
		}

		return array();
	}

	/**
	 * Применяет выбранные фильтры к запросу списка банка.
	 *
	 * @param \WP_Query $query Основной запрос экрана
	 *
	 * @return void
	 */
	public function apply( \WP_Query $query ): void {
		$postType = $query->get( 'post_type' );

		if ( PostTypeResolver::isWorkPostType( $postType ) ) {
			$this->applyMeta( $query, PostMetaName::WorkType->value, $this->sanitizeGetKey( 'fs_work_type' ) );
			$this->applyUsage(
				$query,
				$this->sanitizeGetKey( 'fs_work_usage' ),
				fn(): array => $this->usage->lessonsByWork( PostTypeResolver::subjectFromWorkPostType( $postType ) )
			);

			return;
		}

		if ( PostTypeResolver::isAssessmentPostType( $postType ) ) {
			$this->applyMeta( $query, PostMetaName::AssessmentKind->value, $this->sanitizeGetKey( 'fs_assessment_kind' ) );
			$this->applyUsage(
				$query,
				$this->sanitizeGetKey( 'fs_assessment_usage' ),
				fn(): array => $this->usage->lessonsByAssessment( PostTypeResolver::subjectFromAssessmentPostType( $postType ) )
			);

			return;
		}

		if ( PostTypeResolver::isLessonPostType( $postType ) ) {
			$this->applyUsage(
				$query,
				$this->sanitizeGetKey( 'fs_lesson_usage' ),
				fn(): array => $this->usage->coursesByLesson( PostTypeResolver::subjectFromLessonPostType( $postType ) )
			);
		}
	}

	/**
	 * Простой фильтр по мете записи.
	 *
	 * @param \WP_Query $query Запрос
	 * @param string    $key   Ключ меты
	 * @param string    $value Выбранное значение ('' — фильтр не задан)
	 *
	 * @return void
	 */
	private function applyMeta( \WP_Query $query, string $key, string $value ): void {
		if ( '' === $value ) {
			return;
		}

		$query->set( 'meta_query', array( array( 'key' => $key, 'value' => $value ) ) );
	}

	/**
	 * Фильтр по использованию: конкретный потребитель или «не используется».
	 *
	 * Индекс строится лениво — только когда фильтр реально выбран.
	 *
	 * @param \WP_Query        $query      Запрос
	 * @param string           $usage      'orphan' | ID потребителя | ''
	 * @param callable(): array $indexMaker Построение индекса
	 *
	 * @return void
	 */
	private function applyUsage( \WP_Query $query, string $usage, callable $indexMaker ): void {
		if ( '' === $usage ) {
			return;
		}

		$index = $indexMaker();

		if ( 'orphan' === $usage ) {
			$query->set( 'post__not_in', $this->usage->usedIds( $index ) );

			return;
		}

		if ( is_numeric( $usage ) ) {
			$ids = $this->usage->idsFor( $index, (int) $usage );
			$query->set( 'post__in', empty( $ids ) ? array( 0 ) : $ids );
		}
	}

	/**
	 * Описание одного селекта.
	 *
	 * @param string $name     Имя GET-параметра
	 * @param array  $options  Варианты value => label
	 * @param string $allLabel Подпись «все»
	 *
	 * @return array{name: string, options: array, selected: string, all_label: string}
	 */
	private function select( string $name, array $options, string $allLabel ): array {
		return array(
			'name'      => $name,
			'options'   => $options,
			'selected'  => $this->sanitizeGetKey( $name ),
			'all_label' => $allLabel,
		);
	}

	/**
	 * Селект «использование»: потребители + «не используется».
	 *
	 * @param string $name     Имя GET-параметра
	 * @param array  $index    Индекс использования
	 * @param string $allLabel Подпись «все»
	 *
	 * @return array{name: string, options: array, selected: string, all_label: string}
	 */
	private function usageSelect( string $name, array $index, string $allLabel ): array {
		return $this->select(
			$name,
			array( 'orphan' => 'Не используется' ) + $this->usage->consumerOptions( $index ),
			$allLabel
		);
	}

	/**
	 * Селект «Автор» — только когда авторов больше одного.
	 *
	 * @param string $postType CPT банка
	 *
	 * @return array{name: string, options: array, selected: string, all_label: string}|null
	 */
	private function authorSelect( string $postType ): ?array {
		$posts     = $this->posts->search( $postType, array( 'status' => self::AUTHOR_LOOKUP_STATUSES ) );
		$authorIds = array_unique( array_map( static fn( $p ): int => (int) $p->post_author, $posts ) );

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
