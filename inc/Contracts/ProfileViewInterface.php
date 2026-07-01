<?php

declare( strict_types=1 );

namespace Inc\Contracts;

use Inc\DTO\Profile\ProfileContext;

/**
 * Interface ProfileViewInterface
 *
 * Витрина личного кабинета для конкретной формы пользователя.
 *
 * Роль выбирает ФОРМУ (преподаватель vs учащийся): какие пункты меню и какие
 * экраны монтируются. Право записи и охват данных несёт {@see ProfileContext}
 * (ученик пишет свои данные, родитель — только чтение по ребёнку).
 *
 * @package Inc\Contracts
 */
interface ProfileViewInterface {

	/**
	 * Описывает состав кабинета для данного контекста.
	 *
	 * @param ProfileContext $context Контекст текущего пользователя.
	 *
	 * @return array{nav: list<array{key: string, label: string}>, screens: list<string>}
	 *         nav     — пункты сайдбара (ключ экрана + подпись);
	 *         screens — ключи экранов, монтируемых на сцену (в порядке отображения).
	 */
	public function build( ProfileContext $context ): array;
}
