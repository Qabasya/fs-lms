<?php
/**
 * Модальное окно создания / редактирования таксономии предмета.
 *
 * Используется на странице управления предметом (SubjectTest.php).
 * Управляется через JS: taxonomy-modal.js (компонент) + taxonomies.js (сервис).
 *
 * @var \Inc\DTO\SubjectViewDTO $dto
 */
?>
<div id="fs-taxonomy-modal" class="fs-lms-modal" style="display:none;">
    <div class="fs-lms-modal-content">
        <h3 id="modal-title">Добавить новую таксономию</h3>
        <input type="hidden" id="tax-subject-key" value="<?php echo esc_attr( $dto->subject_key ); ?>">
        <input type="hidden" id="tax-action" value="store">

        <p>
            <label>Название:</label><br>
            <input type="text" id="tax-name" style="width:100%">
        </p>

        <p id="slug-container">
            <label>Ярлык:</label><br>
            <input type="text" id="tax-slug" style="width:100%" >
        </p>

        <p id="slug-container">
            <label>Тип отображения:</label><br>
            <div class="fs-radio-group">
                <label><input type="radio" name="tax_display_type" value="select" checked> Выпадающий список (Select)</label><br>
                <label><input type="radio" name="tax_display_type" value="radio"> Один выбор (Radio)</label><br>
                <label><input type="radio" name="tax_display_type" value="checkbox"> Множественный выбор (Checkbox)</label>
            </div>
        </p>

        <div style="text-align:right; margin-top:20px;">
            <button class="button js-modal-close">Отмена</button>
            <button class="button button-primary js-modal-save">Сохранить</button>
        </div>
    </div>
</div>