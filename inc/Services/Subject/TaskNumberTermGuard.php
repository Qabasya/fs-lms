<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

/**
 * Class TaskNumberTermGuard
 *
 * Стережёт термины фиксированной таксономии "Номера заданий" ({key}_task_number)
 * на нативных экранах WordPress:
 *  - добавление ({@see validateInsert}) и переименование ({@see validateUpdate}) —
 *    только целое положительное число, без дублей;
 *  - слаг ({@see normalizeSlug}) — единый паттерн "{ключ_предмета}_{номер}",
 *    которым TaxonomySeeder засеивает термины при создании предмета;
 *  - удаление ({@see preventDeleteWithContent}, {@see restrictDeleteCapability}) —
 *    запрещено, пока к номеру привязаны записи: связь терма с постами уходит
 *    вместе с ним и не восстанавливается пересозданием одноимённого терма.
 *
 * @package Inc\Services\Subject
 */
class TaskNumberTermGuard {

	private const TAXONOMY_SUFFIX = '_task_number';

	/**
	 * Фильтр-байпас гарда удаления: поднимается на время внутреннего сноса
	 * (удаление предмета целиком, откат импорта), где терм обязан уйти вместе
	 * со своими записями.
	 */
	public const BYPASS_FILTER = 'fs_lms_bypass_task_number_guard';

	private const INVALID_NUMBER_MESSAGE = 'Номер задания должен быть целым положительным числом без букв и лишних символов (например, «5»).';

	/**
	 * Хук 'pre_insert_term' — блокирует создание терма с некорректным названием.
	 *
	 * @param string|\WP_Error $term     Название нового терма (или уже возникшая ошибка).
	 * @param string           $taxonomy Слаг таксономии.
	 *
	 * @return string|\WP_Error
	 */
	public function validateInsert( string|\WP_Error $term, string $taxonomy ): string|\WP_Error {
		if ( is_wp_error( $term ) || ! $this->isTaskNumberTaxonomy( $taxonomy ) ) {
			return $term;
		}

		$name = trim( $term );

		if ( ! $this->isTaskNumber( $name ) ) {
			return new \WP_Error( 'fs_lms_invalid_task_number', self::INVALID_NUMBER_MESSAGE );
		}

		if ( term_exists( $name, $taxonomy ) ) {
			return new \WP_Error(
				'fs_lms_duplicate_task_number',
				sprintf( 'Задание №%s уже существует в этой таксономии.', $name )
			);
		}

		return $term;
	}

	/**
	 * Хук 'edit_terms' — те же правила при ПЕРЕИМЕНОВАНИИ терма.
	 *
	 * `wp_update_term()` не даёт фильтра, способного вернуть `WP_Error` (в отличие
	 * от `pre_insert_term` на вставке), поэтому единственная точка, где правку
	 * ещё можно остановить до записи в БД, — это действие `edit_terms`. Отсюда
	 * `wp_die()`: на обычной форме редактирования покажется страница с причиной и
	 * ссылкой «Назад», в быстром редактировании — текст ошибки прямо в строке
	 * (inline-edit-tax.js выводит не-HTML ответ как сообщение об ошибке).
	 *
	 * Действие срабатывает и внутри `wp_insert_term` — там оно приходит без `$args`,
	 * и проверка пропускается: имя уже проверено на `pre_insert_term`.
	 *
	 * @param int                  $term_id  ID терма.
	 * @param string               $taxonomy Слаг таксономии.
	 * @param array<string, mixed> $args     Аргументы `wp_update_term()`.
	 *
	 * @return void
	 */
	public function validateUpdate( int $term_id, string $taxonomy, array $args = array() ): void {
		if ( ! $this->isTaskNumberTaxonomy( $taxonomy ) || ! isset( $args['name'] ) ) {
			return;
		}

		$name = trim( wp_unslash( (string) $args['name'] ) );

		if ( '' === $name ) {
			return; // Пустое имя отсекает сам wp_update_term().
		}

		if ( ! $this->isTaskNumber( $name ) ) {
			$this->reject( self::INVALID_NUMBER_MESSAGE );
		}

		$existing = term_exists( $name, $taxonomy );
		$existsId = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;

		// Сохранение терма без изменения номера — не дубликат самого себя.
		if ( $existsId > 0 && $existsId !== $term_id ) {
			$this->reject( sprintf( 'Задание №%s уже существует в этой таксономии.', $name ) );
		}
	}

