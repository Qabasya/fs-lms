<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

use Inc\Repositories\OptionsRepositories\TaxonomyRepository;

/**
 * Class TagPaletteService
 *
 * Закрепляет за каждой таксономией предмета один цвет чипа: у номера задания
 * свой, у года свой, у автора свой и т.д.
 *
 * Цвет отдаётся НОМЕРОМ ступени палитры (1..COLORS) — сами значения живут в
 * SCSS (`$tag-palette` во `frontend/_variables.scss`), PHP печатает лишь класс
 * `--c{n}`. Порядок ступеней: фиксированная {key}_task_number первой, затем
 * пользовательские таксономии в порядке хранения; когда таксономий больше,
 * чем цветов, палитра идёт по кругу.
 *
 * @package Inc\Services\Subject
 */
class TagPaletteService {

	/** Сколько ступеней в палитре — должно совпадать с $tag-palette в SCSS. */
	public const COLORS = 6;

	/** @var array<string, array<string, int>> Кэш карт [subject_key][taxonomy] => ступень. */
	private array $maps = array();

	/**
	 * @param TaxonomyRepository $taxonomy_repository Репозиторий таксономий предмета.
	 */
	public function __construct(
		private readonly TaxonomyRepository $taxonomy_repository,
	) {}

	/**
	 * Ступень палитры для таксономии.
	 *
	 * @param string $subject_key Ключ предмета.
	 * @param string $taxonomy    Слаг таксономии.
	 *
	 * @return int 1..COLORS; 0 — таксономия предмету не принадлежит (чип нейтральный).
	 */
	public function colorIndex( string $subject_key, string $taxonomy ): int {
		if ( '' === $subject_key || '' === $taxonomy ) {
			return 0;
		}

		return $this->map( $subject_key )[ $taxonomy ] ?? 0;
	}

	/**
	 * Карта «таксономия → ступень» для предмета (считается один раз за запрос).
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return array<string, int>
	 */
	private function map( string $subject_key ): array {
		if ( isset( $this->maps[ $subject_key ] ) ) {
			return $this->maps[ $subject_key ];
		}

		$slugs = array( PostTypeResolver::getTaskTaxonomy( $subject_key ) );

		foreach ( $this->taxonomy_repository->getBySubject( $subject_key ) as $taxonomy ) {
			$slugs[] = $taxonomy->slug;
		}

		$map = array();

		foreach ( array_values( array_unique( $slugs ) ) as $position => $slug ) {
			$map[ $slug ] = ( $position % self::COLORS ) + 1;
		}

		$this->maps[ $subject_key ] = $map;

		return $map;
	}
}