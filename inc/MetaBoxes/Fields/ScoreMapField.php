<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class ScoreMapField
 *
 * Поле таблицы перевода первичных баллов во вторичные (ЕГЭ).
 * Хранит JSON-строку {"первичный": вторичный, ...}.
 * Поддерживает плейсхолдер и тултип с описанием формата.
 *
 * @package Inc\MetaBoxes\Fields
 */
class ScoreMapField extends BaseField {

	private const PLACEHOLDER = "0\t0\n1\t6\n2\t11\n3\t17\n…";

	private const TOOLTIP = 'Вставьте два столбца из Excel: первичный балл [Tab] вторичный балл, '
		. 'каждая пара на отдельной строке. Нечисловые строки-заголовки будут проигнорированы.';

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$content = self::toTsv( $value );
		?>
		<div class="fs-lms-field-group">
			<div class="fs-lms-label-row">
				<label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $label ); ?>
				</label>
				<?php echo self::tooltip( self::TOOLTIP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<textarea id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>"
					class="large-text fs-lms-score-map-field"
					rows="4"
					placeholder="<?php echo esc_attr( self::PLACEHOLDER ); ?>"><?php echo esc_textarea( $content ); ?></textarea>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$trimmed = trim( $value );
		if ( '' === $trimmed ) {
			return '';
		}
		$map = self::parseTsv( $trimmed );
		return $map ? (string) wp_json_encode( $map ) : '';
	}

	private static function toTsv( mixed $value ): string {
		$map = [];
		if ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				$map = $decoded;
			}
		} elseif ( is_array( $value ) ) {
			$map = $value;
		}
		if ( empty( $map ) ) {
			return '';
		}
		ksort( $map );
		$lines = [];
		foreach ( $map as $primary => $secondary ) {
			$lines[] = $primary . "\t" . $secondary;
		}
		return implode( "\n", $lines );
	}

	private static function parseTsv( string $text ): array {
		$map  = [];
		$rows = preg_split( '/\r\n|\r|\n/', $text ) ?: [];
		foreach ( $rows as $row ) {
			$row = trim( $row );
			if ( '' === $row ) {
				continue;
			}
			$parts = preg_split( '/\t|;|\s{2,}/', $row, 2 );
			if ( ! $parts || count( $parts ) < 2 ) {
				continue;
			}
			$primary   = trim( $parts[0] );
			$secondary = trim( $parts[1] );
			if ( ! is_numeric( $primary ) || ! is_numeric( $secondary ) ) {
				continue;
			}
			$map[ (int) $primary ] = (int) $secondary;
		}
		ksort( $map );
		return $map;
	}

	public function editorType(): string {
		return 'textarea';
	}
}
