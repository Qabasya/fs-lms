<?php

declare( strict_types=1 );

namespace Inc\Contracts;

use Inc\DTO\Log\LogPageQueryDTO;
use Inc\Enums\Log\LogChannel;

/**
 * Поставщик данных одной вкладки страницы «Журналы».
 *
 * Каждый канал журнала ({@see LogChannel}) имеет свой набор фильтров, словарей
 * и ключей шаблона — интерфейс позволяет держать это рядом с каналом,
 * а не в одной цепочке elseif внутри коллбэка страницы.
 *
 * Реализации живут в `inc/Services/Log/Pages/`, собираются реестром
 * {@see \Inc\Services\Log\Pages\LogPageRegistry}.
 */
interface LogPageProviderInterface {

	/**
	 * Канал, вкладку которого обслуживает провайдер.
	 */
	public function channel(): LogChannel;

	/**
	 * Данные вкладки для шаблона `admin/logs`.
	 *
	 * Специфические GET-фильтры провайдер читает сам (трейт Sanitizer);
	 * общий контекст страницы приходит в $query.
	 *
	 * @param LogPageQueryDTO $query Общие параметры страницы (пагинация, сортировка, даты)
	 *
	 * @return array<string, mixed> Переменные шаблона
	 */
	public function data( LogPageQueryDTO $query ): array;
}
