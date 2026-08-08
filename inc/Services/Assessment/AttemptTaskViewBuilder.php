<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\StepContentRenderer;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class AttemptTaskViewBuilder
 *
 * Per-task view-данные страницы прохождения контрольной (T13.5, D16):
 * тип шаблона, материалы «Развёрнутого ответа», номер задания и HTML условия.
 *
 * @package Inc\Services\Assessment
 *
 * Эталонные решения и критерии сюда НЕ попадают — на страницу ученика они не отдаются.
 */
readonly class AttemptTaskViewBuilder {

	/**
	 * @param PostManager         $posts   Доступ к записям заданий
	 * @param StepContentRenderer $content Канонический рендер условия задания
	 */
	public function __construct(
		private PostManager         $posts,
		private StepContentRenderer $content,
	) {}

	/**
	 * Собирает view-данные для списка заданий контрольной.
	 *
	 * `taskNumber` (T15.10, станция КЕГЭ) — номер задания из фиксированной таксономии
	 * `{key}_task_number` (та же, что использует EgeCompletenessChecker); 0, если
	 * терм не назначен. Станция КЕГЭ по нему рисует табличный (многозначный) ввод
	 * ответа для официальных заданий №25/№27.
	 *
	 * @param int[]               $taskIds    Задания контрольной
	 * @param string              $subjectKey Ключ предмета (для таксономии номеров)
	 * @param AssessmentKind|null $kind       Вид контрольной (ЕГЭ разворачивает составные)
	 *
	 * @return array<int, array{template: string, materials: array<int, array{url: string, name: string}>, taskNumber: int, condition: string, subparts: array}>
	 */
	public function build( array $taskIds, string $subjectKey = '', ?AssessmentKind $kind = null ): array {
		// Задача 3: в режиме ЕГЭ составное задание (Triple 19-21) разворачивается в
		// три отдельных подпункта — каждый со своим условием, номером и полем ответа.
		$expandComposites = null !== $kind && $kind->expandsComposites();
		$taxonomy         = '' !== $subjectKey ? PostTypeResolver::getTaskTaxonomy( $subjectKey ) : '';

		// ID приходят из таблицы контрольной, не из WP_Query — без прогрева каждый
		// getMeta() в цикле шёл бы отдельным запросом (2 на задание).
		$this->posts->primeMetaCache( $taskIds );

		$views = array();
		foreach ( $taskIds as $taskId ) {
			$taskId   = (int) $taskId;
			$template = TaskTemplate::fromDatabase( (string) $this->posts->getMeta( $taskId, PostMetaName::TemplateType->value ) );

			$metaRaw = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
			$meta    = is_array( $metaRaw ) ? $metaRaw : array();

			$views[ $taskId ] = array(
				'template'   => $template->value,
				'materials'  => TaskTemplate::FileAnswer === $template ? $this->materials( $meta ) : array(),
				'taskNumber' => $this->taskNumber( $taskId, $taxonomy ),
				'condition'  => $this->condition( $taskId, $meta, $template ),
				'subparts'   => $expandComposites && TaskTemplate::Triple === $template
					? $this->subparts( $meta, $template )
					: array(),
			);
		}

		return $views;
	}

	/**
	 * Вложения задания «Развёрнутый ответ».
	 *
	 * @param array $meta Мета задания
	 *
	 * @return array<int, array{url: string, name: string}>
	 */
	private function materials( array $meta ): array {
		$materials = array();

		foreach ( (array) ( $meta['task_materials']['attachment_ids'] ?? array() ) as $attachmentId ) {
			$attachmentId = (int) $attachmentId;
			$url          = $attachmentId ? wp_get_attachment_url( $attachmentId ) : '';
			if ( ! $url ) {
				continue;
			}
			$materials[] = array(
				'url'  => $url,
				'name' => get_the_title( $attachmentId ) ?: "Файл #{$attachmentId}",
			);
		}

		return $materials;
	}

	/**
	 * Номер задания из фиксированной таксономии предмета (0 — терм не назначен).
	 *
	 * @param int    $taskId   ID задания
	 * @param string $taxonomy Таксономия номеров ('' — предмет не задан)
	 */
	private function taskNumber( int $taskId, string $taxonomy ): int {
		if ( '' === $taxonomy ) {
			return 0;
		}

		$terms = wp_get_post_terms( $taskId, $taxonomy, array( 'fields' => 'names' ) );

		return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? (int) $terms[0] : 0;
	}

	/**
	 * Подпункты составного задания (Triple 19-21) — каждый со своим условием и номером.
	 *
	 * @param array        $meta     Мета задания
	 * @param TaskTemplate $template Шаблон задания
	 *
	 * @return array<int, array{key: string, number: int, condition: string}>
	 */
	private function subparts( array $meta, TaskTemplate $template ): array {
		$parts = $this->content->buildConditionHtml( $meta, $template );
		$parts = is_array( $parts ) ? $parts : array();

		$subparts = array();
		foreach ( array( '19', '20', '21' ) as $subKey ) {
			$subparts[] = array(
				'key'       => $subKey,
				'number'    => (int) $subKey,
				'condition' => (string) ( $parts[ $subKey ] ?? '' ),
			);
		}

		return $subparts;
	}

	/**
	 * Безопасный HTML условия задания.
	 *
	 * Условие хранится в `fs_lms_meta['task_condition']` (не в post_content), поэтому
	 * строим его через канонический StepContentRenderer — как это делает плеер.
	 * Составные (Triple) склеиваем; пустое условие → fallback на post_content (легаси-задачи).
	 *
	 * @param int          $taskId   ID задания
	 * @param array        $meta     Мета задания
	 * @param TaskTemplate $template Шаблон задания
	 */
	private function condition( int $taskId, array $meta, TaskTemplate $template ): string {
		$html = $this->content->buildConditionHtml( $meta, $template );

		if ( is_array( $html ) ) {
			$html = implode( '', array_map(
				static fn( string $part ): string => '<div class="fs-attempt-subcondition">' . $part . '</div>',
				$html
			) );
		}

		if ( '' === trim( (string) $html ) ) {
			$post = get_post( $taskId );
			$html = $post ? wp_kses_post( apply_filters( 'the_content', $post->post_content ) ) : '';
		}

		return (string) $html;
	}
}
