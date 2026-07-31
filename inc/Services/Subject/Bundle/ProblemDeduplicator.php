<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

use Inc\Managers\Wp\PostManager;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class ProblemDeduplicator
 *
 * Не даёт глобальному банку задач раздуваться при повторных импортах.
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Проблема
 *
 * `fs_lms_problems` — банк, общий для всех предметов. Если на один сайт
 * импортировать два предмета, ссылающихся на одни и те же задачи, наивный
 * импорт создаст их дважды: два одинаковых условия в общем банке, и
 * преподаватель не понимает, какое из них «настоящее».
 *
 * ### Решение
 *
 * При импорте задачи банка сначала ищется уже существующая: сперва по метке
 * происхождения (`fs_lms_bundle_origin` — `_export_id` + сайт-источник),
 * затем по контрольной сумме содержимого. Найденная задача переиспользуется:
 * её ID кладётся в карту, новая запись не создаётся, а значит и в журнал
 * отката она не попадает — при откате чужую задачу трогать нельзя.
 *
 * ### Почему метка, а не только хэш
 *
 * Хэш ловит побайтово одинаковые задачи, метка — те же задачи, слегка
 * отредактированные на целевом сайте после первого импорта. Без метки правка
 * условия превращала бы задачу в «новую» и возвращала дубли.
 */
class ProblemDeduplicator {

	/**
	 * Мета-ключ метки происхождения импортированной задачи банка.
	 */
	public const string ORIGIN_META = 'fs_lms_bundle_origin';

	/**
	 * Мета-ключ контрольной суммы содержимого.
	 */
	public const string FINGERPRINT_META = 'fs_lms_bundle_fingerprint';

	/**
	 * Конструктор.
	 *
	 * @param PostManager $posts Менеджер записей
	 */
	public function __construct(
		private readonly PostManager $posts,
	) {}

	/**
	 * Ищет уже существующую на целевом сайте задачу банка.
	 *
	 * @param array  $problem  Представление задачи из манифеста
	 * @param string $originId Идентификатор происхождения (`site|_export_id`)
	 *
	 * @return int ID существующей задачи; 0 — не найдена
	 */
	public function findExisting( array $problem, string $originId ): int {
		$byOrigin = $this->findByMeta( self::ORIGIN_META, $originId );
		if ( $byOrigin > 0 ) {
			return $byOrigin;
		}

		return $this->findByMeta( self::FINGERPRINT_META, $this->fingerprint( $problem ) );
	}

	/**
	 * Помечает созданную задачу происхождением и отпечатком.
	 *
	 * @param int    $postId   ID новой задачи
	 * @param array  $problem  Представление задачи из манифеста
	 * @param string $originId Идентификатор происхождения
	 *
	 * @return void
	 */
	public function mark( int $postId, array $problem, string $originId ): void {
		$this->posts->updateMeta( $postId, self::ORIGIN_META, $originId );
		$this->posts->updateMeta( $postId, self::FINGERPRINT_META, $this->fingerprint( $problem ) );
	}

	/**
	 * Идентификатор происхождения задачи в пакете.
	 *
	 * Сайт-источник входит в ключ, потому что `_export_id` уникален только в
	 * пределах одной установки: `problems:12` с двух разных сайтов — это две
	 * разные задачи.
	 *
	 * @param string $sourceSite URL сайта-источника из манифеста
	 * @param string $exportId   `_export_id` задачи
	 *
	 * @return string
	 */
	public static function originId( string $sourceSite, string $exportId ): string {
		return md5( $sourceSite . '|' . $exportId );
	}

	/**
	 * Контрольная сумма содержимого задачи.
	 *
	 * Считается по заголовку и мете условия — по тому, что делает задачу
	 * задачей. Служебные поля (`_export_id`, статус, дата) в отпечаток не
	 * входят: они меняются при переносе, а задача остаётся той же.
	 *
	 * @param array $problem Представление задачи
	 *
	 * @return string
	 */
	private function fingerprint( array $problem ): string {
		$meta = (array) ( $problem['meta'] ?? array() );
		ksort( $meta );

		return md5( (string) wp_json_encode( array(
			'title'   => (string) ( $problem['post_title'] ?? '' ),
			'content' => (string) ( $problem['post_content'] ?? '' ),
			'meta'    => $meta,
		) ) );
	}

	/**
	 * Ищет задачу банка по точному значению меты.
	 *
	 * @param string $metaKey   Ключ меты
	 * @param string $metaValue Значение
	 *
	 * @return int ID найденной задачи; 0 — нет
	 */
	private function findByMeta( string $metaKey, string $metaValue ): int {
		if ( '' === $metaValue ) {
			return 0;
		}

		$found = $this->posts->search( PostTypeResolver::problems(), array(
			'status'     => array( 'publish', 'draft', 'pending', 'private' ),
			'limit'      => 1,
			'meta_query' => array(
				array(
					'key'     => $metaKey,
					'value'   => $metaValue,
					'compare' => '=',
				),
			),
		) );

		return array() === $found ? 0 : (int) $found[0]->ID;
	}
}
