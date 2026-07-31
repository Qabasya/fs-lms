<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Assessment;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Assessment\ScoreMapParser;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class ScoreMapCallbacks
 *
 * AJAX-обработчики удобного ввода таблицы перевода первичный→вторичный (T7.16).
 *
 * @package Inc\Callbacks\Assessment
 */
class ScoreMapCallbacks extends BaseController {

	use Authorizer;
	use AjaxResponse;
	use Sanitizer;

	public function __construct(
		private readonly ScoreMapParser    $parser,
		private readonly AssessmentManager $assessments,
		private readonly PostManager       $posts,
	) {
		parent::__construct();
	}

	/**
	 * Парсит сырой текст из буфера обмена в массив score_map.
	 *
	 * POST: text (сырой текст), security
	 * Возвращает: ['map' => [primary => secondary, ...]]
	 */
	public function ajaxParseScoreMap(): void {
		$this->authorize( Nonce::ScoreMap, Capability::AuthorLmsCourses );

		$text = $this->sanitizeMultilineText( 'text' );
		if ( '' === $text ) {
			$this->error( 'Текст не передан.' );
			return;
		}

		$map = $this->parser->parse( $text );
		$this->success( [ 'map' => $map ] );
	}

	/**
	 * Клонирует score_map из другой ЕГЭ-работы.
	 *
	 * POST: source_assessment_id, target_assessment_id, security
	 */
	public function ajaxCopyScoreMap(): void {
		$this->authorize( Nonce::ScoreMap, Capability::AuthorLmsCourses );

		$sourceId = $this->requireInt( 'source_assessment_id' );
		$targetId = $this->requireInt( 'target_assessment_id' );

		$source = $this->assessments->get( $sourceId );
		if ( ! $source ) {
			$this->error( 'Источник не найден.' );
			return;
		}

		if ( ! $source->kind->needsSecondaryScore() ) {
			$this->error( 'Источник не является работой ЕГЭ.' );
			return;
		}

		if ( empty( $source->scoreMap ) ) {
			$this->error( 'Таблица перевода источника пуста.' );
			return;
		}

		$target = $this->assessments->get( $targetId );
		if ( ! $target ) {
			$this->error( 'Целевая работа не найдена.' );
			return;
		}

		if ( ! $target->kind->needsSecondaryScore() ) {
			$this->error( 'Целевая работа не является работой ЕГЭ.' );
			return;
		}

		$post = $this->posts->get( $targetId );
		if ( ! $post ) {
			$this->error( 'Пост не найден.' );
			return;
		}

		$meta              = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value, true );
		$meta              = is_array( $meta ) ? $meta : [];
		$meta['score_map'] = $source->scoreMap;
		$this->posts->updateMeta( $post->ID, PostMetaName::Meta->value, $meta );

		$this->success( [ 'map' => $source->scoreMap ] );
	}

	/**
	 * Отдаёт список ЕГЭ-работ того же предмета, из которых можно скопировать шкалу.
	 *
	 * Источником считается работа с вторичным баллом (`needsSecondaryScore()`) и
	 * непустой таблицей перевода; текущая работа из списка исключается.
	 *
	 * POST: assessment_id (текущая работа), security
	 * Возвращает: ['sources' => [['id','title','pairs','range'], ...]]
	 */
	public function ajaxGetScoreMapSources(): void {
		$this->authorize( Nonce::ScoreMap, Capability::AuthorLmsCourses );

		$assessmentId = $this->requireInt( 'assessment_id' );

		$post = $this->posts->get( $assessmentId );
		if ( ! $post || ! PostTypeResolver::isAssessmentPostType( $post->post_type ) ) {
			$this->error( 'Работа не найдена.' );
			return;
		}

		$subjectKey = PostTypeResolver::subjectFromAssessmentPostType( $post->post_type );
		$sources    = [];

		foreach ( $this->assessments->getBankBySubject( $subjectKey ) as $candidate ) {
			if ( $candidate->id === $assessmentId
				|| ! $candidate->kind->needsSecondaryScore()
				|| empty( $candidate->scoreMap ) ) {
				continue;
			}

			$primaries   = array_map( 'intval', array_keys( $candidate->scoreMap ) );
			$secondaries = array_map( 'intval', array_values( $candidate->scoreMap ) );

			$sources[] = [
				'id'    => $candidate->id,
				'title' => $candidate->title,
				'pairs' => count( $candidate->scoreMap ),
				// Подсказка «0–34 → 0–100», чтобы автор понял, ту ли шкалу берёт.
				'range' => sprintf(
					'%d–%d → %d–%d',
					min( $primaries ),
					max( $primaries ),
					min( $secondaries ),
					max( $secondaries )
				),
			];
		}

		$this->success( [ 'sources' => $sources ] );
	}
}
