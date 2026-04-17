<div id="fs-subject-modal" class="fs-lms-modal">
    <div class="fs-lms-modal-content">
        <span class="fs-close js-modal-close">&times;</span>

        <h2>Создать новый предмет</h2>

        <form id="fs-add-subject-form" autocomplete="off">
            <div class="fs-form-group">
                <label for="subj_name">Название (например: Информатика ЕГЭ)</label>
                <input type="text" id="subj_name" name="name" placeholder="Введите название..." required>
            </div>

            <div class="fs-form-group">
                <label for="subj_key">Технический ключ (например: inf_ege)</label>
                <input type="text" id="subj_key" name="key" placeholder="Только латиница и подчеркивания..." required
                       pattern="[a-z0-9_]+">
                <p class="description">Используется для названия типов записей в базе.</p>
            </div>

            <div class="fs-form-group">
                <label for="subj_tasks_count">Количество типов заданий (от 1 до 100)</label>
                <input type="number" id="subj_tasks_count" name="tasks_count"
                       min="1" max="100" value="1" required>
                <p class="description">Сколько уникальных номеров заданий будет в курсе.</p>
            </div>

            <?php
            use Inc\Enums\Nonce;
            wp_nonce_field( Nonce::Subject->value, 'security' );
            ?>

            <div class="fs-modal-footer">
                <button type="submit" class="button button-primary">Создать предмет и CPT</button>
            </div>
        </form>
    </div>
</div>
