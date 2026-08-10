<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

use Inc\DTO\Article\ArticleSlugChangeDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\ArticleRepository;

/**
 * Class ArticleSlugPlanner
 *
 * Пакетное приведение слагов статей предмета к действующему правилу.
 *
 * Отличается от {@see ArticleSlugService} задачей: сервис занимает следующий
 * свободный номер по одной статье и дырки в нумерации не трогает, а планировщик
 * перенумеровывает серию целиком по порядку чтения — тому же, по которому блок
 * навигации считает «N из M» ({@see ArticleService::getNavigation()}).
 *
 * План отделён от применения намеренно: WP-CLI показывает его при `--dry-run`
 * ровно тем же кодом, каким потом переименовывает.
 *
 * @package Inc\Services\Subject
 */
readonly class ArticleSlugPlanner {

	/** Статусы, у которых адрес уже опубликован: им нужен редирект со старого слага. */
	private const PUBLISHED_STATUSES = array( 'publish', 'future', 'private', 'fs_archived' );

	/** Неопубликованные статусы — нумеруются после опубликованных. */
	private const DRAFT_STATUSES = array( 'draft', 'pending' );

	/** Временный слаг на первой фазе применения: `fs-reslug-tmp-{ID}`. */
	private const TEMP_PREFIX = 'fs-reslug-tmp-';

	/**
	 * @param ArticleRepository  $articles Выборки статей.
	 * @param TermManager        $terms    Термины таксономий.
	 * @param PostManager        $posts    Записи WordPress.
	 * @param ArticleSlugService $slugs    Правило слага.
	 */
	public function __construct(
		private ArticleRepository $articles,
		private TermManager $terms,
		private PostManager $posts,
		private ArticleSlugService $slugs,
	) {}

	/**
	 * Строит план переименования: только те статьи, у которых слаг реально меняется.
	 *
	 * Черновики нумеруются после опубликованных и отдельным флагом: иначе флаг
	 * сдвигал бы номера уже изданных статей, то есть менял живые адреса.
	 *
	 * @param string $subject_key    Ключ предмета.
	 * @param bool   $include_drafts Включить черновики.
	 *
	 * @return ArticleSlugChangeDTO[]
	 */
	public function plan( string $subject_key, bool $include_drafts = false ): array {
		$post_type = PostTypeResolver::articles( $subject_key );
		$taxonomy  = PostTypeResolver::getTaskTaxonomy( $subject_key );
		$changes   = array();

		foreach ( $this->terms->getAll( $taxonomy ) as $term ) {
			$number = $this->numberFromTerm( $term );

			if ( null === $number ) {
				continue;
			}

			$series = $this->articles->findAllInTerm( $post_type, (int) $term->term_id, $taxonomy, self::PUBLISHED_STATUSES );

			if ( $include_drafts ) {
				$series = array_merge(
					$series,
					$this->articles->findAllInTerm( $post_type, (int) $term->term_id, $taxonomy, self::DRAFT_STATUSES )
				);
			}

			$changes = array_merge( $changes, $this->series( $series, $number ) );
		}

		$orphans = $this->articles->findWithoutTerm( $post_type, $taxonomy, self::PUBLISHED_STATUSES );

		if ( $include_drafts ) {
			$orphans = array_merge(
				$orphans,
				$this->articles->findWithoutTerm( $post_type, $taxonomy, self::DRAFT_STATUSES )
			);
		}

		return array_merge( $changes, $this->series( $orphans, null ) );
	}

	/**
	 * Применяет план.
	 *
	 * Две фазы: сначала все участники уезжают на временные слаги, потом на
	 * целевые. Иначе перестановка внутри серии («вторая статья становится
	 * первой», когда первая ещё жива) дала бы двух тёзок по `post_name` —
	 * ядро на этом пути слаги не проверяет вовсе.
	 *
	 * @param ArticleSlugChangeDTO[] $changes План.
	 *
	 * @return int Сколько статей переименовано.
	 */
	public function apply( array $changes ): int {
		foreach ( $changes as $change ) {
			$this->posts->renameSlug( $change->post_id, self::TEMP_PREFIX . $change->post_id );
		}

		foreach ( $changes as $change ) {
			$this->posts->renameSlug( $change->post_id, $change->new_slug );

			if ( ! in_array( $change->status, self::PUBLISHED_STATUSES, true ) ) {
				continue;
			}

			// Старый адрес отдаст 301 штатным wp_old_slug_redirect(), а замок
			// заодно проставляется статьям, изданным до появления автогенерации.
			$this->posts->rememberOldSlug( $change->post_id, $change->old_slug );
			$this->posts->updateMeta( $change->post_id, PostMetaName::ArticleSlugLocked->value, 1 );
		}

		return count( $changes );
	}

	/**
	 * Нумерует одну серию статей по порядку чтения.
	 *
	 * @param \WP_Post[] $posts       Статьи серии, старые первыми.
	 * @param int|null   $task_number Номер задания; null — серия без задания.
	 *
	 * @return ArticleSlugChangeDTO[] Только реальные изменения.
	 */
	private function series( array $posts, ?int $task_number ): array {
		$changes = array();
		$ordinal = 0;

		foreach ( $posts as $post ) {
			++$ordinal;

			$change = new ArticleSlugChangeDTO(
				post_id:     (int) $post->ID,
				title:       (string) $post->post_title,
				old_slug:    (string) $post->post_name,
				new_slug:    $this->slugs->compose( $task_number, $ordinal ),
				status:      (string) $post->post_status,
				task_number: $task_number,
				ordinal:     $ordinal,
			);

			if ( $change->isChange() ) {
				$changes[] = $change;
			}
		}

		return $changes;
	}

	/**
	 * Номер задания из терма. Имя терма гарантированно число — {@see TaskNumberTermGuard}.
	 *
	 * @param \WP_Term $term Терм таксономии номеров.
	 *
	 * @return int|null
	 */
	private function numberFromTerm( \WP_Term $term ): ?int {
		$name = trim( (string) $term->name );

		return 1 === preg_match( '/^[1-9][0-9]*$/', $name ) ? (int) $name : null;
	}
}