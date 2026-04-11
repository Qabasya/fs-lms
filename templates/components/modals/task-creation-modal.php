<?php
/**
 * Модальное окно создания нового задания.
 * Подключается в админ-футере.
 * * @var WP_Term[] $terms Массив терминов таксономии "Номера заданий" (например, fs_task_number)
 * @var string $subject_key Текущий предмет (если он известен глобально, иначе можно будет выбирать)
 */
?>

<div id="fs-task-modal" class="fs-modal-overlay" style="display: none;">
	<div class="fs-modal-content">
		<header class="fs-modal-header">
			<h2>Создание нового задания</h2>
			<button type="button" class="fs-modal-close" title="Закрыть">&times;</button>
		</header>

		<form id="fs-task-creation-form">
			<?php wp_nonce_field('create_task_nonce', 'fs_create_task_nonce'); ?>

			<input type="hidden" name="subject_key" id="fs-modal-subject" value="<?php echo esc_attr($subject_key ?? 'inf'); ?>">

			<div class="fs-form-group">
				<label for="fs-modal-term">Номер задания:</label>
				<select name="term_slug" id="fs-modal-term" required>
					<option value="">-- Выберите номер --</option>
					<?php foreach ($terms as $term): ?>
						<option value="<?php echo esc_attr($term->slug); ?>">
							<?php echo esc_html($term->name); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="fs-form-group">
				<label for="fs-modal-boilerplate">Типовое условие (Шаблон):</label>
				<div style="display: flex; gap: 10px; align-items: center;">
					<select name="boilerplate_uid" id="fs-modal-boilerplate" disabled style="flex-grow: 1;">
						<option value="">-- Без шаблона (пустое условие) --</option>
					</select>
					<span class="spinner" id="fs-modal-spinner"></span>
				</div>
				<p class="description">Выберите номер задания, чтобы подгрузить доступные шаблоны.</p>
			</div>

			<div class="fs-form-group">
				<label for="fs-modal-title">Название задания:</label>
				<input type="text" name="task_title" id="fs-modal-title" placeholder="Например: Вариант СтатГрад №1" required>
			</div>

			<footer class="fs-modal-footer">
				<button type="button" class="button fs-modal-cancel">Отмена</button>
				<button type="submit" class="button button-primary" id="fs-modal-submit">Продолжить</button>
			</footer>
		</form>
	</div>
</div>

<style>
    /* Максимально простые и надежные стили для админки */
    .fs-modal-overlay {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0, 0, 0, 0.6); z-index: 99999;
        display: flex; align-items: center; justify-content: center;
    }
    .fs-modal-content {
        background: #fff; width: 450px; border-radius: 4px; box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    }
    .fs-modal-header {
        display: flex; justify-content: space-between; align-items: center;
        padding: 15px 20px; border-bottom: 1px solid #ddd;
    }
    .fs-modal-header h2 { margin: 0; font-size: 18px; }
    .fs-modal-close {
        background: none; border: none; font-size: 24px; cursor: pointer; color: #666; padding: 0;
    }
    .fs-modal-close:hover { color: #d63638; }
    #fs-task-creation-form { padding: 20px; }
    .fs-form-group { margin-bottom: 15px; }
    .fs-form-group label { display: block; margin-bottom: 5px; font-weight: 600; }
    .fs-form-group input, .fs-form-group select { width: 100%; max-width: 100%; }
    .fs-modal-footer {
        display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;
    }
</style>