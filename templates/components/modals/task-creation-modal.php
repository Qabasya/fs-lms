<?php use Inc\Enums\Nonce; ?>

<div id="fs-task-modal" class="fs-lms-modal">
    <div class="fs-lms-modal-content">
        <span class="fs-close js-modal-close">&times;</span>

        <h2>Создание нового задания</h2>

        <form id="fs-task-creation-form">
            <?php wp_nonce_field( Nonce::TaskCreation->value, 'nonce' ); ?>

            <input type="hidden" name="subject_key" id="fs-modal-subject"
                   value="<?php echo esc_attr( $subject_key ?? '' ); ?>">

            <div class="fs-form-group">
                <label for="fs-modal-term">Номер задания:</label>
                <select id="fs-modal-term" name="term_id" required disabled>
                    <option value="">Загрузка типов...</option>
                </select>
            </div>

            <div class="fs-form-group">
                <label for="fs-modal-boilerplate">Типовое условие (Шаблон):</label>
                <select id="fs-modal-boilerplate" name="boilerplate_uid" disabled>
                    <option value="">-- Сначала выберите номер --</option>
                </select>
                <p class="description">Выберите номер, чтобы подгрузить доступные условия.</p>
            </div>

            <div class="fs-form-group">
                <label for="fs-modal-title">Название задания:</label>
                <input type="text" name="task_title" id="fs-modal-title"
                       placeholder="Например: Вариант СтатГрад №1" required>
            </div>

            <div class="fs-modal-footer">
                <button type="button" class="button js-modal-close fs-modal-cancel">Отмена</button>
                <button type="submit" class="button button-primary" id="fs-modal-submit">Продолжить</button>
            </div>
        </form>
    </div>
</div>
