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
	 * @param list<array{number: string, score: ?float, answer: string, correct: string}> $rows Строки листа в порядке заданий работы
	 * @param int        $answered     Сколько позиций с непустым ответом ученика
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

	/** Всего позиций ответа в листе (№26 и №27 дают по две строки, а не по одной). */
	public function total(): int {
		return count( $this->rows );
	}
}