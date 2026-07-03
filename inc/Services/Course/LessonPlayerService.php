<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\Enums\Course\ProgressStatus;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Services\Task\CorrectAnswerResolver;
use Inc\Services\Task\FillTextParser;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;

/**
 * Class LessonPlayerService
 *
 * Сборка view-модели пошагового плеера урока (★, T1.5.12): упорядоченные шаги с
 * гейтом (доступ) и статусом (прогресс) для конкретного ученика в уроке программы.
 * Инлайн-шаги отдают контент для рендера; task-шаги — данные виджета без правильных ответов (Этап 6).
 *
 * @package Inc\Services\Course
 */
class LessonPlayerService {

	public function __construct(
		private readonly LessonManager                $lessons,
		private readonly LessonGateResolver           $gate,
		private readonly LessonProgressService        $progress,
		private readonly PostManager                  $posts,
		private readonly TaskAttemptRepository        $taskAttempts,
		private readonly EffectiveStepSettingsResolver $settingsResolver,
		private readonly TemplateResolver             $templateResolver,
		private readonly TaskCheckerRegistry          $checkerRegistry,
		private readonly CorrectAnswerResolver        $correctAnswers,
		private readonly WorkManager                  $works,
		private readonly SubmissionService            $submissionService,
	) {}

	/**
	 * @return array{group_lesson_id:int, lesson_id:int, topic:string, steps:array<int, array<string,mixed>>}|null
	 */
	public function buildView( int $studentPersonId, GroupLessonDTO $groupLesson ): ?array {
		$lesson = $groupLesson->lessonId ? $this->lessons->get( $groupLesson->lessonId ) : null;
		if ( null === $lesson ) {
			return null;
		}

		$statuses = $this->progress->getStepStatuses( $studentPersonId, $groupLesson->id );

		$steps = array();
		foreach ( $lesson->steps as $step ) {
			$status = $statuses[ $step->key ] ?? ProgressStatus::Available;
			$gate   = $this->gate->resolveStep( $studentPersonId, $groupLesson, $step->key );

			$steps[] = array(
				'key'    => $step->key,
				'type'   => $step->type->value,
				'title'  => $this->resolveTitle( $step ),
				'gate'   => $gate->value,
				'status' => $status->value,
				'render' => $this->renderData( $step, $groupLesson, $studentPersonId ),
			);
		}

		return array(
			'group_lesson_id' => $groupLesson->id,
			'lesson_id'       => $lesson->id,
			'topic'           => $lesson->topic,
			'steps'           => $steps,
		);
	}

	/** Заголовок шага: инлайн — из payload/типа, ссылочный — из связанной сущности. */
	private function resolveTitle( StepDTO $step ): string {
		$title = (string) ( $step->payload['title'] ?? '' );
		if ( '' !== $title ) {
			return $title;
		}

		$refId = (int) ( $step->payload['ref'] ?? $step->payload['article_id'] ?? 0 );
		if ( $refId > 0 ) {
			$post = $this->posts->get( $refId );
			if ( $post instanceof \WP_Post && '' !== $post->post_title ) {
				return $post->post_title;
			}
		}

		return $step->type->label();
	}

	/**
	 * Данные для рендера шага по типу.
	 *
	 * @return array<string, mixed>
	 */
	private function renderData( StepDTO $step, GroupLessonDTO $groupLesson, int $studentPersonId ): array {
		return match ( $step->type->value ) {
			'text'  => array( 'content' => (string) ( $step->payload['content'] ?? '' ) ),
			'video' => $this->renderVideoData( $step, $groupLesson ),
			'task'     => $this->renderTaskData( $step, $groupLesson, $studentPersonId ),
			'work'     => $this->renderWorkData( $step, $groupLesson, $studentPersonId ),
			default    => array( 'ref' => (int) ( $step->payload['ref'] ?? 0 ) ),
		};
	}

