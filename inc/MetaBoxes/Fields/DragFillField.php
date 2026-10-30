<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

use Inc\Services\Task\DragFillParser;
use Inc\Shared\TileText;

/**
 * Class DragFillField
 *
 * Поле задания «Перетаскивание пропусков»: формула со слотами `{N}`, плитки банка и по слоту — верная плитка
 * и группа (слоты одной группы взаимозаменяемы).
 *
 * В формуле, плитках и ответах допустимы только `<sub>`/`<sup>` ({@see TileText}).
 * Хранит: { formula: 'V = {1} * {2}', tiles: ['h','w','k'], slots: [ {answer: 'h', group: 1}, … ] }
 * POST-структура: fs_lms_meta[task_drag_fill][formula|tiles|slots][i][answer|group]
 *
 * @package Inc\MetaBoxes\Fields
 */
class DragFillField extends BaseField {

	/** Сколько строк слотов показывать в пустой форме. */
	private const MIN_ROWS = 3;

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$data    = is_array( $value ) ? $value : array();
		$formula = (string) ( $data['formula'] ?? '' );
		$tiles   = is_array( $data['tiles'] ?? null ) ? $data['tiles'] : array();
		$slots   = is_array( $data['slots'] ?? null ) ? array_values( $data['slots'] ) : array();
		$rows    = max( self::MIN_ROWS, count( $slots ), DragFillParser::slotCount( $formula ) );
		$base    = esc_attr( $this->get_field_name( $id ) );
		?>
		<div class="fs-lms-field-group fs-task-drag-fill-field" data-field="task-drag-fill">
			<label class="fs-lms-label"><?php echo esc_html( $label ); ?></label>
			<p class="description">
				Формула: на месте пропусков пишите <code>{1}</code>, <code>{2}</code>… Например <code>V = {1} * {2} * {3}</code>.
			</p>
			<input type="text" name="<?php echo $base; ?>[formula]" value="<?php echo esc_attr( $formula ); ?>" class="large-text code js-drag-fill-formula">

			<p class="description">Плитки (по одной в строке), включая лишние:</p>
			<textarea name="<?php echo $base; ?>[tiles]" rows="4" class="large-text code js-drag-fill-tiles"><?php echo esc_textarea( implode( "\n", array_map( 'strval', $tiles ) ) ); ?></textarea>

			<p class="description">
				Верная плитка для каждого пропуска. Пропуски с одинаковым номером группы взаимозаменяемы (порядок неважен).
			</p>
			<div class="fs-task-drag-fill__rows js-drag-fill-rows">
				<?php for ( $i = 0; $i < $rows; $i++ ) : ?>
					<?php $this->renderRow( $base, $i, (string) ( $slots[ $i ]['answer'] ?? '' ), (string) ( $slots[ $i ]['group'] ?? '' ) ); ?>
				<?php endfor; ?>
			</div>
			<button type="button" class="button js-drag-fill-any-order">Порядок неважен</button>

			<script type="text/template" class="js-drag-fill-row-tpl">
				<?php $this->renderRow( $base, '__IDX__', '', '' ); ?>
			</script>
		</div>
		<?php
	}

	/** Строка слота: номер, верная плитка, группа. `$i` — индекс с нуля (или `__IDX__` в шаблоне для JS). */
	private function renderRow( string $base, int|string $i, string $answer, string $group ): void {
		?>
		<div class="fs-task-drag-fill__row">
			<span class="js-drag-fill-num">{<?php echo is_int( $i ) ? (int) ( $i + 1 ) : esc_html( $i ); ?>}</span>
			<input type="text" name="<?php echo $base; ?>[slots][<?php echo esc_attr( (string) $i ); ?>][answer]" value="<?php echo esc_attr( $answer ); ?>" placeholder="Верная плитка">
			<input type="number" min="1" name="<?php echo $base; ?>[slots][<?php echo esc_attr( (string) $i ); ?>][group]" value="<?php echo esc_attr( $group ); ?>" placeholder="Группа">
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return array( 'formula' => '', 'tiles' => array(), 'slots' => array() );
		}

		$formula = TileText::clean( wp_unslash( (string) ( $value['formula'] ?? '' ) ) );
		$count   = DragFillParser::slotCount( $formula );
		$raw     = is_array( $value['slots'] ?? null ) ? array_values( $value['slots'] ) : array();
		$slots   = $this->sanitizeSlots( $raw, $count );

		return array(
			'formula' => $formula,
			'tiles'   => $this->coverAnswers( $this->sanitizeTiles( $value['tiles'] ?? array() ), $slots ),
			'slots'   => $slots,
		);
	}

	public function editorType(): string {
		return 'drag_fill';
	}

	/**
	 * Слоты по числу `{N}` формулы: верная плитка и целая группа ≥ 1; слот без группы получает свою.
	 *
	 * @return array<int, array{answer: string, group: int}>
	 */
	private function sanitizeSlots( array $raw, int $count ): array {
		$groups = array();
		$slots  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$slot   = is_array( $raw[ $i ] ?? null ) ? $raw[ $i ] : array();
			$group  = (int) ( $slot['group'] ?? 0 );
			$slots[] = array(
				'answer' => TileText::clean( wp_unslash( (string) ( $slot['answer'] ?? '' ) ) ),
				'group'  => $group,
			);
			if ( $group >= 1 ) {
				$groups[] = $group;
			}
		}

		$next = array() === $groups ? 1 : max( $groups ) + 1;
		foreach ( $slots as &$slot ) {
			if ( $slot['group'] < 1 ) {
				$slot['group'] = $next++;
			}
		}
		unset( $slot );

		return $slots;
	}

	/**
	 * Плитки: из textarea (по строке) или массива; пустые отбрасываются.
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

		$tiles = array_map( static fn( $tile ): string => TileText::clean( wp_unslash( (string) $tile ) ), $raw );

		return array_values( array_filter( $tiles, static fn( string $tile ): bool => '' !== $tile ) );
	}

	/**
	 * Добавляет в банк недостающие копии верных плиток: ответ без плитки решить нельзя, а повтор (`h*h*i`) требует две.
	 *
	 * @param string[]                                      $tiles
	 * @param array<int, array{answer: string, group: int}> $slots
	 *
	 * @return string[]
	 */
	private function coverAnswers( array $tiles, array $slots ): array {
		$have = array_count_values( $tiles );

		foreach ( $slots as $slot ) {
			if ( '' === $slot['answer'] ) {
				continue;
			}
			if ( ( $have[ $slot['answer'] ] ?? 0 ) > 0 ) {
				--$have[ $slot['answer'] ];
				continue;
			}
			$tiles[] = $slot['answer'];
		}

		return $tiles;
	}
}
