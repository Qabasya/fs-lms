<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;

/**
 * Class CorrectAnswerResolver
 *
 * Достаёт человекочитаемый эталонный ответ задачи из `fs_lms_meta` по шаблону
 * (Эпик 11 T11.8). Только для teacher-facing детали работы — на клиент ученика
 * правильные ответы не отдаются (исключение — лист ответов станции КЕГЭ, см.
 * {@see \Inc\Modules\EgeComputer\Services\KegeResultSheetService}). Эталона нет
 * только у «Развёрнутого ответа» — единственного полностью ручного шаблона.
 *
 * Ключи меты соответствуют полям шаблонов/чекеров:
 *  standard/common/audio/код/файловые → task_answer; triple → task_19/20/21_answer;
 *  choice → task_options.options[].{text,correct}; matching → task_pairs.pairs[].{left,right};
 *  ordering → task_order_items.items[]; fill → task_gap_text.text (FillTextParser).
 *
 * @package Inc\Services\Task
 */
class CorrectAnswerResolver {

	public function __construct(
		private readonly PostManager $posts,
	) {}

	public function resolve( int $taskId ): ?string {
		if ( ! $this->posts->get( $taskId ) ) {
			return null;
		}
		$metaRaw = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
		$meta    = is_array( $metaRaw ) ? $metaRaw : array();
		$tplRaw  = $this->posts->getMeta( $taskId, PostMetaName::TemplateType->value );
		$tpl     = TaskTemplate::fromDatabase( is_string( $tplRaw ) ? $tplRaw : null );

		$answer = match ( $tpl ) {
			// Строка ответа `task_answer` — у всех шаблонов, кроме «Развёрнутого»
			// (см. TaskCheckerRegistry): у код/файловых не автопроверяется сам код
			// или файл, но короткий ответ у них есть и эталон для него существует.
			TaskTemplate::Standard,
			TaskTemplate::Common,
			TaskTemplate::Audio,
			TaskTemplate::Code,
			TaskTemplate::FileCode,
			TaskTemplate::File,
			TaskTemplate::TextSolution => trim( (string) ( $meta['task_answer'] ?? '' ) ),
			TaskTemplate::Triple   => $this->triple( $meta ),
			TaskTemplate::Choice   => $this->choice( $meta ),
			TaskTemplate::Matching => $this->matching( $meta ),
			TaskTemplate::Ordering => $this->ordering( $meta ),
			TaskTemplate::Fill     => $this->fill( $meta ),
			default                => '', // ручная проверка (код/файл/развёрнутый) — эталона нет
		};

		return '' === $answer ? null : $answer;
	}

	/**
	 * Id правильных опций choice-задачи — для подсветки «Правильный ответ»
	 * в виджете плеера после исчерпания попыток (D20, T14.8).
	 * Для остальных шаблонов — пустой массив.
	 *
	 * @return string[]
	 */
	public function choiceCorrectIds( int $taskId ): array {
		$tplRaw = $this->posts->getMeta( $taskId, PostMetaName::TemplateType->value );
		if ( TaskTemplate::Choice !== TaskTemplate::fromDatabase( is_string( $tplRaw ) ? $tplRaw : null ) ) {
			return array();
		}

		$metaRaw = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
		$options = is_array( $metaRaw ) ? ( $metaRaw['task_options']['options'] ?? array() ) : array();
		if ( ! is_array( $options ) ) {
			return array();
		}

		$ids = array();
		foreach ( $options as $opt ) {
			if ( is_array( $opt ) && ! empty( $opt['correct'] ) && '' !== (string) ( $opt['id'] ?? '' ) ) {
				$ids[] = (string) $opt['id'];
			}
		}

		return $ids;
	}

