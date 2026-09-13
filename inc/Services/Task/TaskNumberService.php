<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Managers\Wp\PostManager;

/**
 * Class TaskNumberService
 *
 * Номер задания в банке: `{номер задания}{счётчик из трёх цифр}` — 5000, 5001, …
 * Номер живёт в `post_name` и одновременно служит адресом задания
 * (`/{ключ}/trainer/{номер}/`).
 *
 * @package Inc\Services\Task
 *
 * ### Какой номер выдаётся
 *
 * Наименьший свободный, а не «максимум + 1» и не «количество»: удалённое или
 * отправленное в корзину 501 освобождает номер, и следующее созданное задание
 * занимает его. Черновик номер держит — переписанное в черновике задание дыры
 * не оставляет. Этим отличается от серии статей ({@see \Inc\Services\Subject\ArticleSlugService}),
 * где дыры осознанно не переиспользуются.
 *
 * ### Почему проверка при публикации всё равно нужна
 *
 * Выдача номера не атомарна (модалка во время идущего импорта того же номера
 * получит тот же номер), восстановленное из корзины задание возвращает свой
 * номер, даже если его уже заняли, а у черновиков ядро дубли слагов не
 * проверяет вовсе (wp-includes/post.php:5464). Ловит это {@see publishError()}.
 */
class TaskNumberService {

	/** Минимальная ширина счётчика: 5 → 5000. */
	private const COUNTER_WIDTH = 3;

	/**
	 * @param PostManager $posts Менеджер записей WordPress.
	 */
	public function __construct(
		private readonly PostManager $posts,
	) {}

	/**
	 * Наименьший свободный номер для задания с этим номером.
	 *
	 * @param string $post_type   CPT заданий предмета.
	 * @param int    $task_number Номер задания (5 → серия 5000, 5001, …).
	 * @param int    $exclude_id  Какую запись не учитывать (обычно — саму проверяемую).
	 *
	 * @return string
	 */
	public function build( string $post_type, int $task_number, int $exclude_id = 0 ): string {
		$prefix = (string) $task_number;
		$taken  = array_flip( $this->posts->findSlugsByPrefix( $post_type, $prefix, $exclude_id ) );

		$counter = 0;
		while ( isset( $taken[ $this->compose( $task_number, $counter ) ] ) ) {
			++$counter;
		}

		return $this->compose( $task_number, $counter );
	}

	/**
	 * Почему задание нельзя публиковать с этим номером; null — номер в порядке.
	 *
	 * @param string $post_type   CPT заданий предмета.
	 * @param int    $post_id     ID задания (0 — создаётся новое).
	 * @param string $slug        Номер, с которым задание хотят опубликовать.
	 * @param int    $task_number Выбранный у задания номер задания.
	 *
	 * @return string|null
	 */
	public function publishError( string $post_type, int $post_id, string $slug, int $task_number ): ?string {
		if ( ! $this->belongsTo( $slug, $task_number ) ) {
			// Не-число (слаг из заголовка у черновика из конструктора урока) цитировать
			// бессмысленно — называем только ожидаемый вид номера.
			return sprintf(
				'%s не подходит к заданию №%d: номера этого задания имеют вид %s, %s… Свободный номер — %s, укажите его в постоянной ссылке.',
				ctype_digit( $slug ) ? "Номер «{$slug}»" : 'Постоянная ссылка задания',
				$task_number,
				$this->compose( $task_number, 0 ),
				$this->compose( $task_number, 1 ),
				$this->build( $post_type, $task_number, $post_id )
			);
		}

		$other = $this->posts->findBySlug( $post_type, $slug, $post_id );
		if ( null !== $other ) {
			return sprintf(
				'Номер %s уже занят заданием «%s» (ID %d). Свободный номер — %s, укажите его в постоянной ссылке.',
				$slug,
				$other->post_title,
				$other->ID,
				$this->build( $post_type, $task_number, $post_id )
			);
		}

		return null;
	}

	/**
	 * Достаёт номер задания из значения `tax_input`.
	 *
	 * Значение приходит в трёх видах, и все три надо понимать:
	 * массив слагов терминов (`inf_5`) — нативный метабокс в режиме select
	 * ({@see \Inc\Registrars\SubjectTaxonomyRegistrar::buildMetaBoxCallback()});
	 * строка имён через запятую (`5`) — быстрое и массовое редактирование, там
	 * плоская таксономия приезжает строкой; массив ID — на случай, если режим
	 * метабокса когда-нибудь сменится.
	 *
	 * Обращения к БД тут нет и не нужно: форму терма жёстко держит
	 * {@see \Inc\Services\Subject\TaskNumberTermGuard} — имя это `[1-9][0-9]*`,
	 * слаг это `{ключ}_{имя}`.
	 *
	 * @param mixed  $tax_input_value Значение `tax_input[{taxonomy}]` (или список имён терминов).
	 * @param string $subject_key     Ключ предмета.
	 *
	 * @return int|null Null — номера в значении нет (в том числе пустая заглушка метабокса).
	 */
	public function resolveTaskNumber( mixed $tax_input_value, string $subject_key ): ?int {
		foreach ( $this->candidates( $tax_input_value ) as $value ) {
			$number = $this->numberFromTermValue( $value, $subject_key );

			if ( null !== $number ) {
				return $number;
			}
		}

		return null;
	}

	/**
	 * Номер серии задания по счётчику.
	 *
	 * @param int $task_number Номер задания.
	 * @param int $counter     Счётчик в серии, от 0.
	 *
	 * @return string
	 */
	private function compose( int $task_number, int $counter ): string {
		return $task_number . str_pad( (string) $counter, self::COUNTER_WIDTH, '0', STR_PAD_LEFT );
	}

	/**
	 * Принадлежит ли номер серии задания.
	 *
	 * Счётчик — ровно три цифры либо длиннее без ведущего нуля: `compose()` иначе
	 * не пишет, а `50015` к заданию №5 не относится (это №50).
	 *
	 * @param string $slug        Номер.
	 * @param int    $task_number Номер задания.
	 *
	 * @return bool
	 */
	private function belongsTo( string $slug, int $task_number ): bool {
		return 1 === preg_match( '/^' . $task_number . '(?:\d{3}|[1-9]\d{3,})$/', $slug );
	}

	/**
	 * Приводит значение `tax_input` к списку непустых строк.
	 *
	 * Пустые строки отбрасываем обязательно: и `<option value="">— Не выбрано —</option>`
	 * селекта, и скрытая строка-заглушка режимов radio/checkbox приезжают в
	 * массиве наравне с настоящими значениями.
	 *
	 * @param mixed $value Значение `tax_input[{taxonomy}]`.
	 *
	 * @return string[]
	 */
	private function candidates( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$values = array();

		foreach ( $value as $item ) {
			if ( is_string( $item ) || is_int( $item ) ) {
				$item = trim( (string) $item );

				if ( '' !== $item ) {
					$values[] = $item;
				}
			}
		}

		return $values;
	}

	/**
	 * Номер задания из имени или слага терма.
	 *
	 * @param string $value       Значение из формы.
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return int|null
	 */
	private function numberFromTermValue( string $value, string $subject_key ): ?int {
		// Имя терма — голое число (быстрое редактирование шлёт именно его).
		if ( 1 === preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			return (int) $value;
		}

		$pattern = '/^' . preg_quote( $subject_key, '/' ) . '_([1-9][0-9]*)$/';

		return 1 === preg_match( $pattern, $value, $matches ) ? (int) $matches[1] : null;
	}
}
