<?php

declare( strict_types=1 );

namespace Inc\Enums;

/**
 * Тип сущности плагина (лог 1 — EntityAudit).
 *
 * Используется и для отображения badge в админке, и для резолва
 * «entityId → человекочитаемое название» в слое отображения.
 */
enum EntityType: string {

	case Subject        = 'subject';
	case Taxonomy       = 'taxonomy';
	case Term           = 'term';
	case VisualTemplate = 'visual_template';
	case Boilerplate    = 'boilerplate';
	case Task           = 'task';
	case Article        = 'article';
	case Group          = 'group';
	case Period         = 'period';
	case Student        = 'student';
	case Parent         = 'parent';
	case Teacher        = 'teacher';
	case User           = 'user';

	public function label(): string {
		return match ( $this ) {
			self::Subject        => 'Предмет',
			self::Taxonomy       => 'Таксономия',
			self::Term           => 'Терм',
			self::VisualTemplate => 'Визуальный шаблон',
			self::Boilerplate    => 'Типовое условие',
			self::Task           => 'Задание',
			self::Article        => 'Статья',
			self::Group          => 'Группа',
			self::Period         => 'Учебный период',
			self::Student        => 'Ученик',
			self::Parent         => 'Родитель',
			self::Teacher        => 'Преподаватель',
			self::User           => 'Пользователь',
		};
	}

}
