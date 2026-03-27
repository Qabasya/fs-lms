<div id="fs-subject-modal" class="fs-modal" style="display:none;">
    <div class="fs-modal-content">
        <span class="fs-close">&times;</span>
        <h2>Создать новый предмет</h2>

        <form id="fs-add-subject-form">
            <div class="fs-form-group">
                <label for="subj_name">Название (например: Информатика ЕГЭ)</label>
                <input class="regular-text" type="text" id="subj_name" name="name" placeholder="Введите название..." required>
            </div>

            <div class="fs-form-group">
                <label for="subj_key">Технический ключ (например: inf_ege)</label>
                <input class="regular-text" type="text" id="subj_key" name="key" placeholder="Только латиница и подчеркивания..." required
                       pattern="[a-z0-9_]+">
                <p class="description">Используется для названия типов записей в базе.</p>
            </div>

            <div class="fs-form-group">
                <label for="subj_tasks_count">Количество типов заданий
                    (от <?php echo Inc\Core\BaseController::MIN_TASKS_COUNT; ?>
                    до <?php echo Inc\Core\BaseController::MAX_TASKS_COUNT; ?>)</label>
                <input class="regular-text" type="number" id="subj_tasks_count" name="tasks_count"
                       min="<?php echo Inc\Core\BaseController::MIN_TASKS_COUNT; ?>"
                       max="<?php echo Inc\Core\BaseController::MAX_TASKS_COUNT; ?>"
                       value="1" required>
                <p class="description">Сколько уникальных номеров заданий будет в курсе.</p>
            </div>

			<?php wp_nonce_field( 'fs_subject_nonce', 'security' ); ?>
            <button type="submit" class="button button-primary">Создать предмет и CPT</button>
        </form>
    </div>
</div>