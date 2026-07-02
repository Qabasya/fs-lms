<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Модалка добавления/редактирования кабинета (Эпик 9). Близнец модалки периода.
 *
 * @var array $active_subjects Активные предметы (key => SubjectDTO) для чекбоксов
 */

$active_subjects = $active_subjects ?? array();
?>
<div id="fs-room-modal" class="fs-lms-modal hidden">
    <div class="fs-lms-modal-backdrop"></div>

    <div class="fs-lms-modal-content fs-modal-md">
        <div class="fs-lms-modal-header">
            <h2 class="fs-lms-modal-title" id="room-modal-title">Создать кабинет</h2>
            <button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="Закрыть">&times;</button>
        </div>

        <div class="fs-lms-modal-body">
            <form id="fs-room-form" autocomplete="off">

                <div class="fs-form-group">
                    <label for="room_name">Название кабинета (например: Каб. 305)</label>
                    <input type="text" id="room_name" placeholder="Введите название кабинета..." required>
                </div>

                <input type="hidden" id="room_id" value="">

                <div class="fs-form-group">
                    <label>Допустимые предметы</label>
                    <div class="fs-room-subjects">
                        <?php foreach ( $active_subjects as $subject_key => $subject ) : ?>
                            <label class="fs-checkbox-label">
                                <input type="checkbox" class="room-subject-cb" value="<?php echo esc_attr( (string) $subject_key ); ?>">
                                <span><?php echo esc_html( $subject->name ?? (string) $subject_key ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description">Ничего не выбрано = кабинет подходит для любого предмета.</p>
                </div>

                <div class="fs-lms-modal-footer">
                    <button type="button" class="button fs-lms-modal-cancel">Отмена</button>
                    <button type="submit" class="button button-primary" id="room-submit-btn">Создать кабинет</button>
                </div>
            </form>
        </div>
    </div>
</div>
