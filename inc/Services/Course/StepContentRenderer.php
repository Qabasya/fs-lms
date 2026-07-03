<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\StepDTO;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Task\FillTextParser;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;

/**
 * Class StepContentRenderer
 *
 * Чистые (без ученика/группы/прогресса) помощники рендера контента шага —
 * общие для реального плеера (`LessonPlayerService`) и preview-плеера курса
 * (`CoursePreviewService`, Фаза 5). Вынесены из `LessonPlayerService`, где были
 * `private` и не переиспользовались вне гейтед-контекста ученика.
 *
 * @package Inc\Services\Course
 */
class StepContentRenderer {

	public function __construct(
		private readonly PostManager         $posts,
		private readonly TemplateResolver    $templateResolver,
		private readonly TaskCheckerRegistry $checkerRegistry,
		private readonly AssessmentManager   $assessments,
	) {}

	/** Заголовок шага: инлайн — из payload/типа, ссылочный — из связанной сущности. */
	public function resolveTitle( StepDTO $step ): string {
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
	 * Данные контрольной-шага (T14.14): мета для карточки (название, лимит,
	 * попытки) + ссылка на страницу прохождения (attempt-флоу, отдельный UI).
	 *
	 * @return array<string, mixed>
	 */
	public function renderAssessmentData( StepDTO $step ): array {
		$assessmentId = (int) ( $step->payload['ref'] ?? 0 );
		$empty        = array( 'ref' => $assessmentId, 'url' => '' );

		if ( ! $assessmentId ) {
			return $empty;
		}

		$assessment = $this->assessments->get( $assessmentId );
		if ( null === $assessment || 'publish' !== $assessment->status ) {
			return $empty;
		}

		return array(
			'ref'            => $assessmentId,
			'title'          => $assessment->title,
			'url'            => (string) get_permalink( $assessmentId ),
			'time_limit_min' => $assessment->timeLimit,
			'max_attempts'   => $assessment->attemptsAllowed,
			'task_count'     => count( $assessment->taskIds ),
		);
	}

	/**
	 * Единая точка "пост задачи → шаблон → авточек → условие/виджет", общая для
	 * task-шагов (`LessonPlayerService::renderTaskData`/`CoursePreviewService`) и
	 * задач внутри work-шага (`renderWorkData`). `null`, если пост задачи не найден.
	 *
	 * @return array{task_id:int, title:string, template:string, auto_grade:bool, condition_html:string|array<string,string>, widget_data:array<string,mixed>, meta:array<string,mixed>}|null
	 */
	public function taskBundle( int $taskId, bool $shuffle = false ): ?array {
		$post = $this->posts->get( $taskId );
		if ( ! $post ) {
			return null;
		}

		$template  = $this->templateResolver->resolveEnum( $post );
		$autoGrade = $this->checkerRegistry->has( $template );
		$metaRaw   = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
		$meta      = is_array( $metaRaw ) ? $metaRaw : array();

		return array(
			'task_id'        => $taskId,
			'title'          => $post->post_title,
			'template'       => $template->value,
			'auto_grade'     => $autoGrade,
			'condition_html' => $this->buildConditionHtml( $meta, $template ),
			'widget_data'    => $autoGrade ? $this->buildWidgetData( $meta, $template, $shuffle ) : array(),
			'meta'           => $meta,
		);
	}

	/**
	 * Безопасный HTML условия задания (без правильных ответов).
	 * Для Fill — пустая строка (условие встроено в сегменты виджета).
	 * Для Triple — ассоциативный массив [num => html].
	 *
	 * @return string|array<string, string>
	 */
	public function buildConditionHtml( array $meta, TaskTemplate $template ): string|array {
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
	public function buildWidgetData( array $meta, TaskTemplate $template, bool $shuffle ): array {
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
		$text   = (string) ( $meta['task_gap_text']['text'] ?? '' );
		$parsed = FillTextParser::parse( $text );
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
	 * @param string|null $recordingUrl Запись занятия для recording_slot-шагов
	 *                                  (`GroupLessonDTO::recordingUrl`); `null` вне
	 *                                  контекста занятия (preview курса, Фаза 5) —
	 *                                  тогда recording_slot-шаг падает на payload['url'].
	 *
	 * @return array<string, mixed>
	 */
	public function renderVideoData( StepDTO $step, ?string $recordingUrl ): array {
		$url = $this->resolveVideoUrl( $step, $recordingUrl );

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

	/** URL видео-шага: для слота записи подставляет переданную запись занятия. */
	private function resolveVideoUrl( StepDTO $step, ?string $recordingUrl ): string {
		$isSlot = (bool) ( $step->payload['recording_slot'] ?? false );
		if ( $isSlot && null !== $recordingUrl && '' !== $recordingUrl ) {
			return $recordingUrl;
		}

		return (string) ( $step->payload['url'] ?? '' );
	}
}
