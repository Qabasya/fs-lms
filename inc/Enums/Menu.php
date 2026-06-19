<?php

declare(strict_types=1);

namespace Inc\Enums;


enum Menu: string
{
	/** Слаги меню плагина.	 */
	case Main = 'fs_lms';

	case Subjects = 'fs_subjects';
	case Settings = 'fs_lms_settings';
	case BoilerplateManager = 'fs_boilerplate_manager';
	case Groups = 'fs_lms_groups';
	case UserList = 'fs_lms_userlist';
	case Logs = 'fs_lms_logs';

	// ===== Обучение: единое меню банков контента =====
	case Learning         = 'fs_lms_learning';
	case LearningCourses  = 'fs_lms_learning_courses';
	case LearningLessons  = 'fs_lms_learning_lessons';
	case LearningWorks    = 'fs_lms_learning_works';
	case LearningTasks    = 'fs_lms_learning_tasks';
	case LearningArticles = 'fs_lms_learning_articles';
	case LearningProblems = 'fs_lms_learning_problems';

	case _Options = 'options.php';

	public function page_title(): string {
		return match ( $this ) {
			self::Main      => 'Статистика',
			self::Subjects => 'Управление предметами',
			self::Settings        => 'Настройки',
			self::BoilerplateManager         => 'Управление типовыми условиями',
			self::Groups         => 'Управление группами',
			self::UserList         => 'Список пользователей',
			self::Logs         => 'Журналы',
			self::Learning         => 'Обучение',
			self::LearningCourses  => 'Курсы',
			self::LearningLessons  => 'Уроки',
			self::LearningWorks    => 'Работы',
			self::LearningTasks    => 'Задания предмета',
			self::LearningArticles => 'Статьи предмета',
			self::LearningProblems => 'Банк задач',
			self::_Options         => '',
		};
	}
	public function menu_title(): string {
		return match ( $this ) {
			self::Main               => 'Статистика',
			self::Subjects           => 'Предметы',
			self::Settings           => 'Настройки',
			self::BoilerplateManager => 'Boilerplate Manager',
			self::Groups             => 'Группы',
			self::UserList           => 'Пользователи',
			self::Logs               => 'Журналы',
			self::Learning           => 'Обучение',
			self::LearningCourses    => 'Курсы',
			self::LearningLessons    => 'Уроки',
			self::LearningWorks      => 'Работы',
			self::LearningTasks      => 'Задания предмета',
			self::LearningArticles   => 'Статьи предмета',
			self::LearningProblems   => 'Банк задач',
			self::_Options           => '',
		};
	}

	public function callback(): string {
		return match ( $this ) {
			self::Main               => 'adminDashboard',
			self::Subjects           => 'subjectsRoot',
			self::Settings           => 'settingsPage',
			self::BoilerplateManager => 'boilerplatePage',
			self::Groups             => 'groupsPage',
			self::UserList           => 'userlistPage',
			self::Logs               => 'logsPage',
			self::Learning           => 'renderCourses',
			self::LearningCourses    => 'renderCourses',
			self::LearningLessons    => 'renderLessons',
			self::LearningWorks      => 'renderWorks',
			self::LearningTasks      => 'renderTasks',
			self::LearningArticles   => 'renderArticles',
			self::_Options           => '',
		};
	}




}
