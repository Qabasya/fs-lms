<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

use Inc\Managers\Wp\PostManager;
use Inc\Services\Task\TaskNumberService;

/**
 * Class ArticleSlugService
 *
 * Машинный слаг статьи: `article-task-{номер задания}-{номер в серии}`, а у
 * статьи без номера задания — `article-{номер}`.
 *
 * Зачем слаг строит плагин, а не ядро: адрес статьи — это ровно её `post_name`
 * ({@see \Inc\Controllers\Builders\SubjectCptArgsBuilder::sectionRouting()}), и
 * слаг из заголовка даёт длинный кириллический хвост, который вдобавок живёт
 * своей жизнью при переименовании статьи.
 *
 * **Уникальность — забота этого сервиса и только его.** `wp_unique_post_slug()`
 * отрабатывает ДО фильтра `wp_insert_post_data` (wp-includes/post.php:4813
 * против :4885), поэтому выставленное на фильтре значение ядро уже не проверяет
 * и молча примет два поста с одинаковым слагом. У черновиков оно и штатно не
 * проверяет дубли (post.php:5464).
 *
 * @package Inc\Services\Subject
 */
readonly class ArticleSlugService {

	/** Префикс серии статей одного задания. */
	private const TASK_PREFIX = 'article-task-';

	/** Префикс статей, к которым задание не привязано. */
	private const PLAIN_PREFIX = 'article-';

	/**
	 * @param PostManager       $posts   Менеджер записей WordPress.
	 * @param TaskNumberService $numbers Разбор номера задания из формы.
	 */
	public function __construct(
		private PostManager $posts,
		private TaskNumberService $numbers,
	) {}

	/**
	 * Собирает слаг для статьи, занимая следующий свободный номер серии.
	 *
	 * @param string   $post_type   CPT статей предмета.
	 * @param int      $post_id     ID статьи (0 — создаётся новая).
	 * @param int|null $task_number Номер задания; null — статья без задания.
	 *
	 * @return string
	 */
	public function build( string $post_type, int $post_id, ?int $task_number ): string {
		$prefix = $this->prefix( $task_number );

		// Саму статью из выборки исключаем: иначе черновик со слагом article-3
		// на каждом сохранении видел бы сам себя и уползал на article-4, -5, …
		$taken = $this->posts->findSlugsByPrefix( $post_type, $prefix, $post_id );

		return $prefix . $this->nextOrdinal( $taken, $prefix );
	}

	/**
	 * Собирает слаг по готовому номеру серии — точка входа для пакетного
	 * переименования, где порядок задаёт не занятость номеров, а порядок чтения.
	 *
	 * @param int|null $task_number Номер задания; null — статья без задания.
	 * @param int      $ordinal     Порядковый номер статьи в серии, от 1.
	 *
	 * @return string
	 */
	public function compose( ?int $task_number, int $ordinal ): string {
		return $this->prefix( $task_number ) . max( 1, $ordinal );
	}

	/**
	 * Начало слага для серии.
	 *
	 * @param int|null $task_number Номер задания; null — статья без задания.
	 *
	 * @return string
	 */
	public function prefix( ?int $task_number ): string {
		return null !== $task_number
			? self::TASK_PREFIX . $task_number . '-'
			: self::PLAIN_PREFIX;
	}

	/**
	 * Достаёт номер задания из значения `tax_input`.
	 *
	 * @see TaskNumberService::resolveTaskNumber() Форматы значения
	 *
	 * @param mixed  $tax_input_value Значение `tax_input[{taxonomy}]`.
	 * @param string $subject_key     Ключ предмета.
	 *
	 * @return int|null Null — номера в значении нет (в том числе пустая заглушка метабокса).
	 */
	public function resolveTaskNumber( mixed $tax_input_value, string $subject_key ): ?int {
		return $this->numbers->resolveTaskNumber( $tax_input_value, $subject_key );
	}

	/**
	 * Следующий свободный номер серии.
	 *
	 * Считаем от максимума, а не от количества: дырки в нумерации не
	 * переиспользуются осознанно — освободившийся номер вернул бы посетителя по
	 * старой ссылке на совсем другую статью. Схлопывает дырки только пакетное
	 * переименование.
	 *
	 * @param string[] $taken  Занятые слаги серии.
	 * @param string   $prefix Начало слага.
	 *
	 * @return int
	 */
	private function nextOrdinal( array $taken, string $prefix ): int {
		$pattern  = '/^' . preg_quote( $prefix, '/' ) . '([1-9][0-9]*)$/';
		$ordinals = array();

		foreach ( $taken as $slug ) {
			if ( 1 === preg_match( $pattern, $slug, $matches ) ) {
				$ordinals[] = (int) $matches[1];
			}
		}

		$next = ( array() !== $ordinals ? max( $ordinals ) : 0 ) + 1;

		// Страховка: слаг мог занять кто-то мимо этой нумерации (ручная правка,
		// перенос предмета), а второго шанса ядро не даст — оно нас не проверяет.
		while ( in_array( $prefix . $next, $taken, true ) ) {
			++$next;
		}

		return $next;
	}
}