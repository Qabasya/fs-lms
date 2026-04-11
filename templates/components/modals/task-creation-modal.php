<?php
/**
 * Модальное окно создания нового задания.
 *
 * Подключается в админ-футере на страницах списка заданий.
 * Позволяет выбрать номер задания (термин таксономии) и типовое условие (boilerplate),
 * после чего создаётся черновик задания с автоматической генерацией номера.
 *
 * @var string $subject_key Ключ текущего предмета (например, "math")
 */
?>

<div id="fs-task-modal" class="fs-modal-overlay" style="display: none;">
    <div class="fs-modal-content">
        <header class="fs-modal-header">
            <h2>Создание нового задания</h2>
            <button type="button" class="fs-modal-close fs-close" title="Закрыть">&times;</button>
        </header>

        <form id="fs-task-creation-form">
            <!-- Nonce для защиты от CSRF -->
            <?php wp_nonce_field('fs_task_creation_nonce', 'nonce'); ?>

            <!-- Скрытое поле с ключом предмета -->
            <input type="hidden" name="subject_key" id="fs-modal-subject" value="<?php echo esc_attr($subject_key ?? ''); ?>">

            <!-- Поле выбора номера задания (термин таксономии) -->
            <div class="fs-form-group">
                <label for="fs-modal-term">Номер задания:</label>
                <select id="fs-modal-term" name="term_id" class="regular-text" required disabled>
                    <option value="">Загрузка типов...</option>
                </select>
            </div>

            <!-- Поле выбора типового условия (boilerplate) -->
            <div class="fs-form-group">
                <label for="fs-modal-boilerplate">Типовое условие (Шаблон):</label>
                <select id="fs-modal-boilerplate" name="boilerplate_uid" class="regular-text" disabled>
                    <option value="">-- Сначала выберите номер --</option>
                </select>
                <p class="description">Выберите номер, чтобы подгрузить доступные условия.</p>
            </div>

            <!-- Поле названия задания -->
            <div class="fs-form-group">
                <label for="fs-modal-title">Название задания:</label>
                <input type="text" name="task_title" id="fs-modal-title"
                       placeholder="Например: Вариант СтатГрад №1" required>
            </div>

            <footer class="fs-modal-footer">
                <button type="button" class="button fs-modal-cancel">Отмена</button>
                <button type="submit" class="button button-primary" id="fs-modal-submit">
                    Продолжить
                </button>
            </footer>
        </form>
    </div>
</div>

<style>
    /* ========== СТИЛИ МОДАЛЬНОГО ОКНА ========== */
    /* Максимально простые и надёжные стили для админки */

    .fs-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.6);
        z-index: 99999;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .fs-modal-content {
        background: #fff;
        width: 450px;
        border-radius: 4px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    }

    .fs-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 20px;
        border-bottom: 1px solid #ddd;
    }

    .fs-modal-header h2 {
        margin: 0;
        font-size: 18px;
    }

    .fs-modal-close {
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: #666;
    }

    .fs-modal-close:hover {
        color: #d63638;
    }

    #fs-task-creation-form {
        padding: 20px;
    }

    .fs-form-group {
        margin-bottom: 15px;
    }

    .fs-form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
    }

    .fs-form-group input,
    .fs-form-group select {
        width: 100%;
    }

    .fs-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 20px;
    }
</style>
