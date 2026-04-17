<div id="fs-taxonomy-modal" class="fs-lms-modal">
    <div class="fs-lms-modal-content">
        <span class="fs-close js-modal-close">&times;</span>

        <h2 id="modal-title">Новая таксономия</h2>

        <input type="hidden" id="tax-subject-key" value="<?php echo esc_attr( $dto->subject_key ); ?>">
        <input type="hidden" id="tax-action" value="store">
        <input type="hidden" id="tax-original-slug" value="">

        <div class="fs-form-group">
            <label>Название:</label>
            <input type="text" id="tax-name" placeholder="Введите название...">
        </div>

        <div class="fs-form-group" id="slug-container">
            <label>Ярлык:</label>
            <div style="display: flex; align-items: center;">
                <span id="tax-slug-prefix" style="background: #f0f0f1; padding: 0 10px; border: 1px solid #8c8f94; border-right: none; border-radius: 4px 0 0 4px; line-height: 33px; font-family: monospace;">
                    <?php echo esc_html( $dto->subject_key ); ?>_
                </span>
                <input type="text" id="tax-slug" style="border-radius: 0 4px 4px 0;">
            </div>
        </div>

        <div class="fs-form-group">
            <label>Тип отображения:</label>
            <div class="fs-radio-group">
                <label><input type="radio" name="tax_display_type" value="select" checked> Выпадающий список</label>
                <label><input type="radio" name="tax_display_type" value="radio"> Один выбор</label>
                <label><input type="radio" name="tax_display_type" value="checkbox"> Множественный выбор</label>
            </div>
        </div>

        <div class="fs-modal-footer">
            <button class="button js-modal-close">Отмена</button>
            <button class="button button-primary js-modal-save">Сохранить</button>
        </div>
    </div>
</div>
