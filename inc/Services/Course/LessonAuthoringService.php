<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Enums\Course\StepType;
use Inc\Enums\Course\WorkType;
use Inc\Enums\Subject\TemplateCategory;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Task\TaskBundleService;
use Inc\Services\Template\TemplateRegistry;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class LessonAuthoringService
 *
 * Бизнес-логика авторинга урока: кандидаты-работы для селектора, статьи, валидация,
 * санитайз/сборка шагов. Единая точка входа для авторинга урока методистом
 * (`LessonCallbacks`). Урок ссылается на работы, не на задачи. Доступ к данным — через PostManager.
 *
 * @package Inc\Services\Course
 */
class LessonAuthoringService {

	use Sanitizer;

	/** Максимум шагов в одном уроке (совпадает с клиентским лимитом step-editor.js). */
	public const int MAX_STEPS_PER_LESSON = 20;

	public function __construct(
		private readonly PostManager      $posts,
		private readonly LessonManager    $lessons,
		private readonly TemplateRegistry $templates,
		private readonly TaskBundleService $taskBundles,
	) {}

	/**
	 * Кандидаты-работы для селектора урока (только текущий предмет).
	 *
	 * @param string $subjectKey
	 * @param string $workType  '' = все типы
	 * @param string $scope     'mine' | 'subject'
	 * @param string $search
	 * @return array<int, array{id: int, title: string, work_type: string, author: int}>
	 */
	public function getWorkCandidates(
		string $subjectKey,
		string $workType = '',
		string $scope    = 'mine',
		string $search   = ''
	): array {
		$posts = $this->posts->search( PostTypeResolver::works( $subjectKey ), array(
			'limit'  => 50,
			'author' => 'mine' === $scope ? get_current_user_id() : 0,
			'search' => $search,
		) );

		// Фильтр по типу работы — на стороне PHP (тип лежит в сериализованной мете).
		$filter_type = WorkType::tryFrom( $workType );

		$result = array();
		foreach ( $posts as $post ) {
			$meta      = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value );
			$post_type = is_array( $meta ) ? (string) ( $meta['work_type'] ?? '' ) : '';

			if ( null !== $filter_type && $post_type !== $filter_type->value ) {
				continue;
			}

			$result[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'work_type' => $post_type,
				'author'    => (int) $post->post_author,
			);
		}

