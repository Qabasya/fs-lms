<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class FileAttachmentsField
 *
 * Поле «материалы задания» (Эпик 13, D16): один файл из медиабиблиотеки
 * WordPress — исходные данные к номеру ЕГЭ/ОГЭ (картинка для презентации,
 * файл с данными и т.п.). Отдаётся УЧЕНИКУ ссылкой на скачивание.
 *
 * Хранит: { attachment_ids: int[] } (массив сохранён для обратной совместимости
 * с существующими данными/чтением на стороне ученика; UI ограничивает выбор одним файлом)
 * POST-структура: fs_lms_meta[task_materials][attachment_ids][]
 *
 * @package Inc\MetaBoxes\Fields
 */
class FileAttachmentsField extends BaseField {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$data = is_array( $value ) ? $value : array();
		$ids  = is_array( $data['attachment_ids'] ?? null )
			? array_values( array_filter( array_map( 'intval', $data['attachment_ids'] ) ) )
			: array();

		$attachmentId = $ids[0] ?? 0;
		$base         = esc_attr( $this->get_field_name( $id ) );
		$url          = $attachmentId ? wp_get_attachment_url( $attachmentId ) : '';
		$title        = $attachmentId ? ( get_the_title( $attachmentId ) ?: "Файл #{$attachmentId}" ) : '';
		?>
		<div class="fs-lms-field-group fs-task-materials-field" data-field="task-materials" data-base="<?php echo $base; ?>">
			<label class="fs-lms-label">
				<?php echo esc_html( $label ); ?>
				<?php echo self::tooltip( 'Файл виден ученику как ссылка на скачивание (данные к заданию, исходник).' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</label>

			<input type="hidden"
				name="<?php echo $base; ?>[attachment_ids][]"
				value="<?php echo (int) $attachmentId; ?>"
				class="js-materials-attachment-id">

			<div class="fs-task-materials__preview" <?php echo $url ? '' : 'style="display:none"'; ?>>
				<a href="<?php echo esc_url( (string) $url ); ?>" target="_blank" rel="noopener noreferrer" class="js-materials-link">
					<?php echo esc_html( (string) $title ); ?>
				</a>
			</div>

			<div class="fs-task-materials__actions">
				<button type="button" class="button js-materials-select">
					<?php echo $attachmentId ? 'Заменить файл' : 'Выбрать файл'; ?>
				</button>
				<?php if ( $attachmentId ) : ?>
					<button type="button" class="button-link js-materials-remove">Удалить</button>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return array( 'attachment_ids' => array() );
		}
		$raw = is_array( $value['attachment_ids'] ?? null ) ? $value['attachment_ids'] : array();

		return array(
			'attachment_ids' => array_values( array_filter( array_map( 'intval', $raw ), static fn( int $id ) => $id > 0 ) ),
		);
	}

	public function editorType(): string {
		return 'materials';
	}
}
