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
	 * Человекочитаемый ответ УЧЕНИКА для шаблонов, чей виджет хранит ответ
	 * структурой, а не текстом ({@see \fs-lms/src/js/frontend/components/task-widget.js}):
	 *
	 *  - choice   — JSON-массив id выбранных опций (`["1"]`) → тексты опций;
	 *  - matching — `[{"left":…,"right":…}]`                → `левое → правое; …`;
	 *  - ordering — `["Первый","Второй"]`                   → `1. Первый   2. Второй`;
	 *  - fill     — `{"1":"…","2":"…"}`                     → `[1] …   [2] …`;
	 *  - triple   — `{"19":"…","20":"…","21":"…"}`          → `19: … | 20: … | 21: …`.
	 *
	 * Без этого преобразования учитель видел в проверке сырой JSON вместо
	 * ответа (Tasks.md, п. 5). Формат вывода совпадает с эталоном из
	 * {@see resolve()} — их показывают рядом, и сравнивать глазами нужно
	 * одинаково свёрстанные строки.
	 *
	 * Для остальных шаблонов сырой ответ уже человекочитаем — метод возвращает
	 * null, вызывающий код оставляет исходную строку без изменений.
	 */
	public function formatStudentAnswer( int $taskId, string $rawAnswerJson ): ?string {
		if ( '' === $rawAnswerJson ) {
			return null;
		}

		$tplRaw = $this->posts->getMeta( $taskId, PostMetaName::TemplateType->value );
		$tpl    = TaskTemplate::fromDatabase( is_string( $tplRaw ) ? $tplRaw : null );

		$decoded = json_decode( $rawAnswerJson, true );
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return null;
		}

		$formatted = match ( $tpl ) {
			TaskTemplate::Choice   => $this->studentChoice( $taskId, $decoded ),
			TaskTemplate::Matching => $this->studentMatching( $decoded ),
			TaskTemplate::Ordering => $this->studentOrdering( $decoded ),
			TaskTemplate::Fill     => $this->studentFill( $decoded ),
			TaskTemplate::Triple   => $this->studentTriple( $decoded ),
			default                => '',
		};

		return '' === $formatted ? null : $formatted;
	}

	/**
	 * @param array<int|string, mixed> $ids Выбранные id опций
	 */
	private function studentChoice( int $taskId, array $ids ): string {
		$metaRaw = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
		$options = is_array( $metaRaw ) ? ( $metaRaw['task_options']['options'] ?? array() ) : array();
		if ( ! is_array( $options ) ) {
			return '';
		}

		$textById = array();
		foreach ( $options as $opt ) {
			if ( is_array( $opt ) && '' !== (string) ( $opt['id'] ?? '' ) ) {
				$textById[ (string) $opt['id'] ] = trim( (string) ( $opt['text'] ?? '' ) );
			}
		}

		$texts = array();
		foreach ( $ids as $id ) {
			if ( is_array( $id ) ) {
				continue;
			}
			$text = $textById[ (string) $id ] ?? '';
			if ( '' !== $text ) {
				$texts[] = $text;
			}
		}

		return implode( ', ', $texts );
	}

	/**
	 * Незаполненная строка сопоставления показывается явно («— не выбрано»):
	 * пропуск молча съел бы информацию о том, что ученик её не разобрал.
	 *
	 * @param array<int|string, mixed> $pairs Пары ответа ученика
	 */
	private function studentMatching( array $pairs ): string {
		$out = array();
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			$left  = trim( (string) ( $pair['left'] ?? '' ) );
			$right = trim( (string) ( $pair['right'] ?? '' ) );
			if ( '' === $left && '' === $right ) {
				continue;
			}
			$out[] = $left . ' → ' . ( '' !== $right ? $right : '— не выбрано' );
		}

		return implode( '; ', $out );
	}

	/**
	 * @param array<int|string, mixed> $items Порядок, выставленный учеником
	 */
	private function studentOrdering( array $items ): string {
		$out = array();
		$i   = 0;
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				continue;
			}
			$text = trim( (string) $item );
			if ( '' !== $text ) {
				$out[] = ( ++$i ) . '. ' . $text;
			}
		}

		return implode( '   ', $out );
	}

	/**
	 * @param array<int|string, mixed> $gaps Пропуск => ответ ученика
	 */
	private function studentFill( array $gaps ): string {
		$out = array();
		foreach ( $gaps as $index => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			$text  = trim( (string) $value );
			$out[] = '[' . $index . '] ' . ( '' !== $text ? $text : '—' );
		}

		return implode( '   ', $out );
	}

	/**
	 * @param array<int|string, mixed> $answers Номер задания => ответ ученика
	 */
	private function studentTriple( array $answers ): string {
		$out = array();
		foreach ( array( '19', '20', '21' ) as $n ) {
			$value = $answers[ $n ] ?? '';
			if ( is_array( $value ) ) {
				continue;
			}
			$text = trim( (string) $value );
			if ( '' !== $text ) {
				$out[] = "{$n}: {$text}";
			}
		}

		return implode( ' | ', $out );
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
