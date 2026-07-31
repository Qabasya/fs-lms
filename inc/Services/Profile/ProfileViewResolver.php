<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Contracts\ProfileViewInterface;
use Inc\DTO\Profile\ProfileContext;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

/**
 * Class ProfileViewResolver
 *
 * Резолвер личного кабинета: по вошедшему пользователю строит {@see ProfileContext},
 * выбирает витрину ({@see ProfileViewInterface}) и собирает JS-конфиг `window.fsProfile`,
 * которым SPA рендерит сайдбар, экраны и режим доступа.
 *
 * Маппинг роль → витрина:
 *  - FSTeacher                       → TeacherProfileView
 *  - FSStudent / Student / FSParent  → LearnerProfileView
 *  - офисные роли (FSOffice/…)       → null (их кабинет — в админке WP, см. ProfileController)
 *
 * @package Inc\Services\Profile
 */
class ProfileViewResolver {

	public function __construct(
		private readonly PersonRepository        $persons,
		private readonly StudentRecordRepository $records,
		private readonly GroupsRepository        $groups,
		// Витрины инжектятся конкретными типами намеренно: резолвер — точка
		// СБОРКИ, он обязан различать реализации, чтобы выбрать нужную по роли
		// (два аргумента одного интерфейса автовайринг не различил бы).
		// Контракт соблюдён на выходе: viewFor() возвращает ProfileViewInterface.
		private readonly TeacherProfileView      $teacherView,
		private readonly LearnerProfileView      $learnerView,
		private readonly SubjectRepository       $subjects,
	) {}


	/**
	 * Собирает контекст кабинета для WP-пользователя.
	 */
	public function context( int $wpUserId ): ProfileContext {
		$user = get_userdata( $wpUserId );
		$role = UserRole::primaryForCabinet( $user ? (array) $user->roles : array() );

		$person   = $this->persons->findByWpUserId( $wpUserId );
		$personId = $person?->id;

		$readOnly        = ( UserRole::FSParent === $role );
		$subjectPersonId = $personId;
		$children        = array();

		// Родитель: данные ребёнка + переключатель детей + только чтение.
		if ( UserRole::FSParent === $role && null !== $personId ) {
			foreach ( $this->records->findActiveByParent( $personId ) as $rec ) {
				$children[] = array(
					'personId' => $rec->studentPersonId,
					'name'     => trim( $rec->snapshotLastName . ' ' . $rec->snapshotFirstName ),
				);
			}
			$subjectPersonId = $children[0]['personId'] ?? null;
		}

		return new ProfileContext( $wpUserId, $personId, $role, $subjectPersonId, $readOnly, $children );
	}

	/**
	 * Возвращает витрину для роли, либо null для ролей без фронт-кабинета (офисные).
	 */
	public function viewFor( UserRole $role ): ?ProfileViewInterface {
		// FSOffice использует ту же витрину с группами, но видит ВСЕ группы (см. jsConfig).
		if ( UserRole::FSTeacher === $role || UserRole::FSOffice === $role ) {
			return $this->teacherView;
		}
		if ( in_array( $role, array( UserRole::FSStudent, UserRole::FSParent, UserRole::Student ), true ) ) {
			return $this->learnerView;
		}
		return null;
	}

	/**
	 * Собирает `window.fsProfile` для локализации в Enqueue.
	 *
	 * @return array<string, mixed>
	 */
	public function jsConfig( int $wpUserId ): array {
		$ctx    = $this->context( $wpUserId );
		$config = $this->baseConfig( $wpUserId, $ctx );

		// Колокольчик уведомлений — общий для всех ролей кабинета (не только препода/ученика).
		$config['notifications'] = array(
			'nonce'   => Nonce::Notifications->create(),
			'actions' => array(
				'list'        => AjaxHook::GetNotifications->jsAction(),
				'count'       => AjaxHook::GetNotificationsCount->jsAction(),
				'markRead'    => AjaxHook::MarkNotificationRead->jsAction(),
				'markAllRead' => AjaxHook::MarkAllNotificationsRead->jsAction(),
			),
		);

		// Учащийся/родитель (Эпик 7): один endpoint профиля (read-only).
		if ( in_array( $ctx->role, array( UserRole::FSStudent, UserRole::FSParent, UserRole::Student ), true ) ) {
			$config['learner'] = array(
				'nonce'   => Nonce::LearnerProfile->create(),
				'actions' => array(
					'getProfile' => AjaxHook::GetLearnerProfile->jsAction(),
					// Эпик 15 (П10): самозапись в открытую группу (нонс профиля переиспользуется,
					// как SaveSchedule в блоках преподавателя).
					'selfEnroll' => AjaxHook::SelfEnrollOpenGroup->jsAction(),
					// Задачи 12/13: деталь своей работы/попытки (эталонные ответы + футер).
					'getOwnDetail' => AjaxHook::GetOwnWorkDetail->jsAction(),
				),
			);
		}

		return $config;
	}

	/**
	 * Общая часть конфига: роль, пользователь, витрина (nav/screens), URL-ы.
	 *
	 * @return array<string, mixed>
	 */
	private function baseConfig( int $wpUserId, ProfileContext $ctx ): array {
		$view  = $this->viewFor( $ctx->role );
		$built = $view ? $view->build( $ctx ) : array( 'nav' => array(), 'screens' => array() );

		$user = get_userdata( $wpUserId );
		$name = $user ? ( $user->display_name ?: $user->user_login ) : '';

		return array(
			'role'            => $ctx->role->value,
			'readOnly'        => $ctx->readOnly,
			'user'            => array(
				'name'     => $name,
				'initials' => $this->initials( $name ),
			),
			'subjectPersonId' => $ctx->subjectPersonId,
			'children'        => $ctx->children,
			'nav'             => $built['nav'],
			'screens'         => $built['screens'],
			'ajax'            => array( 'url' => admin_url( 'admin-ajax.php' ) ),
			'homeUrl'         => home_url( '/' ),
			'logoutUrl'       => wp_logout_url( home_url( '/' ) ),
		);
	}



	private function initials( string $name ): string {
		$parts = array_filter( explode( ' ', $name ) );
		$ini   = '';
		foreach ( $parts as $p ) {
			$ini .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
		}
		return mb_substr( $ini, 0, 2 );
	}
}
