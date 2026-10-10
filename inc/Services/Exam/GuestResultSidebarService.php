<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Article\ArticleCardDTO;
use Inc\Services\Course\PublicCourseService;
use Inc\Services\Subject\ArticleService;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Subject\SubjectPagesService;

/**
 * Сайдбар страницы результата гостя (11b.3.1): блок «Курсы» направления и четыре статьи по заданиям с ошибками.
 *
 * «Курсы» — заглушка программы подготовки, как в учебнике (настоящий курс не ищется). Статьи берутся из каталога учебника
 * (`ArticleService::getCatalogCards()`, с его кешем): по одной с каждого ошибочного номера, начиная с наименьшего балла, затем
 * по кругу; недостающие до четырёх — «случайные», но выбор детерминирован участием, поэтому обновление страницы набор не меняет.
 * У предмета без опубликованных статей (ОГЭ пока) блок статей пуст, и партиал его скрывает.
 */
class GuestResultSidebarService {

	/** Сколько статей показывать. */
	public const ARTICLES_LIMIT = 4;

	/** Вердикты, считающиеся ошибкой; `pending` (ручная часть ОГЭ) — нет. */
	private const ERROR_STATUSES = array( 'incorrect', 'unanswered', 'partial' );

	public function __construct(
		private readonly ArticleService $articles,
		private readonly PublicCourseService $courses,
		private readonly SubjectPagesService $pages,
	) {}

	/**
	 * @param list<array<string, mixed>> $units Единицы оценивания результата (`number`, `status`, `score`, `max`).
	 *
	 * @return array{courses: array<int, \Inc\DTO\Course\CourseCardDTO>, articles: list<array<string, string>>, articles_url: string}
	 */
	public function build( string $subjectKey, array $units, int $participationId ): array {
		if ( '' === $subjectKey ) {
			return array( 'courses' => array(), 'articles' => array(), 'articles_url' => '' );
		}

		$links = $this->pages->links( $subjectKey );
		$cards = $this->pick( $subjectKey, $units, $participationId );

		return array(
			'courses'      => $this->courses->getSidebarCourses( $subjectKey, $links->subject ),
			'articles'     => array_map( static fn( ArticleCardDTO $card ): array => array(
				'id'        => $card->id,
				'title'     => $card->title,
				'url'       => $card->url,
				'excerpt'   => $card->excerpt,
				'thumbnail' => $card->thumbnail,
			), $cards ),
			'articles_url' => $links->articles,
		);
	}

	/**
	 * @param list<array<string, mixed>> $units
	 *
	 * @return list<ArticleCardDTO>
	 */
	private function pick( string $subjectKey, array $units, int $seed ): array {
		$numberTax = PostTypeResolver::getTaskTaxonomy( $subjectKey );
		$cards     = $this->articles->getCatalogCards( $subjectKey, array( $numberTax ) );
		if ( empty( $cards ) ) {
			return array();
		}

		$byNumber = array();
		foreach ( $cards as $card ) {
			foreach ( $card->terms[ $numberTax ] ?? array() as $slug ) {
				$byNumber[ $slug ][] = $card;
			}
		}

		$queues = array();
		foreach ( $this->errorSlugs( $subjectKey, $units ) as $slug ) {
			if ( ! empty( $byNumber[ $slug ] ) ) {
				$queues[] = $byNumber[ $slug ];
			}
		}

		$picked = array();
		for ( $round = 0; count( $picked ) < self::ARTICLES_LIMIT; ++$round ) {
			$added = false;
			foreach ( $queues as $queue ) {
				$card = $queue[ $round ] ?? null;
				if ( null === $card || isset( $picked[ $card->id ] ) ) {
					continue;
				}
				$picked[ $card->id ] = $card;
				$added               = true;
				if ( count( $picked ) >= self::ARTICLES_LIMIT ) {
					break 2;
				}
			}
			if ( ! $added ) {
				break;
			}
		}

		$rest = array_filter( $cards, static fn( ArticleCardDTO $card ): bool => ! isset( $picked[ $card->id ] ) );
		usort( $rest, static fn( ArticleCardDTO $a, ArticleCardDTO $b ): int => crc32( $seed . ':' . $a->id ) <=> crc32( $seed . ':' . $b->id ) );

		return array_slice( array_merge( array_values( $picked ), $rest ), 0, self::ARTICLES_LIMIT );
	}

	/**
	 * Слаги термов номеров по ошибочным единицам: от наименьшей доли набранного балла, при равенстве — по номеру.
	 *
	 * @param list<array<string, mixed>> $units
	 *
	 * @return list<string>
	 */
	private function errorSlugs( string $subjectKey, array $units ): array {
		$errors = array_filter( $units, static fn( array $unit ): bool => in_array( (string) ( $unit['status'] ?? '' ), self::ERROR_STATUSES, true ) );

		$ratio = static fn( array $unit ): float => (float) ( $unit['max'] ?? 0 ) > 0 ? (float) ( $unit['score'] ?? 0 ) / (float) $unit['max'] : 0.0;
		usort( $errors, static function ( array $a, array $b ) use ( $ratio ): int {
			return array( $ratio( $a ), (int) $a['number'] ) <=> array( $ratio( $b ), (int) $b['number'] );
		} );

		$slugs = array();
		foreach ( $errors as $unit ) {
			// Сдвоенный номер («19–21») даёт каждый свой номер.
			preg_match_all( '/\d+/', (string) ( $unit['number'] ?? '' ), $found );
			foreach ( $found[0] as $number ) {
				$slugs[] = $subjectKey . '_' . $number;
			}
		}

		return array_values( array_unique( $slugs ) );
	}
}
