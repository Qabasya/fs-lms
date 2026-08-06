<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\Enums\Settings\OptionName;
use Inc\Enums\Wp\SubjectPageType;

/**
 * Class SubjectPagesRepository
 *
 * Хранит страницы лендинга предмета: [subject_key => [page_type => page_id]].
 *
 * @package Inc\Repositories
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует опцию `fs_lms_subject_pages`. Храним ID, а не слаг: WP при
 * коллизии слагов выдаёт странице суффикс (`math-2`), и ссылка, собранная из
 * ключа предмета, вела бы в никуда.
 */
class SubjectPagesRepository {

	/**
	 * Все связки предмет → страницы.
	 *
	 * @return array<string, array<string, int>>
	 */
	public function readAll(): array {
		$pages = get_option( OptionName::SubjectPages->value, array() );

		return is_array( $pages ) ? $pages : array();
	}

	/**
	 * Страницы одного предмета.
	 *
	 * @param string $subjectKey Ключ предмета.
	 *
	 * @return array<string, int> [page_type => page_id]
	 */
	public function getBySubject( string $subjectKey ): array {
		return array_map( 'intval', $this->readAll()[ $subjectKey ] ?? array() );
	}

	/**
	 * ID страницы раздела; 0 — страницы нет.
	 *
	 * @param string          $subjectKey Ключ предмета.
	 * @param SubjectPageType $type       Раздел лендинга.
	 *
	 * @return int
	 */
	public function getId( string $subjectKey, SubjectPageType $type ): int {
		return $this->getBySubject( $subjectKey )[ $type->value ] ?? 0;
	}

	/**
	 * Записывает полный набор страниц предмета (одной операцией на предмет).
	 *
	 * @param string             $subjectKey Ключ предмета.
	 * @param array<string, int> $pageIds    [page_type => page_id]
	 *
	 * @return void
	 */
	public function saveBySubject( string $subjectKey, array $pageIds ): void {
		$pages                = $this->readAll();
		$pages[ $subjectKey ] = $pageIds;

		update_option( OptionName::SubjectPages->value, $pages );
	}

	/**
	 * Забывает страницы предмета (сами страницы убирает SubjectPagesService).
	 *
	 * @param string $subjectKey Ключ предмета.
	 *
	 * @return void
	 */
	public function removeSubject( string $subjectKey ): void {
		$pages = $this->readAll();

		if ( ! isset( $pages[ $subjectKey ] ) ) {
			return;
		}

		unset( $pages[ $subjectKey ] );

		update_option( OptionName::SubjectPages->value, $pages );
	}

	/**
	 * Чей раздел лежит на странице. Нужно и шаблону страницы (какой раздел
	 * рендерить), и шорткоду без атрибута `subject`.
	 *
	 * @param int $pageId ID страницы.
	 *
	 * @return array{key: string, type: SubjectPageType}|null Null — страница не наша.
	 */
	public function locate( int $pageId ): ?array {
		if ( $pageId < 1 ) {
			return null;
		}

		foreach ( $this->readAll() as $subjectKey => $pageIds ) {
			$type = array_search( $pageId, array_map( 'intval', $pageIds ), true );

			if ( is_string( $type ) && null !== SubjectPageType::tryFrom( $type ) ) {
				return array(
					'key'  => (string) $subjectKey,
					'type' => SubjectPageType::from( $type ),
				);
			}
		}

		return null;
	}
}