	/**
	 * Данные work-шага для прохождения в плеере (D19, T14.9): задачи работы
	 * (условия + виджеты БЕЗ ответов, как task-шаги), мета работы и текущая
	 * сдача (агрегат + пооответные вердикты/баллы/фидбек).
	 *
	 * @return array<string, mixed>
	 */
	private function renderWorkData( StepDTO $step, GroupLessonDTO $groupLesson, int $studentPersonId ): array {
		$workId = (int) ( $step->payload['ref'] ?? 0 );
		$empty  = array(
			'ref'        => $workId,
			'work_found' => false,
		);

		if ( ! $workId ) {
			return $empty;
		}

		$work = $this->works->get( $workId );
		if ( null === $work ) {
			return $empty;
		}

		$tasks = array();
		foreach ( $work->itemIds as $taskId ) {
			$post = $this->posts->get( $taskId );
			if ( ! $post ) {
				continue;
			}

			$template  = $this->templateResolver->resolveEnum( $post );
			$autoGrade = $this->checkerRegistry->has( $template );
			$metaRaw   = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
			$meta      = is_array( $metaRaw ) ? $metaRaw : array();

			$tasks[] = array(
				'task_id'        => $taskId,
				'title'          => $post->post_title,
				'template'       => $template->value,
				'auto_grade'     => $autoGrade,
				'condition_html' => $this->buildConditionHtml( $meta, $template ),
				// Ручные шаблоны в работе сдаются свободным текстом (проверит преподаватель).
				'widget_data'    => $autoGrade
					? $this->buildWidgetData( $meta, $template, false )
					: array( 'type' => 'text_answer' ),
			);
		}

		return array(
			'ref'             => $workId,
			'work_found'      => true,
			'title'           => $work->title,
			'work_type'       => $work->workType->value,
			'work_type_label' => $work->workType->label(),
			'instructions'    => wp_kses_post( $work->instructions ),
			'task_count'      => count( $tasks ),
			// Батч-проверка без явных весов — вес каждой задачи равен 1.
			'total_points'    => count( $tasks ),
			'tasks'           => $tasks,
		) + $this->currentSubmission( $studentPersonId, $groupLesson->id, $workId );
	}

