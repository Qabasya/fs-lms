<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\DTO\Course\LessonDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Subject\TemplateCategory;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Course\LessonManager;
use Inc\Services\Course\LessonAuthoringService;
use Inc\Services\Course\LessonVisibilityService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class LessonCallbacks
 *
 * Admin-AJAX обработчики для конструктора бакетов урока.
 *
 * @package Inc\Callbacks\Course
 */
class LessonCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	/** Максимум шагов в одном уроке (совпадает с клиентским лимитом step-editor.js). */
	private const int MAX_STEPS_PER_LESSON = 20;

	public function __construct(
		private readonly LessonAuthoringService  $authoringService,
		private readonly LessonManager           $lessonManager,
		private readonly LessonVisibilityService $visibilityService,
	) {
		parent::__construct();
	}

	/**
	 * Список кандидатов-работ для селектора урока.
	 * Params: subject_key, work_type (string), scope (mine|subject), search (string)
	 */
	public function ajaxGetLessonWorkCandidates(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$work_type   = $this->sanitizeKey( 'work_type' );
		$scope       = $this->sanitizeKey( 'scope' );
		$search      = $this->sanitizeText( 'search' );

		if ( ! in_array( $scope, array( 'mine', 'subject' ), true ) ) {
			$scope = 'mine';
		}

		$this->success(
			$this->authoringService->getWorkCandidates( $subject_key, $work_type, $scope, $search )
		);
	}

	/**
	 * Создаёт черновик урока из конструктора курса.
	 * Params: subject_key, title
	 */
	public function ajaxCreateLessonDraft(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$title       = $this->sanitizeText( 'title' ) ?: 'Новый урок';

		$dto = LessonDTO::fromArray( array(
			'id'          => 0,
			'subject_key' => $subject_key,
			'topic'       => $title,
			'steps'       => array(),
			'author_id'   => get_current_user_id(),
			'status'      => 'draft',
		) );

		try {
			$lesson_id = $this->lessonManager->create( $subject_key, $dto );
			$this->success( array( 'id' => $lesson_id, 'title' => $title ) );
		} catch ( \Throwable $e ) {
			$this->error( 'Не удалось создать урок: ' . $e->getMessage() );
		}
	}

	/**
	 * Статьи предмета для ArticleRefField.
	 * Params: subject_key
	 */
	public function ajaxGetLessonArticles(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$articles    = $this->authoringService->getArticles( $subject_key );

		$result = array();
		foreach ( $articles as $id => $title ) {
			$result[] = array( 'id' => $id, 'title' => $title );
		}

		$this->success( $result );
	}

	/**
	 * Кандидаты для шага-ссылки (модалка билдера).
	 * Params: subject_key, kind (work|task|assessment|article), source (subject|bank), search
	 */
	public function ajaxGetStepCandidates(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$kind        = $this->sanitizeKey( 'kind' );
		$source      = $this->sanitizeKey( 'source' );
		$search      = $this->sanitizeText( 'search' );

		$this->success( $this->authoringService->getStepCandidates( $subject_key, $kind, $source, $search ) );
	}

	/**
	 * Черновик subject-задачи из билдера. Params: subject_key, title, category (question|code)
	 */
	public function ajaxCreateTaskDraft(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$title       = $this->sanitizeText( 'title' ) ?: 'Новая задача';
		$categoryRaw = $this->sanitizeKey( 'category' );
		$category    = '' !== $categoryRaw ? TemplateCategory::fromValueOrDefault( $categoryRaw ) : null;
		$id          = $this->authoringService->createTaskDraft( $subject_key, $title, $category );

		$this->success( array( 'id' => $id, 'title' => $title ) );
	}

	/**
	 * Черновик контрольной из билдера. Params: subject_key, title
	 */
	public function ajaxCreateAssessmentDraft(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$title       = $this->sanitizeText( 'title' ) ?: 'Новая контрольная';
		$id          = $this->authoringService->createAssessmentDraft( $subject_key, $title );

		$this->success( array( 'id' => $id, 'title' => $title ) );
	}

	/**
	 * Черновик статьи предмета (материал) из билдера. Params: subject_key, title
	 */
	public function ajaxCreateArticleDraft(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::ManageLmsArticles );

		$subject_key = $this->requireKey( 'subject_key' );
		$title       = $this->sanitizeText( 'title' ) ?: 'Новый материал';
		$id          = $this->authoringService->createArticleDraft( $subject_key, $title );

		$this->success( array( 'id' => $id, 'title' => $title ) );
	}

	/**
	 * Сохраняет последовательность шагов урока (билдер).
	 * Params: lesson_id, subject_key, steps[]
	 */
	public function ajaxSaveLessonSteps(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::AuthorLmsCourses );

		$lesson_id   = $this->requireInt( 'lesson_id' );
		$subject_key = $this->requireKey( 'subject_key' );
		$raw_steps   = wp_unslash( $_POST['steps'] ?? array() );
		$raw_steps   = is_array( $raw_steps ) ? $raw_steps : array();

		$lesson = $this->lessonManager->get( $lesson_id );
		if ( null === $lesson ) {
			$this->error( 'Урок не найден.' );
			return;
		}

		$sanitized = array_map( array( $this, 'sanitizeStep' ), $raw_steps );
		$steps     = $this->authoringService->buildSteps( $sanitized );

		if ( count( $steps ) > self::MAX_STEPS_PER_LESSON ) {
			$this->error( sprintf( 'В одном уроке не может быть больше %d шагов.', self::MAX_STEPS_PER_LESSON ) );
			return;
		}

		$dto = new LessonDTO(
			id        : $lesson_id,
			subjectKey: $subject_key,
			topic     : $lesson->topic,
			steps     : $steps,
			authorId  : $lesson->authorId,
			status    : $lesson->status,
		);
		$this->lessonManager->update( $lesson_id, $dto );
		// Уже открытым для групп занятиям — доложить новые работы урока (copy-on-publish).
		$this->visibilityService->syncExtraWorksForOpenOccurrences( $lesson_id );

		$this->success( array( 'count' => count( $steps ) ) );
	}

	/**
	 * Санитайз одного сырого шага по типу (поля очищаются trait-методами Sanitizer).
	 *
	 * @param mixed $raw
	 *
	 * @return array<string, mixed>
	 */
	private function sanitizeStep( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$type        = $this->sanitizeKeyValue( $raw['type'] ?? '' );
		$key         = $this->sanitizeKeyValue( $raw['key'] ?? '' );
		$raw_payload = is_array( $raw['payload'] ?? null ) ? $raw['payload'] : array();

		$payload = match ( $type ) {
			'text'               => array(
				'title'   => $this->sanitizeTextValue( $raw_payload['title'] ?? '' ),
				'content' => $this->sanitizeHtmlValue( $raw_payload['content'] ?? '' ),
			),
			'video'              => array(
				'title'       => $this->sanitizeTextValue( $raw_payload['title'] ?? '' ),
				'url'         => $this->sanitizeTextValue( $raw_payload['url'] ?? '' ),
				'description' => $this->sanitizeTextValue( $raw_payload['description'] ?? '' ),
				// D21 (T14.12): главы (перемотка в нативном плеере) и вложения-конспекты.
				'chapters'    => $this->sanitizeChapters( $raw_payload['chapters'] ?? array() ),
				'attachments' => array_values( array_filter( array_map(
					'intval',
					is_array( $raw_payload['attachments'] ?? null ) ? $raw_payload['attachments'] : array()
				) ) ),
			),
			'task'               => array(
				'ref'      => $this->sanitizeIntValue( $raw_payload['ref'] ?? 0 ),
				'source'   => 'bank' === $this->sanitizeKeyValue( $raw_payload['source'] ?? 'subject' ) ? 'bank' : 'subject',
				'settings' => array(
					'max_attempts'      => max( 0, (int) ( $raw_payload['settings']['max_attempts'] ?? 0 ) ),
					'hint_after_errors' => max( 0, (int) ( $raw_payload['settings']['hint_after_errors'] ?? 0 ) ),
				),
			),
			'work', 'assessment' => array( 'ref' => $this->sanitizeIntValue( $raw_payload['ref'] ?? 0 ) ),
			default              => array(),
		};

		// Подсказку показываем строго до исчерпания попыток: N ошибок < max_attempts
		// (0 = ∞ — ограничения нет). Клампим на сервере, не доверяя клиенту.
		if ( 'task' === $type ) {
			$max_att = (int) $payload['settings']['max_attempts'];
			if ( $max_att > 0 && $payload['settings']['hint_after_errors'] >= $max_att ) {
				$payload['settings']['hint_after_errors'] = $max_att - 1;
			}
		}

		// Метка «дубликат — контент не изменён»: переживает сохранение (напоминание преподавателю).
		if ( filter_var( $raw_payload['needs_review'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
			$payload['needs_review'] = true;
		}

		return array( 'key' => $key, 'type' => $type, 'payload' => $payload );
	}

	/**
	 * Главы видео-шага (D21): [{t: секунды, title}], отсортированы по времени.
	 * Пустые строки (без названия и с нулевым временем) отбрасываются.
	 *
	 * @param mixed $raw
	 *
	 * @return array<int, array{t:int, title:string}>
	 */
	private function sanitizeChapters( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$chapters = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = $this->sanitizeTextValue( $row['title'] ?? '' );
			$t     = max( 0, (int) ( $row['t'] ?? 0 ) );
			if ( '' === $title && 0 === $t ) {
				continue;
			}
			$chapters[] = array(
				't'     => $t,
				'title' => $title,
			);
		}

		usort( $chapters, static fn( array $a, array $b ): int => $a['t'] <=> $b['t'] );

		return $chapters;
	}
}