	/**
	 * Человекочитаемый ответ УЧЕНИКА для choice-задачи. Виджет
	 * ({@see \fs-lms/src/js/frontend/components/task-widget.js buildChoiceWidget()})
	 * хранит `collectAnswer()` как JSON-массив id выбранных опций (напр. `["1"]`),
	 * а не текст — без сопоставления с `task_options.options[].{id,text}` он и
	 * попадает «как есть» в интерфейс проверки. Для остальных шаблонов сырой
	 * ответ уже человекочитаем — метод возвращает null, вызывающий код
	 * оставляет исходную строку без изменений.
	 */
	public function formatChoiceAnswer( int $taskId, string $rawAnswerJson ): ?string {
		$tplRaw = $this->posts->getMeta( $taskId, PostMetaName::TemplateType->value );
		if ( TaskTemplate::Choice !== TaskTemplate::fromDatabase( is_string( $tplRaw ) ? $tplRaw : null ) ) {
			return null;
		}

		$ids = '' !== $rawAnswerJson ? json_decode( $rawAnswerJson, true ) : null;
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return null;
		}

		$metaRaw = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
		$options = is_array( $metaRaw ) ? ( $metaRaw['task_options']['options'] ?? array() ) : array();
		if ( ! is_array( $options ) ) {
			return null;
		}

		$textById = array();
		foreach ( $options as $opt ) {
			if ( is_array( $opt ) && '' !== (string) ( $opt['id'] ?? '' ) ) {
				$textById[ (string) $opt['id'] ] = trim( (string) ( $opt['text'] ?? '' ) );
			}
		}

		$texts = array();
		foreach ( $ids as $id ) {
			$text = $textById[ (string) $id ] ?? '';
			if ( '' !== $text ) {
				$texts[] = $text;
			}
		}

		return empty( $texts ) ? null : implode( ', ', $texts );
	}

	private function triple( array $meta ): string {
		$parts = array();
		foreach ( array( '19', '20', '21' ) as $n ) {
			$a = trim( (string) ( $meta[ "task_{$n}_answer" ] ?? '' ) );
			if ( '' !== $a ) {
				$parts[] = "{$n}: {$a}";
			}
		}
		return implode( ' | ', $parts );
	}

	private function choice( array $meta ): string {
		$options = $meta['task_options']['options'] ?? array();
		if ( ! is_array( $options ) ) {
			return '';
		}
		$correct = array();
		foreach ( $options as $opt ) {
			if ( is_array( $opt ) && ! empty( $opt['correct'] ) ) {
				$text = trim( (string) ( $opt['text'] ?? '' ) );
				if ( '' !== $text ) {
					$correct[] = $text;
				}
			}
		}
		return implode( ', ', $correct );
	}

	private function matching( array $meta ): string {
		$pairs = $meta['task_pairs']['pairs'] ?? array();
		if ( ! is_array( $pairs ) ) {
			return '';
		}
		$out = array();
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			$left  = trim( (string) ( $pair['left'] ?? '' ) );
			$right = trim( (string) ( $pair['right'] ?? '' ) );
			if ( '' !== $left || '' !== $right ) {
				$out[] = "{$left} → {$right}";
			}
		}
		return implode( '; ', $out );
	}

	private function ordering( array $meta ): string {
		$items = $meta['task_order_items']['items'] ?? array();
		if ( ! is_array( $items ) ) {
			return '';
		}
		$out = array();
		$i   = 0;
		foreach ( $items as $item ) {
			$s = trim( (string) $item );
			if ( '' !== $s ) {
				$out[] = ( ++$i ) . '. ' . $s;
			}
		}
		return implode( '   ', $out );
	}

	private function fill( array $meta ): string {
		$text = (string) ( $meta['task_gap_text']['text'] ?? '' );
		if ( '' === $text ) {
			return '';
		}
		$parsed = FillTextParser::parse( $text );
		$out    = array();
		$i      = 0;
		foreach ( $parsed->gaps as $synonyms ) {
			++$i;
			$first = ( is_array( $synonyms ) && isset( $synonyms[0] ) ) ? (string) $synonyms[0] : '';
			if ( '' !== $first ) {
				$out[] = "[{$i}] {$first}";
			}
		}
		return implode( '   ', $out );
	}
}