	/**
	 * Текущая сдача работы учеником: агрегатная строка (task_id = null, в ней
	 * вердикты батч-проверки) + пооответные строки (статус/баллы/фидбек).
	 *
	 * @return array{submission: array<string,mixed>|null, task_results: array<int, array<string,mixed>>}
	 */
	private function currentSubmission( int $studentPersonId, int $groupLessonId, int $workId ): array {
		$aggregate   = null;
		$taskResults = array();

		foreach ( $this->submissionService->getSubmissionsForView( $studentPersonId, $groupLessonId ) as $submission ) {
			if ( $submission->workId !== $workId ) {
				continue;
			}

			if ( null === $submission->taskId ) {
				$verdicts  = json_decode( (string) $submission->answerText, true );
				$aggregate = array(
					'status'       => $submission->status->value,
					'status_label' => $submission->status->label(),
					'score'        => $submission->score,
					'max_score'    => $submission->maxScore,
					'submitted_at' => $submission->submittedAt,
					'verdicts'     => is_array( $verdicts ) ? $verdicts : array(),
				);
				continue;
			}

			$taskResults[ $submission->taskId ] = $this->taskResult( $submission );
		}

		return array(
			'submission'   => $aggregate,
			'task_results' => $taskResults,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function taskResult( SubmissionDTO $submission ): array {
		return array(
			'status'    => $submission->status->value,
			'answer'    => $submission->answerText,
			'score'     => $submission->score,
			'max_score' => $submission->maxScore,
			'feedback'  => null !== $submission->feedback ? wp_kses_post( $submission->feedback ) : null,
		);
	}

	/**
	 * Данные task-шага: условие, виджет (без ответов), подсказка, настройки, прогресс.
	 *
	 * @return array<string, mixed>
	 */
	private function renderTaskData( StepDTO $step, GroupLessonDTO $groupLesson, int $studentPersonId ): array {
		$empty = array(
			'auto_grade'     => false,
			'template'       => '',
			'condition_html' => '',
			'widget_data'    => array(),
			'hint_html'      => '',
			'settings'       => array(),
			'attempts_used'  => 0,
			'reveal_hint'    => false,
		);

		$taskId = (int) ( $step->payload['ref'] ?? 0 );
		if ( ! $taskId ) {
			return $empty;
		}

		$post = $this->posts->get( $taskId );
		if ( ! $post ) {
			return $empty;
		}

		$template  = $this->templateResolver->resolveEnum( $post );
		$autoGrade = $this->checkerRegistry->has( $template );
		$meta      = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$settings   = $this->settingsResolver->resolve( $step, $groupLesson, $template );
		$attempts   = $this->taskAttempts->listByStep( $studentPersonId, $groupLesson->id, $step->key );
		$usedCount  = count( $attempts );
		$wrongCount = count( array_filter( $attempts, static fn( $a ) => false === $a->isCorrect ) );
		$hintHtml   = wp_kses_post( (string) ( $meta['task_hint'] ?? '' ) );
		$revealHint = '' !== $hintHtml && ( $settings->hintAfterErrors === 0 || $wrongCount >= $settings->hintAfterErrors );

		$data = array(
			'auto_grade'     => $autoGrade,
			'template'       => $template->value,
			'condition_html' => $this->buildConditionHtml( $meta, $template ),
			'widget_data'    => $autoGrade ? $this->buildWidgetData( $meta, $template, $settings->shuffle ) : array(),
			'hint_html'      => $hintHtml,
			'settings'       => $settings->toArray(),
			'attempts_used'  => $usedCount,
			'reveal_hint'    => $revealHint,
		);

		// D20 (T14.8): шаг провален с исчерпанием попыток — эталон виден и после
		// перезагрузки страницы (в submit-ответе его отдаёт SubmitTaskAnswerCallbacks).
		$exhausted  = $settings->maxAttempts > 0 && $usedCount >= $settings->maxAttempts;
		$hasCorrect = array() !== array_filter( $attempts, static fn( $a ) => true === $a->isCorrect );
		if ( $autoGrade && $exhausted && ! $hasCorrect ) {
			$data['correct_answer'] = $this->correctAnswers->resolve( $taskId );

			$correctIds = $this->correctAnswers->choiceCorrectIds( $taskId );
			if ( array() !== $correctIds ) {
				$data['correct_answer_ids'] = $correctIds;
			}
		}

		return $data;
	}

	/**
	 * Безопасный HTML условия задания (без правильных ответов).
	 * Для Fill — пустая строка (условие встроено в сегменты виджета).
	 * Для Triple — ассоциативный массив [num => html].
	 *
	 * @return string|array<string, string>
	 */
	private function buildConditionHtml( array $meta, TaskTemplate $template ): string|array {
		if ( TaskTemplate::Triple === $template ) {
			return array(
				'19' => wp_kses_post( (string) ( $meta['task_19_condition'] ?? '' ) ),
				'20' => wp_kses_post( (string) ( $meta['task_20_condition'] ?? '' ) ),
				'21' => wp_kses_post( (string) ( $meta['task_21_condition'] ?? '' ) ),
			);
		}

		if ( TaskTemplate::Fill === $template ) {
			return '';
		}

		return wp_kses_post( (string) ( $meta['task_condition'] ?? '' ) );
	}

	/**
	 * Данные для JS-виджета: только отображаемая структура, без правильных ответов.
	 *
	 * @return array<string, mixed>
	 */
	private function buildWidgetData( array $meta, TaskTemplate $template, bool $shuffle ): array {
		return match ( $template ) {
			TaskTemplate::Standard, TaskTemplate::Common =>
				array( 'type' => 'text_answer' ),

			TaskTemplate::Audio =>
				array(
					'type'      => 'audio',
					'audio_url' => $this->resolveAudioUrl( (int) ( $meta['task_audio']['attachment_id'] ?? 0 ) ),
				),

			TaskTemplate::Triple =>
				array( 'type' => 'triple' ),

			TaskTemplate::Choice =>
				$this->buildChoiceData( $meta, $shuffle ),

			TaskTemplate::Matching =>
				$this->buildMatchingData( $meta, $shuffle ),

			TaskTemplate::Ordering =>
				$this->buildOrderingData( $meta, $shuffle ),

			TaskTemplate::Fill =>
				$this->buildFillData( $meta ),

			// Эпик 13 (D16): FileAnswer здесь намеренно НЕ обрабатывается — шаговые
			// задания урока (task_attempts) требуют авто-проверки (SubmitTaskAnswerCallbacks
			// жёстко отклоняет шаблоны без чекера в TaskCheckerRegistry) и не имеют
			// поверхности ручной проверки для учителя. FileAnswer живёт только в
			// экзаменах/контрольных (AssessmentPageController, T13.5).
			default => array( 'type' => 'text_answer' ),
		};
	}

	private function buildChoiceData( array $meta, bool $shuffle ): array {
		$opts     = is_array( $meta['task_options'] ?? null ) ? $meta['task_options'] : array();
		$multiple = (bool) ( $opts['multiple'] ?? false );
		$options  = array_values( array_map(
			static fn( $o ) => array( 'id' => (string) ( $o['id'] ?? '' ), 'text' => (string) ( $o['text'] ?? '' ) ),
			is_array( $opts['options'] ?? null ) ? $opts['options'] : array()
		) );
		if ( $shuffle ) {
			shuffle( $options );
		}
		return array( 'type' => 'choice', 'multiple' => $multiple, 'options' => $options );
	}

	private function buildMatchingData( array $meta, bool $shuffle ): array {
		$rawPairs = is_array( $meta['task_pairs']['pairs'] ?? null ) ? $meta['task_pairs']['pairs'] : array();
		$lefts    = array_values( array_map(
			static fn( $p, $i ) => array( 'id' => $i, 'text' => (string) ( $p['left'] ?? '' ) ),
			$rawPairs,
			array_keys( $rawPairs )
		) );
		$rights   = array_values( array_map( static fn( $p ) => (string) ( $p['right'] ?? '' ), $rawPairs ) );
		if ( $shuffle ) {
			shuffle( $rights );
		}
		return array( 'type' => 'matching', 'lefts' => $lefts, 'rights' => $rights );
	}

	private function buildOrderingData( array $meta, bool $shuffle ): array {
		$items = is_array( $meta['task_order_items']['items'] ?? null ) ? $meta['task_order_items']['items'] : array();
		$data  = array_values( array_map(
			static fn( $text, $i ) => array( 'id' => $i, 'text' => (string) $text ),
			$items,
			array_keys( $items )
		) );
		if ( $shuffle ) {
			shuffle( $data );
		}
		return array( 'type' => 'ordering', 'items' => $data );
	}

	private function buildFillData( array $meta ): array {
		$text     = (string) ( $meta['task_gap_text']['text'] ?? '' );
		$parsed   = FillTextParser::parse( $text );
		return array( 'type' => 'fill', 'segments' => $parsed->segments );
	}

	private function resolveAudioUrl( int $attachmentId ): string {
		if ( ! $attachmentId ) {
			return '';
		}
		$url = wp_get_attachment_url( $attachmentId );
		return $url ?: '';
	}

	/**
	 * Данные видео-шага (D21, T14.12): режим по источнику (прямой файл → нативный
	 * плеер с кастомным хромом, иначе oembed-карточка), главы и вложения-конспекты.
	 *
	 * @return array<string, mixed>
	 */
	private function renderVideoData( StepDTO $step, GroupLessonDTO $groupLesson ): array {
		$url = $this->resolveVideoUrl( $step, $groupLesson );

		return array(
			'url'            => $url,
			'description'    => (string) ( $step->payload['description'] ?? '' ),
			'provider'       => (string) ( $step->payload['provider'] ?? '' ),
			'recording_slot' => (bool) ( $step->payload['recording_slot'] ?? false ),
			'mode'           => $this->resolveVideoMode( $url ),
			'chapters'       => $this->videoChapters( $step ),
			'attachments'    => $this->videoAttachments( $step ),
		);
	}

	/**
	 * Режим плеера по источнику (D21): прямой файл (S3: mp4/hls и т.п.) — нативный
	 * `<video>`; всё остальное (VK/Rutube/YouTube) — oembed-карточка.
	 */
	private function resolveVideoMode( string $url ): string {
		if ( '' === $url ) {
			return 'none';
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $ext, array( 'mp4', 'webm', 'ogv', 'mov', 'm4v', 'm3u8' ), true ) ? 'native' : 'embed';
	}

	/**
	 * @return array<int, array{t:int, title:string}>
	 */
	private function videoChapters( StepDTO $step ): array {
		$raw = is_array( $step->payload['chapters'] ?? null ) ? $step->payload['chapters'] : array();

		$chapters = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$chapters[] = array(
				't'     => max( 0, (int) ( $row['t'] ?? 0 ) ),
				'title' => (string) ( $row['title'] ?? '' ),
			);
		}

		return $chapters;
	}

	/**
	 * Вложения-конспекты видео-шага: карточки для скачивания.
	 *
	 * @return array<int, array{id:int, title:string, url:string, ext:string, size:string}>
	 */
	private function videoAttachments( StepDTO $step ): array {
		$ids = is_array( $step->payload['attachments'] ?? null ) ? $step->payload['attachments'] : array();

		$attachments = array();
		foreach ( $ids as $id ) {
			$id  = (int) $id;
			$url = $id > 0 ? (string) wp_get_attachment_url( $id ) : '';
			if ( '' === $url ) {
				continue;
			}

			$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
			$title = get_the_title( $id );
			$file  = get_attached_file( $id );
			$size  = ( $file && file_exists( $file ) ) ? size_format( (int) filesize( $file ) ) : '';

			$attachments[] = array(
				'id'    => $id,
				'title' => '' !== $title ? $title : basename( $path ),
				'url'   => $url,
				'ext'   => strtoupper( pathinfo( $path, PATHINFO_EXTENSION ) ),
				'size'  => (string) $size,
			);
		}

		return $attachments;
	}

	/**
	 * URL видео-шага: для слота записи подставляет recordingUrl группового урока.
	 */
	private function resolveVideoUrl( StepDTO $step, GroupLessonDTO $groupLesson ): string {
		$isSlot = (bool) ( $step->payload['recording_slot'] ?? false );
		if ( $isSlot && null !== $groupLesson->recordingUrl && '' !== $groupLesson->recordingUrl ) {
			return $groupLesson->recordingUrl;
		}

		return (string) ( $step->payload['url'] ?? '' );
	}
}
