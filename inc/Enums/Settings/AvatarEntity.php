<?php

declare( strict_types=1 );

namespace Inc\Enums\Settings;

/**
 * Сущности, которым в Настройки → Аватары задаётся картинка вместо цветного чипа.
 *
 * Значение кейса — ключ группы в опции {@see OptionName::Avatars} и поле `entity` AJAX-запроса.
 * Кабинет (Brand) хранится не в этой опции, а как `brand_logo_id` конфигурации плагина.
 */
enum AvatarEntity: string {

	/** Логотип личного кабинета (шапка /profile/). */
	case Brand = 'brand';

	/** Предмет: ключ — subject_key. Наследуют группы и курсы предмета. */
	case Subject = 'subject';

	/** Группа: ключ — ID группы. Перекрывает аватар предмета. */
	case Group = 'group';
}