		return $result;
	}

	/**
	 * Статьи предмета для ArticleRefField.
	 *
	 * @param string $subjectKey
	 * @return array<int, string> post_id => title
	 */
	public function getArticles( string $subjectKey ): array {
		$result = array();
		foreach ( $this->posts->getAll( PostTypeResolver::articles( $subjectKey ) ) as $post ) {
			$result[ $post->ID ] = $post->post_title;
		}

		return $result;
	}

	/**
	 * Кандидаты для шага-ссылки (модалка «Добавить шаг», T1.5.5).
	 *
	 * @param string $subjectKey
	 * @param string $kind     work|task|assessment|article|lesson
	 * @param string $source   subject|bank|all — источник задачи (для kind=task; all = предмет + банк)
	 * @param string $search
	 * @param string $position Номер позиции экзамена (для kind=task в конструкторе ЕГЭ/ОГЭ) — фильтрует
	 *                         предметные задачи по терму `{subject}_task_number` и банковские по
	 *                         {@see PostMetaName::BankTaskSubject}/{@see PostMetaName::BankTaskNumber}.
	 *                         '' — без фильтра (обычный степ урока/работы).
	 *
	 * @return array<int, array{id: int, title: string, source?: string}>
	 */
	public function getStepCandidates( string $subjectKey, string $kind, string $source = 'subject', string $search = '', string $position = '' ): array {
		// Задача-шаг тянется из обоих источников сразу (предмет + банк) — вариант А.
		if ( 'task' === $kind && 'all' === $source ) {
			return array_merge(
				$this->candidatesFrom( PostTypeResolver::tasks( $subjectKey ), $search, 'subject', true, $this->taskNumberQuery( $subjectKey, $position ), $position ),
				$this->candidatesFrom( PostTypeResolver::problems(), $search, 'bank', true, $this->bankNumberQuery( $subjectKey, $position ), $position )
			);
		}

		$post_type = match ( $kind ) {
			'work'       => PostTypeResolver::works( $subjectKey ),
			'assessment' => PostTypeResolver::assessments( $subjectKey ),
			'article'    => PostTypeResolver::articles( $subjectKey ),
			'lesson'     => PostTypeResolver::lessons( $subjectKey ),
			'task'       => 'bank' === $source ? PostTypeResolver::problems() : PostTypeResolver::tasks( $subjectKey ),
			default      => '',
		};

		if ( '' === $post_type ) {
			return array();
		}

		$origin     = 'task' === $kind ? ( 'bank' === $source ? 'bank' : 'subject' ) : '';
		$extraQuery = 'task' !== $kind ? array() : ( 'bank' === $source
			? $this->bankNumberQuery( $subjectKey, $position )
			: $this->taskNumberQuery( $subjectKey, $position ) );

		return $this->candidatesFrom( $post_type, $search, $origin, 'task' === $kind, $extraQuery, $position );
	}

	/**
	 * Фильтр предметных задач по номеру позиции — терм `{subject}_task_number` с именем $position.
	 *
	 * @return array{tax_query?: array}
	 */
	private function taskNumberQuery( string $subjectKey, string $position ): array {
		if ( '' === $position ) {
			return array();
		}

		return array(
			'tax_query' => array(
				array(
					'taxonomy' => PostTypeResolver::getTaskTaxonomy( $subjectKey ),
					'field'    => 'name',
					'terms'    => $position,
				),
			),
		);
	}

	/**
	 * Фильтр банковских задач (fs_lms_problems) по номеру позиции — своей мете
	 * (у CPT банка нет таксономии номеров), см. {@see PostMetaName::BankTaskSubject}.
	 *
	 * @return array{meta_query?: array}
	 */
	private function bankNumberQuery( string $subjectKey, string $position ): array {
		if ( '' === $position ) {
			return array();
		}

		return array(
			'meta_query' => array(
				array( 'key' => PostMetaName::BankTaskSubject->value, 'value' => $subjectKey ),
				array( 'key' => PostMetaName::BankTaskNumber->value, 'value' => $position ),
			),
		);
	}

	/**
	 * Кандидаты одного CPT в формате пикера. `$source` (если задан) проставляет
	 * происхождение задачи (subject|bank) — нужно для payload.source шага.
	 * `$withBundles` — только для задач: добавляет `bundle_children`, если пост —
	 * parent связки 19-21 (см. .docs/Tasks.md, §3.4).
	 * `$extraQuery` — доп. tax_query/meta_query (см. {@see taskNumberQuery()}/{@see bankNumberQuery()}).
	 * `$position` — непусто только для позиционного поиска EGE/ОГЭ-конструктора:
	 * кандидату-ребёнку связки (не parent'у — у него уже есть `bundle_children`
	 * выше) добавляет `bundle_siblings` ({@see TaskBundleService::siblingsOf()}),
	 * чтобы JS сразу разложил номера 19/20/21 по позиционным слотам (задача C).
	 *
	 * @return array<int, array{id: int, title: string, source?: string, bundle_children?: array, bundle_siblings?: array}>
	 */
	private function candidatesFrom( string $post_type, string $search, string $source = '', bool $withBundles = false, array $extraQuery = array(), string $position = '' ): array {
		$result = array();
		$opts   = array_merge( array( 'limit' => 50, 'search' => $search ), $extraQuery );
		foreach ( $this->posts->search( $post_type, $opts ) as $post ) {
			$item = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
			);
			if ( '' !== $source ) {
				$item['source'] = $source;
			}
			if ( $withBundles ) {
				$children = $this->taskBundles->childrenSummary( $post->ID );
				if ( ! empty( $children ) ) {
					$item['bundle_children'] = $children;
				} elseif ( '' !== $position ) {
					$siblings = $this->taskBundles->siblingsOf( $post->ID );
					if ( ! empty( $siblings ) ) {
						$item['bundle_siblings'] = $siblings;
					}
				}
			}
			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Черновик subject-задачи из билдера (только заголовок; детали/таксономии — при правке).
	 * Зеркалит `WorkAuthoringService::createProblemDraft` для bank-задач.
	 *
	 * @param TemplateCategory|null $category Если задана — задаче проставляется дефолтный
	 *                                        шаблон категории (question/code), чтобы при правке
	 *                                        сразу открылись нужные поля (type-first из шага).
	 */
	public function createTaskDraft( string $subjectKey, string $title, ?TemplateCategory $category = null ): int {
		$id = $this->createDraft( PostTypeResolver::tasks( $subjectKey ), $title );

		if ( $id > 0 && null !== $category ) {
			$template = $this->templates->defaultForCategory( $category );
			if ( null !== $template ) {
				$this->posts->updateMeta( $id, PostMetaName::TemplateType->value, $template->get_id() );
			}
		}

		return $id;
	}

	/**
	 * Черновик контрольной из билдера (только заголовок).
	 */
	public function createAssessmentDraft( string $subjectKey, string $title ): int {
		return $this->createDraft( PostTypeResolver::assessments( $subjectKey ), $title );
	}

	/**
	 * Черновик статьи предмета (материал) из билдера (только заголовок).
	 */
	public function createArticleDraft( string $subjectKey, string $title ): int {
		return $this->createDraft( PostTypeResolver::articles( $subjectKey ), $title );
	}

	/**
	 * Создаёт черновик-пост указанного CPT с одним заголовком.
	 */
	/**
	 * Черновик задачи в глобальном банке задач (fs_lms_problems) для контрольной.
	 * Не привязан к предмету, не появляется в «Задания предмета».
	 */
	public function createPrivateTaskDraft( string $subjectKey, string $title ): int {
		return $this->createDraft( PostTypeResolver::problems(), $title, 'draft' );
	}

	private function createDraft( string $postType, string $title, string $status = 'draft' ): int {
		return $this->posts->insert( array(
			'post_title'  => '' !== $title ? $title : 'Черновик',
			'post_type'   => $postType,
			'post_status' => $status,
			'post_author' => get_current_user_id(),
		) );
	}

	/**
	 * Строит StepDTO[] из сырого (уже санитайзнутого коллбеком) ввода билдера:
	 * валидирует тип, присваивает стабильный `key` (генерирует отсутствующий), сохраняет payload.
	 * Шаги с неизвестным типом отбрасываются.
	 *
	 * @param array<int, mixed> $rawSteps
	 * @param string            $subjectKey Предмет урока — для проверки принадлежности ref
	 *                                       (пусто = без проверки, обратная совместимость)
	 *
	 * @return StepDTO[]
	 */
	public function buildSteps( array $rawSteps, string $subjectKey = '' ): array {
		$steps = array();
		foreach ( $rawSteps as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$type = StepType::tryFrom( (string) ( $raw['type'] ?? '' ) );
			if ( null === $type ) {
				continue;
			}

			$key     = (string) ( $raw['key'] ?? '' );
			$payload = is_array( $raw['payload'] ?? null ) ? $raw['payload'] : array();

			// Хардненинг: ref шага (task/work/assessment) обязан принадлежать предмету урока
			// (task+source=bank — общему банку задач). Кривой ref из crafted-запроса прицепил бы
			// к уроку чужой контент — обнуляем ref (шаг остаётся пустым «Выбрать существующую»).
			$ref = (int) ( $payload['ref'] ?? 0 );
			if ( '' !== $subjectKey && $ref > 0
				&& ! $this->refBelongsToSubject( $type, $ref, (string) ( $payload['source'] ?? '' ), $subjectKey ) ) {
				PluginLogger::warning( 'LessonAuthoring', 'Ref шага не принадлежит предмету урока — отброшен', array(
					'ref'     => $ref,
					'type'    => $type->value,
					'subject' => $subjectKey,
				) );
				$payload['ref'] = 0;
			}

			$steps[] = new StepDTO( '' !== $key ? $key : $this->generateStepKey(), $type, $payload );
		}

		return $steps;
	}

	/**
	 * Принадлежит ли ref-пост предмету урока (для task/work/assessment).
	 * task+source=bank — общему банку задач (fs_lms_problems). Прочие типы шагов
	 * ref не имеют — считаются валидными.
	 */
	private function refBelongsToSubject( StepType $type, int $refId, string $source, string $subjectKey ): bool {
		$expected = match ( $type ) {
			StepType::Task       => 'bank' === $source ? PostTypeResolver::problems() : PostTypeResolver::tasks( $subjectKey ),
			StepType::Work       => PostTypeResolver::works( $subjectKey ),
			StepType::Assessment => PostTypeResolver::assessments( $subjectKey ),
			default              => null,
		};

		if ( null === $expected ) {
			return true;
		}

		$post = $this->posts->get( $refId );

		return $post instanceof \WP_Post && $post->post_type === $expected;
	}

	/**
	 * Стабильный идентификатор шага (без WP-зависимостей; переживает реордер).
	 */
	private function generateStepKey(): string {
		return 's_' . bin2hex( random_bytes( 6 ) );
	}

	/**
	 * Оставляет только работы нужного предмета.
	 *
	 * @param string $subjectKey
	 * @param int[]  $workIds
	 * @return int[]
	 */
	public function validateWorkIds( string $subjectKey, array $workIds ): array {
		$post_type = PostTypeResolver::works( $subjectKey );

		return array_values( array_filter( $workIds, function ( int $id ) use ( $post_type ): bool {
			$post = $this->posts->get( $id );
			return $post instanceof \WP_Post && $post->post_type === $post_type;
		} ) );
	}

	/**
	 * Санитайз одного сырого шага по типу (поля очищаются trait-методами Sanitizer).
	 * Вызывается из `LessonCallbacks::ajaxSaveLessonSteps`.
	 *
	 * @param mixed $raw
	 *
	 * @return array<string, mixed>
	 */
	public function sanitizeStep( mixed $raw ): array {
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
			// Трансляция (Этап 1): ссылка-заглушка до появления записи занятия
			// (плеер подменяет её на recording_url привязанного group_lesson).
			'broadcast'          => array(
				'title'      => $this->sanitizeTextValue( $raw_payload['title'] ?? '' ),
				'stream_url' => $this->sanitizeTextValue( $raw_payload['stream_url'] ?? '' ),
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
