<?php

declare( strict_types=1 );

namespace Inc\Enums\Log;

/**
 * Тип значения фильтра журнала — определяет плейсхолдер $wpdb->prepare()
 * и способ построения условия в {@see \Inc\Repositories\WPDBRepositories\Log\AbstractLogRepository}.
 *
 * Даты (`date_from`/`date_to` по `created_at`) сюда не входят: они есть у всех
 * каналов и добавляются базовым репозиторием автоматически.
 */
enum LogFilterType {

	/** Строковое равенство: `column = %s`. */
	case Text;

	/** Целочисленное равенство: `column = %d`. */
	case Number;

	/** Вхождение в список ID: `column IN (%d, %d, …)`; пустой список → выборка пуста. */
	case NumberList;
}