	/**
	 * Хук 'pre_delete_term' — запрещает удалять номер задания, к которому
	 * привязаны записи.
	 *
	 * `wp_delete_term()` уносит с собой строки `term_relationships`, а корзины у
	 * термов нет: удалив «Задание №1» со 100 заданиями, номер у них теряешь
	 * безвозвратно — новый терм с тем же именем получает другой `term_id` и
	 * старые задания к нему НЕ подписываются.
	 *
	 * Легитимный массовый снос (удаление предмета, откат импорта) поднимает
	 * фильтр {@see self::BYPASS_FILTER} и проходит мимо гарда.
	 *
	 * @param int    $term_id  ID терма.
	 * @param string $taxonomy Слаг таксономии.
	 *
	 * @return void
	 */
	public function preventDeleteWithContent( int $term_id, string $taxonomy ): void {
		if ( ! $this->isTaskNumberTaxonomy( $taxonomy ) || $this->isBypassed() ) {
			return;
		}

		$attached = $this->countAttached( $term_id, $taxonomy );
		if ( 0 === $attached ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		$name = ( $term instanceof \WP_Term ) ? $term->name : (string) $term_id;

		$this->reject( sprintf(
			'Нельзя удалить номер задания «%s»: к нему привязано записей — %d. '
				. 'Удаление разорвёт эту связь безвозвратно — у номеров нет корзины, '
				. 'а новый терм с тем же номером старые задания не подхватит. '
				. 'Сначала смените номер у этих записей: поле «Номер задания» в '
				. 'редакторе задания или «Свойства» в списке заданий.',
			$name,
			$attached
		), 'Номер задания занят' );
	}

	/**
	 * Хук 'map_meta_cap' — прячет само действие «Удалить» у занятого номера.
	 *
	 * Без этого ссылка в списке терминов остаётся кликабельной и удаление
	 * обрывается только на `pre_delete_term`. WP скрывает действия, на которые
	 * нет права, поэтому запрет права — самый спокойный для пользователя способ.
	 *
	 * @param string[] $caps    Итоговые права.
	 * @param string   $cap     Проверяемое право.
	 * @param int      $user_id ID пользователя.
	 * @param array    $args    Аргументы проверки; `$args[0]` — ID терма.
	 *
	 * @return string[]
	 */
	public function restrictDeleteCapability( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'delete_term' !== $cap || empty( $args[0] ) || $this->isBypassed() ) {
			return $caps;
		}

		$term = get_term( (int) $args[0] );
		if ( ! $term instanceof \WP_Term || ! $this->isTaskNumberTaxonomy( $term->taxonomy ) ) {
			return $caps;
		}

		return $this->countAttached( $term->term_id, $term->taxonomy ) > 0
			? array( 'do_not_allow' )
			: $caps;
	}

	/**
	 * Сколько записей висит на номере.
	 *
	 * Считаем по связям, а не по `$term->count`: счётчик учитывает только
	 * опубликованные записи, а связь теряется и у черновиков с архивом.
	 *
	 * @param int    $term_id  ID терма.
	 * @param string $taxonomy Слаг таксономии.
	 *
	 * @return int
	 */
	private function countAttached( int $term_id, string $taxonomy ): int {
		$objects = get_objects_in_term( array( $term_id ), array( $taxonomy ) );

		return is_wp_error( $objects ) ? 0 : count( $objects );
	}

	/** Легитимный внутренний снос (удаление предмета, откат импорта). */
	private function isBypassed(): bool {
		return (bool) apply_filters( self::BYPASS_FILTER, false );
	}

	/**
	 * Прерывает сохранение терма с человекочитаемой причиной.
	 *
	 * @param string $message Текст ошибки.
	 * @param string $title   Заголовок страницы ошибки.
	 *
	 * @return never
	 */
	private function reject( string $message, string $title = 'Некорректный номер задания' ): void {
		wp_die(
			esc_html( $message ),
			$title,
			array(
				'response'  => 400,
				'back_link' => true,
			)
		);
	}

	private function isTaskNumber( string $name ): bool {
		return 1 === preg_match( '/^[1-9][0-9]*$/', $name );
	}

	/**
	 * Хук 'wp_insert_term_data' — приводит слаг нового терма к паттерну
	 * "{ключ_предмета}_{номер}", которым TaxonomySeeder засеивает термины при создании предмета.
	 *
	 * @param array  $data     Данные терма перед вставкой (name, slug, term_group).
	 * @param string $taxonomy Слаг таксономии.
	 *
	 * @return array
	 */
	public function normalizeSlug( array $data, string $taxonomy ): array {
		if ( ! $this->isTaskNumberTaxonomy( $taxonomy ) ) {
			return $data;
		}

		$name = (string) ( $data['name'] ?? '' );
		if ( '' === $name ) {
			return $data;
		}

		$prefix        = substr( $taxonomy, 0, -strlen( self::TAXONOMY_SUFFIX ) );
		$data['slug']  = sanitize_title( "{$prefix}_{$name}" );

		return $data;
	}

	private function isTaskNumberTaxonomy( string $taxonomy ): bool {
		return str_ends_with( $taxonomy, self::TAXONOMY_SUFFIX );
	}
}
