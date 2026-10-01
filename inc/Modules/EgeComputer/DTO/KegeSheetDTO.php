<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer\DTO;

/**
 * Лист ответов станции КЕГЭ — данные экрана после завершения экзамена.
 *
 * Собирает {@see \Inc\Modules\EgeComputer\Services\KegeResultSheetService}, шаблон
 * (templates/frontend/assessment/kege/finish.php) получает готовый объект фильтром
 * модуля. В отличие от générique-экрана результата, здесь ученику показываются и
 * эталонные ответы: станция имитирует лист ответов реального КЕГЭ.
 *
 * @package Inc\Modules\EgeComputer\DTO
 */
readonly class KegeSheetDTO {

	/**
	 * Сколько строк помещается в одну таблицу листа на экране без прокрутки — как на
	 * реальной станции: первая таблица заполняется доверху, остаток идёт во вторую.
	 * Работа может быть любой длины (повторы типа, архивные варианты), поэтому таблиц
	 * столько, сколько нужно: 27 строк обычного КИМ — 18 + 9, 30 строк — 18 + 12,
	 * 12 строк — одна. Зеркало: `ROWS_PER_TABLE` в `kege-entry.js` (предпросмотр).
	 */
	public const ROWS_PER_TABLE = 18;

	/**
	 * @param list<array{number: string, score: ?float, answer: string, correct: string, url: string}> $rows Строки листа в порядке заданий работы
	 * @param int        $answered     Сколько заданий с непустым ответом ученика
	 * @param float      $primary      Первичный балл попытки
	 * @param float      $primaryMax   Максимальный первичный балл
	 * @param int|null   $secondary    Вторичный балл; null — таблица перевода не задана
	 * @param int|null   $secondaryMax Максимум таблицы перевода; null вместе с $secondary
	 * @param bool       $revealed     D18: можно ли показывать ответы/баллы ученику — false,
	 *                                 пока учитель не подтвердил результат ({@see \Inc\Services\Assessment\AttemptRevealPolicy}).
	 *                                 При false строки/баллы уже зачищены сервисом — шаблон
	 *                                 их не получает, но обязан ещё и сам проверять этот флаг
	 *                                 перед рендером таблицы (defense in depth)
	 */
	public function __construct(
		public array  $rows,
		public int    $answered,
		public float  $primary,
		public float  $primaryMax,
		public ?int   $secondary,
		public ?int   $secondaryMax,
		public bool   $revealed = true,
	) {}

	/** Пустой лист — модуль выключен или фильтр никем не обработан. */
	public static function blank(): self {
		return new self( array(), 0, 0.0, 0.0, null, null );
	}

	/**
	 * Строки листа, разложенные по таблицам: каждая заполняется до
	 * {@see self::ROWS_PER_TABLE} строк, остаток переходит в следующую.
	 *
	 * @return list<list<array{number: string, score: ?float, answer: string, correct: string, url: string}>>
	 */
	public function tables(): array {
		return array_chunk( $this->rows, self::ROWS_PER_TABLE );
	}

	/** Всего строк листа — заданий работы (их может быть больше 27: повторы типа идут отдельными строками). */
	public function total(): int {
		return count( $this->rows );
	}
}