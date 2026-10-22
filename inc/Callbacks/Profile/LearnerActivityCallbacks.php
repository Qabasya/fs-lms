<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Profile;

use Inc\Core\BaseController;
use Inc\Enums\Log\TrackedPage;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Log\StudentActivityLogger;
use Inc\Services\Profile\ProfileViewResolver;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX ленты «Активность» для действий ученика и родителя: открытие экрана кабинета и записи занятия.
 *
 * Без capability — доступ гейтится нонсом и принадлежностью: экран пишется только за ребёнка
 * из {@see \Inc\DTO\Profile\ProfileContext}, запись — только если пользователь ученик или родитель
 * ученика группы этого занятия. Ответ всегда успешный и пустой: клиент не ждёт результата, сбой
 * записи не должен мешать открыть запись или экран.
 *
 * @package Inc\Callbacks\Profile
 */
class LearnerActivityCallbacks extends BaseController {

	use AjaxResponse;
	use Sanitizer;

	public function __construct(
		private readonly StudentActivityLogger $logger,
		private readonly ProfileViewResolver   $resolver,
		private readonly GroupLessonRepository $groupLessons,
		private readonly GroupAccessGuard      $guard,
		private readonly PersonRepository      $persons,
	) {
		parent::__construct();
	}

	/**
	 * Params: type=page|recording; page (ключ {@see TrackedPage}) | group_lesson_id; [student_person_id].
	 */
	public function ajaxLogLearnerActivity(): void {
		Nonce::LearnerActivity->verify();

		$userId = get_current_user_id();
		if ( $userId <= 0 ) {
			$this->error( __( 'Требуется вход.', 'fs-lms' ) );
			return;
		}

		match ( $this->sanitizeKey( 'type' ) ) {
			'page'      => $this->logPage( $userId ),
			'recording' => $this->logRecording( $userId ),
			default     => null,
		};

		$this->success( array() );
	}

	private function logPage( int $userId ): void {
		$page = TrackedPage::tryFrom( $this->sanitizeKey( 'page' ) );
		if ( null === $page ) {
			return;
		}

		$ctx      = $this->resolver->context( $userId );
		$personId = $ctx->resolveSubjectPersonId( $this->sanitizeInt( 'student_person_id' ) );
		if ( $personId ) {
			$this->logger->pageViewed( $userId, $personId, $page );
		}
	}

	private function logRecording( int $userId ): void {
		$row    = $this->groupLessons->find( $this->sanitizeInt( 'group_lesson_id' ) );
		$person = $this->persons->findByWpUserId( $userId );
		if ( null === $row || null === $person ) {
			return;
		}

		if ( $this->guard->isMemberEver( $row->groupId, $person->id ) || $this->guard->isParentOf( $row->groupId, $person->id ) ) {
			$this->logger->recordingOpened( $userId, $row->id );
		}
	}
}
