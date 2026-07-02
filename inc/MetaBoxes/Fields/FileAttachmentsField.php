<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class FileAttachmentsField
 *
 * Поле «материалы задания» (Эпик 13, D16): несколько файлов из медиабиблиотеки
 * WordPress — исходные данные к номеру ЕГЭ/ОГЭ (картинки для презентации,
 * файлы с данными и т.п.). Отдаются УЧЕНИКУ ссылками на скачивание.
 *
 * Хранит: { attachment_ids: int[] }
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

		$base = esc_attr( $this->get_field_name( $id ) );
		?>
		<div class="fs-lms-field-group fs-task-materials-field" data-field="task-materials" data-base="<?php echo $base; ?>">
			<label class="fs-lms-label">
				<?php echo esc_html( $label ); ?>
				<?php echo self::tooltip( 'Файлы видны ученику как ссылки на скачивание (данные к заданию, исходники).' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</label>

			<ul class="fs-task-materials__list">
				<?php foreach ( $ids as $attachmentId ) : ?>
					<li class="fs-task-materials__item">
						<input type="hidden"
							name="<?php echo $base; ?>[attachment_ids][]"
							value="<?php echo (int) $attachmentId; ?>">
						<a href="<?php echo esc_url( (string) wp_get_attachment_url( $attachmentId ) ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( get_the_title( $attachmentId ) ?: "Файл #{$attachmentId}" ); ?>
						</a>
						<button type="button" class="button-link js-materials-remove">✕</button>
					</li>
				<?php endforeach; ?>
			</ul>

			<button type="button" class="button js-materials-add">+ Добавить файлы</button>
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
