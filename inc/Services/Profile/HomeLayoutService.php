<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Managers\Person\UserManager;

/**
 * Class HomeLayoutService
 *
 * Раскладка главной ученика/родителя («Кастомизация»), своя у каждого пользователя:
 *  - `sections` — порядок трёх секций (`tiles`, `schedule`, `deadlines`);
 *  - `tiles` — набор плиток (до шести) по id; `null` — «по умолчанию» (набор собирает кабинет), пустой список — все убраны.
 *
 * Из браузера приходит что угодно, поэтому сохраняется только проверенное по белому списку: секции — ровно перестановка
 * трёх известных, плитки — известные id без повторов, не больше {@see self::MAX_TILES}.
 *
 * @package Inc\Services\Profile
 */
class HomeLayoutService {

	public const SECTIONS  = array( 'tiles', 'schedule', 'deadlines' );
	public const MAX_TILES = 6;

	/** Плитка: известный id либо «До экзамена» по предмету (`exam:ключ_предмета`). */
	private const TILE_ID = '/^(?:deadlines|attendance|streak|to_fix|last_result|exam:[a-z0-9_]+)$/';

	public function __construct(
		private readonly UserManager $users,
	) {}

	/** @return array{sections: list<string>, tiles: list<string>|null} */
	public function defaultLayout(): array {
		return array( 'sections' => self::SECTIONS, 'tiles' => null );
	}

	/**
	 * Приводит присланную раскладку к допустимой.
	 *
	 * @return array{sections: list<string>, tiles: list<string>|null}
	 */
	public function normalize( mixed $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();

		return array(
			'sections' => $this->sections( $raw['sections'] ?? null ),
			'tiles'    => $this->tiles( $raw['tiles'] ?? null ),
		);
	}

	/** Сохранённая раскладка пользователя (проверенная) либо по умолчанию. */
	public function get( int $userId ): array {
		return $this->normalize( $this->users->homeLayout( $userId ) );
	}

	/** Сохраняет и возвращает проверенную раскладку. */
	public function save( int $userId, mixed $raw ): array {
		$layout = $this->normalize( $raw );
		$this->users->setHomeLayout( $userId, $layout );

		return $layout;
	}

	/** @return list<string> */
	private function sections( mixed $value ): array {
		if ( ! is_array( $value ) || count( $value ) !== count( self::SECTIONS ) ) {
			return self::SECTIONS;
		}
		$list = array_values( array_map( 'strval', $value ) );
		$sorted = $list;
		sort( $sorted );
		$known = self::SECTIONS;
		sort( $known );

		return $sorted === $known ? $list : self::SECTIONS;
	}

	/** @return list<string>|null */
	private function tiles( mixed $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$tiles = array();
		foreach ( $value as $id ) {
			$id = is_string( $id ) ? $id : '';
			if ( 1 === preg_match( self::TILE_ID, $id ) && ! in_array( $id, $tiles, true ) ) {
				$tiles[] = $id;
			}
			if ( count( $tiles ) >= self::MAX_TILES ) {
				break;
			}
		}

		return $tiles;
	}
}
