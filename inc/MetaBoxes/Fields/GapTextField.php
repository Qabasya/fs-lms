<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

use Inc\Services\Task\FillTextParser;
use Inc\Shared\SafeHtml;

/**
 * Class GapTextField
 *
 * Поле текста с пропусками для задания «Пропуски в тексте».
 * Синтаксис пропуска: [[ответ]] или [[вар1|вар2]] для синонимов.
 *
 * Хранит: { text: 'Текст с [[пропусками]] внутри', answer_mode: 'input'|'tiles', extra_tiles: string[] }
 * `answer_mode` — как ученик заполняет пропуски: вписывает в поле (`input`, по умолчанию, ключа нет в старых заданиях)
 * или перетаскивает плитки (`tiles`; банк — первые варианты пропусков + `extra_tiles`).
 * POST-структура: fs_lms_meta[task_gap_text][text|answer_mode|extra_tiles][]
 *
 * @package Inc\MetaBoxes\Fields
 */
class GapTextField extends BaseField {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$data = is_array( $value ) ? $value : array();
		$text = (string) ( $data['text'] ?? '' );
		$mode = FillTextParser::MODE_TILES === ( $data['answer_mode'] ?? '' ) ? FillTextParser::MODE_TILES : FillTextParser::MODE_INPUT;
		$extra = is_array( $data['extra_tiles'] ?? null ) ? $data['extra_tiles'] : array();
		$base = esc_attr( $this->get_field_name( $id ) );
		?>
		<div class="fs-lms-field-group fs-task-gap-field">
			<label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<p class="description">
				Заключайте пропускаемые слова в <code>[[двойные скобки]]</code>.
				Синонимы: <code>[[красный|алый|пурпурный]]</code> — подходит любое.
				Проверка без учёта регистра.
			</p>
			<textarea
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo $base; ?>[text]"
				rows="8"
				class="large-text code"
			><?php echo esc_textarea( $text ); ?></textarea>
			<p>
				<label>
					<input type="radio" name="<?php echo $base; ?>[answer_mode]" value="<?php echo esc_attr( FillTextParser::MODE_INPUT ); ?>" <?php checked( $mode, FillTextParser::MODE_INPUT ); ?>>
					Ученик вписывает ответ в поле
				</label>
				<label>
					<input type="radio" name="<?php echo $base; ?>[answer_mode]" value="<?php echo esc_attr( FillTextParser::MODE_TILES ); ?>" <?php checked( $mode, FillTextParser::MODE_TILES ); ?>>
					Ученик перетаскивает плитки
				</label>
			</p>
			<p class="description">Плитки — первые варианты каждого пропуска. Лишние плитки (по одной в строке):</p>
			<textarea
				name="<?php echo $base; ?>[extra_tiles]"
				rows="3"
				class="large-text code"
			><?php echo esc_textarea( implode( "\n", array_map( 'strval', $extra ) ) ); ?></textarea>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return array( 'text' => '', 'answer_mode' => FillTextParser::MODE_INPUT, 'extra_tiles' => array() );
		}

		return array(
			'text'        => SafeHtml::post( wp_unslash( (string) ( $value['text'] ?? '' ) ) ),
			'answer_mode' => FillTextParser::MODE_TILES === ( $value['answer_mode'] ?? '' ) ? FillTextParser::MODE_TILES : FillTextParser::MODE_INPUT,
			'extra_tiles' => $this->sanitizeTiles( $value['extra_tiles'] ?? array() ),
		);
	}

	public function editorType(): string {
		return 'gap_text';
	}

	/**
	 * Лишние плитки: из textarea (по строке) или массива; пустые отбрасываются.
	 *
	 * @return string[]
	 */
	private function sanitizeTiles( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\R/u', $raw ) ?: array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$tiles = array_map( static fn( $tile ): string => sanitize_text_field( wp_unslash( (string) $tile ) ), $raw );

		return array_values( array_filter( $tiles, static fn( string $tile ): bool => '' !== $tile ) );
	}
}
