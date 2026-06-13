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
		};
	}
	public function menu_title(): string {
		return match ( $this ) {
			self::Main      => 'Статистика',
			self::Subjects => 'Предметы',
			self::Settings        => 'Настройки',
			self::BoilerplateManager         => 'Boilerplate Manager',
			self::Groups         => 'Группы',
			self::UserList         => 'Пользователи',
			self::Logs         => 'Журналы',
		};
	}

	public function callback(): string {
		return match ( $this ) {
			self::Main      => 'adminDashboard',
			self::Subjects => 'subjectsRoot',
			self::Settings        => 'settingsPage',
			self::BoilerplateManager         => 'boilerplatePage',
			self::Groups         => 'groupsPage',
			self::UserList         => 'userlistPage',
			self::Logs         => 'logsPage',
		};
	}




}
