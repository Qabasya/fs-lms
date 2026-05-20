<div id="fs-academic-period-modal" class="fs-lms-modal hidden">
    <div class="fs-lms-modal-backdrop"></div>

    <div class="fs-lms-modal-content fs-modal-md">
        <div class="fs-lms-modal-header">
            <h2 class="fs-lms-modal-title" id="period-modal-title">Создать учебный период</h2>
            <button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="Закрыть">&times;</button>
        </div>

        <div class="fs-lms-modal-body">
            <form id="fs-academic-period-form" autocomplete="off">

                <div class="fs-form-group" id="period-id-group">
                    <label for="period_id">Технический ключ (например: 2026_autumn)</label>
                    <input type="text" id="period_id" name="key" placeholder="Только латиница и подчеркивания..." required
                           pattern="[a-z0-9_]+">
                    <p class="description">Используется для названия типов записей в базе.</p>
                </div>

                <div class="fs-form-group">
                    <label for="period_name">Название периода (например: Осенний триместр 2026)</label>
                    <input type="text" id="period_name" placeholder="Введите название периода..." required>
                </div>

                <div class="fs-form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
                    <div class="fs-form-group" style="flex: 1; margin-bottom: 0;">
                        <label for="period_start_date">Дата начала</label>
                        <input type="date" id="period_start_date" required>
                    </div>

                    <div class="fs-form-group" style="flex: 1; margin-bottom: 0;">
                        <label for="period_end_date">Дата окончания</label>
                        <input type="date" id="period_end_date" required>
                    </div>
                </div>

                <div class="fs-form-group">
                    <label class="fs-checkbox-label">
                        <input type="checkbox" id="period_is_current" value="1">
                        <span>Сделать этот период активным по умолчанию</span>
                    </label>
                </div>

                <input type="hidden" id="period_action_type" value="add">

                <div class="fs-lms-modal-footer">
                    <button type="button" class="button fs-lms-modal-cancel">Отмена</button>
                    <button type="submit" class="button button-primary" id="period-submit-btn">Создать период</button>
                </div>
            </form>
        </div>
    </div>
</div>
