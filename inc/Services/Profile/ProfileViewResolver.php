<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Contracts\ProfileViewInterface;
use Inc\DTO\Profile\ProfileContext;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Wp\Nonce;
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
final class ProfileViewResolver {

	public function __construct(
		private readonly PersonRepository        $persons,
		private readonly StudentRecordRepository $records,
		private readonly GroupsRepository        $groups,
		private readonly TeacherProfileView      $teacherView,
		private readonly LearnerProfileView      $learnerView,
	) {}

	/**
	 * Собирает контекст кабинета для WP-пользователя.
	 */
	public function context( int $wpUserId ): ProfileContext {
		$user = get_userdata( $wpUserId );
		$role = UserRole::primary( $user ? (array) $user->roles : array() );

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
		$ctx   = $this->context( $wpUserId );
		$view  = $this->viewFor( $ctx->role );
		$built = $view ? $view->build( $ctx ) : array( 'nav' => array(), 'screens' => array() );

		$user = get_userdata( $wpUserId );
		$name = $user ? ( $user->display_name ?: $user->user_login ) : '';

		$config = array(
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
		);

		// Препод и офис работают с группами (КТП/журнал). Препод — свои группы;
		// FSOffice — ВСЕ группы (доступ к любой и так разрешён `canManage` по `ManageLmsPlatform`).
		if ( UserRole::FSTeacher === $ctx->role || UserRole::FSOffice === $ctx->role ) {
			$rows = ( UserRole::FSOffice === $ctx->role )
				? $this->groups->findAll()
				: $this->groups->findByTeacherId( $wpUserId );

			$config['groups'] = array_map(
				static fn( $g ): array => array(
					'id'      => (int) $g->id,
					'name'    => $g->name,
					'subject' => $g->subject_key,
				),
				$rows
			);
			$config['schedule'] = array(
				'nonce'   => Nonce::SaveSchedule->create(),
				'actions' => array(
					'getCalendar'  => AjaxHook::GetGroupCalendar->jsAction(),
					'reflow'       => AjaxHook::ReflowSchedule->jsAction(),
					'pin'          => AjaxHook::PinLesson->jsAction(),
					'getProgram'   => AjaxHook::GetGroupProgram->jsAction(),
					'assignCourse' => AjaxHook::AssignCourse->jsAction(),
				),
			);
			$config['journal'] = array(
				'nonce'   => Nonce::SaveSchedule->create(),
				'actions' => array(
					'getJournal'     => AjaxHook::GetGroupJournal->jsAction(),
					'saveAttendance' => AjaxHook::SaveAttendance->jsAction(),
					'bulkAttendance' => AjaxHook::BulkAttendance->jsAction(),
				),
			);
		}

		return $config;
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
