<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Общий контекст запроса страницы «Журналы»: то, что одинаково для всех каналов.
 *
 * Разбирается один раз в `AdminCallbacks::logsPage()` и передаётся провайдеру
 * вкладки ({@see \Inc\Contracts\LogPageProviderInterface}); специфические
 * фильтры канала провайдер читает сам.
 */
readonly class LogPageQueryDTO {

	/**
	 * @param int         $page     Номер страницы (с единицы)
	 * @param int         $perPage  Записей на страницу
	 * @param string      $orderby  Колонка сортировки: id|created_at
	 * @param string      $order    Направление: ASC|DESC
	 * @param string      $dateFrom Нижняя граница периода (Y-m-d) или ''
	 * @param string      $dateTo   Верхняя граница периода (Y-m-d) или ''
	 * @param int|null    $actorId  Фильтр по автору действия
	 * @param int|null    $personId Фильтр по физлицу
	 */
	public function __construct(
		public int     $page,
		public int     $perPage,
		public string  $orderby,
		public string  $order,
		public string  $dateFrom,
		public string  $dateTo,
		public ?int    $actorId = null,
		public ?int    $personId = null,
	) {}

	/**
	 * Границы периода — общая часть фильтров любого канала.
	 *
	 * @return array<string, string> Непустые date_from/date_to
	 */
	public function dateFilters(): array {
		return array_filter(
			array(
				'date_from' => $this->dateFrom,
				'date_to'   => $this->dateTo,
			)
		);
	}
}
