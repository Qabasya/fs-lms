<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Managers\Person\UserManager;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Profile\LearnerScreen;

/**
 * Class HomeLayoutService
 *
 * Раскладка главной ролей с фронт-кабинетом («Кастомизация»), своя у каждого пользователя:
 *  - `sections` — порядок трёх секций (`tiles`, `schedule`, `deadlines`; у сотрудников — `attention` вместо `deadlines`);
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

	/** Плитка ученика/родителя: известный id или показатель по предмету. */
	private const TILE_ID = '/^(?:deadlines|attendance|streak|to_fix|last_result|(?:exam|ranking):[a-z0-9_]+)$/';

	public const STAFF_SECTIONS = array( 'tiles', 'schedule', 'attention' );

	public function __construct(
		private readonly UserManager $users,
	) {}

	/** @return array{sections: list<string>, tiles: list<string>|null} */
	public function defaultLayout( ?UserRole $role = null ): array {
		return array( 'sections' => $this->sectionIds( $role ), 'tiles' => null );
	}

	/**
	 * Приводит присланную раскладку к допустимой.
	 *
	 * @return array{sections: list<string>, tiles: list<string>|null}
	 */
	public function normalize( mixed $raw, ?UserRole $role = null, ?array $menuKeys = null ): array {
		$raw = is_array( $raw ) ? $raw : array();

		$layout = array(
			'sections' => $this->sections( $raw['sections'] ?? null, $role ),
			'tiles'    => $this->tiles( $raw['tiles'] ?? null, $role ),
		);
		if ( array_key_exists( 'menuOrder', $raw ) || array_key_exists( 'menuHidden', $raw ) ) {
			$allowed = $menuKeys ?? $this->menuKeys( $role );
			$layout['menuOrder'] = $this->menuList( $raw['menuOrder'] ?? array(), $allowed );
			$layout['menuHidden'] = in_array( $role, array( UserRole::FSTeacher, UserRole::FSOffice ), true )
				? $this->menuList( $raw['menuHidden'] ?? array(), $allowed ) : array();
		}
		return $layout;
	}

	/** Сохранённая раскладка пользователя (проверенная) либо по умолчанию. */
	public function get( int $userId, ?UserRole $role = null, ?array $menuKeys = null ): array {
		return $this->normalize( $this->users->homeLayout( $userId ), $role, $menuKeys );
	}

	/** Сохраняет и возвращает проверенную раскладку. */
	public function save( int $userId, mixed $raw, ?UserRole $role = null, ?array $menuKeys = null ): array {
		$patch = is_array( $raw ) ? $raw : array();
		$layout = $this->normalize( array_replace( $this->users->homeLayout( $userId ), $patch ), $role, $menuKeys );
		$this->users->setHomeLayout( $userId, $layout );

		return $layout;
	}

	/** @return list<string> */
	private function sections( mixed $value, ?UserRole $role ): array {
		$sectionIds = $this->sectionIds( $role );
		if ( ! is_array( $value ) || count( $value ) !== count( $sectionIds ) ) {
			return $sectionIds;
		}
		if ( count( array_filter( $value, 'is_string' ) ) !== count( $value ) ) {
			return $sectionIds;
		}
		$list = array_values( $value );
		$sorted = $list;
		sort( $sorted );
		$known = $sectionIds;
		sort( $known );

		return $sorted === $known ? $list : $sectionIds;
	}

	/** @return list<string>|null */
	private function tiles( mixed $value, ?UserRole $role ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$tiles = array();
		foreach ( $value as $id ) {
			$id = is_string( $id ) ? $id : '';
			if ( $this->allowedTile( $id, $role ) && ! in_array( $id, $tiles, true ) ) {
				$tiles[] = $id;
			}
			if ( count( $tiles ) >= self::MAX_TILES ) {
				break;
			}
		}

		return $tiles;
	}

	/** Допустимые пункты основного меню; отдельные блоки групп, курсов и экзаменов сюда не входят. */
	private function menuKeys( ?UserRole $role ): array {
		if ( in_array( $role, array( UserRole::FSTeacher, UserRole::FSOffice ), true ) ) {
			$keys = array( 'dashboard', 'journal', 'works', 'summary', 'ktp', 'activity', 'ranking' );
			return UserRole::FSOffice === $role ? array_merge( $keys, array( 'substitutions' ) ) : $keys;
		}
		return array_map( static fn( LearnerScreen $screen ): string => $screen->key( UserRole::FSParent === $role ), LearnerScreen::cases() );
	}

	/** Удаляет чужие, повторяющиеся и повреждённые идентификаторы пунктов меню. */
	private function menuList( mixed $value, array $allowed ): array {
		return is_array( $value ) ? array_values( array_unique( array_filter( $value, static fn( mixed $id ): bool => is_string( $id ) && in_array( $id, $allowed, true ) ) ) ) : array();
	}

	/**
	 * Допустимые секции главной выбранной роли.
	 *
	 * @return list<string>
	 */
	public function sectionIds( ?UserRole $role = null ): array {
		return in_array( $role, array( UserRole::FSTeacher, UserRole::FSOffice ), true ) ? self::STAFF_SECTIONS : self::SECTIONS;
	}

	/** Проверяет принадлежность идентификатора плитки каталогу роли. */
	private function allowedTile( string $id, ?UserRole $role ): bool {
		if ( in_array( $role, array( UserRole::FSTeacher, UserRole::FSOffice ), true ) ) {
			return in_array( $id, array( 'lessons_today', 'to_review' ), true );
		}
		if ( UserRole::FSParent === $role && str_starts_with( $id, 'exam:' ) ) {
			return false;
		}
		return 1 === preg_match( self::TILE_ID, $id );
	}
}
