<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Course\ProgressStatus;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
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
			'video' => array(
				'url'            => $this->resolveVideoUrl( $step, $groupLesson ),
				'description'    => (string) ( $step->payload['description'] ?? '' ),
				'provider'       => (string) ( $step->payload['provider'] ?? '' ),
				'recording_slot' => (bool) ( $step->payload['recording_slot'] ?? false ),
			),
			'task'     => $this->renderTaskData( $step, $groupLesson, $studentPersonId ),
			default    => array( 'ref' => (int) ( $step->payload['ref'] ?? 0 ) ),
		};
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

		return array(
			'auto_grade'     => $autoGrade,
			'template'       => $template->value,
			'condition_html' => $this->buildConditionHtml( $meta, $template ),
			'widget_data'    => $autoGrade ? $this->buildWidgetData( $meta, $template, $settings->shuffle ) : array(),
			'hint_html'      => $hintHtml,
			'settings'       => $settings->toArray(),
			'attempts_used'  => $usedCount,
			'reveal_hint'    => $revealHint,
		);
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
