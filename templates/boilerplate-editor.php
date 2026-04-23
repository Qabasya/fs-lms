<?php
/**
 * @var string                               $subject        Ключ предмета
 * @var string                               $term           Слаг типа задания
 * @var string                               $template_id    ID визуального шаблона
 * @var bool                                 $is_edit        Режим редактирования
 * @var string                               $page_title     Заголовок страницы
 * @var string                               $bp_uid         UID boilerplate
 * @var string                               $bp_title       Название boilerplate
 * @var array<string, string>                $content_fields Декодированные поля контента
 * @var array<string, array{label: string}>  $fields         Поля условий шаблона
 */

use Inc\Enums\Nonce;
?>

<div class="wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html( $page_title ); ?></h1>
	<hr class="wp-header-end">

	<form id="fs-lms-boilerplate-form" method="post" class="standard-form">
		<input type="hidden" name="action" value="save_boilerplate">
		<input type="hidden" name="subject_key" value="<?php echo esc_attr( $subject ); ?>">
		<input type="hidden" name="term_slug" value="<?php echo esc_attr( $term ); ?>">
		<input type="hidden" name="uid" value="<?php echo esc_attr( $bp_uid ); ?>">
		<?php wp_nonce_field( Nonce::SaveBoilerplate->value, 'security' ); ?>

		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">

				<div id="post-body-content">
					<div class="boilerplate-title-section" style="margin-bottom: 20px;">
						<label for="bp_title"><strong>Название условия (для списка выбора):</strong></label>
						<input type="text" name="title" id="bp_title" class="widefat"
								value="<?php echo esc_attr( $bp_title ); ?>"
								placeholder="Например: одна куча" required>
					</div>

					<div class="boilerplate-editors-section">
						<?php if ( empty( $fields ) ) : ?>
							<div class="notice notice-warning inline">
								<p>Поля для шаблона "<?php echo esc_html( $template_id ); ?>" не определены.</p>
							</div>
						<?php else : ?>
							<?php foreach ( $fields as $id => $config ) : ?>
								<div class="boilerplate-field-group" style="margin-bottom: 30px;">
									<label><strong><?php echo esc_html( $config['label'] ); ?>:</strong></label>
									<?php
									$content   = $content_fields[ $id ] ?? '';
									$editor_id = 'editor_' . str_replace( '-', '_', $id );

									wp_editor(
										$content,
										$editor_id,
										array(
											'textarea_name' => "content[$id]",
											'textarea_rows' => 12,
											'media_buttons' => true,
											'tinymce' => array(
												'setup' => 'function(ed) { ed.on("change", function() { ed.save(); }); }',
											),
										)
									);
									?>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>

				<div id="postbox-container-1" class="postbox-container">
					<div class="postbox">
						<h2 class="handle"><span>Сохранение</span></h2>
						<div class="inside">
							<div class="submitbox" id="submitpost">

								<div id="minor-publishing">
									<div id="misc-publishing-actions">

										<div class="misc-pub-section">
											<span class="dashicons dashicons-layout" style="color: #8c8f94; vertical-align: text-bottom;"></span>
											<strong>Шаблон:</strong>
											<code><?php echo esc_html( $template_id ); ?></code>
										</div>

									</div>
									<div class="clear"></div>
								</div>

								<div id="major-publishing-actions">

									<div id="delete-action">
										<a href="<?php echo esc_url( admin_url( "admin.php?page=fs_boilerplate_manager&subject=$subject&term=$term" ) ); ?>" class="submitdelete deletion">
											&larr; Назад
										</a>
									</div>

									<div id="publishing-action">
										<span class="spinner"></span> <input type="submit" name="save" id="publish" class="button button-primary button-large" value="Сохранить шаблон">
									</div>

									<div class="clear"></div>
								</div>
							</div>
						</div>
					</div>
				</div>

			</div>
		</div>
	</form>
</div>
