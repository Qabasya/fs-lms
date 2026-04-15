<?php

$active_tab = 'tab-1';

if ( isset( $_GET['tab'] ) ) {
    $active_tab = sanitize_text_field( $_GET['tab'] );
}

?>

<div id="tab-1" class="tab-pane <?php echo $active_tab === 'tab-1' ? 'active' : ''; ?>">

    <h1 class="wp-heading-inline">Активные предметы</h1>
    <a class="page-title-action" id="open-subject-modal">Добавить предмет</a>
    <a class="page-title-action" id="fs-import-trigger">Импортировать предмет</a>
    <input type="file" id="fs-import-file" accept=".json" style="display:none;">

    <?php settings_errors(); ?>

    <?php if ( empty( $subjects ) ) : ?>

        <div class="notice notice-info inline" style="margin:20px 0 0 0;">
            <p>Вы еще не создали ни одного предмета.</p>
        </div>

    <?php else : ?>

        <table class="wp-list-table widefat fixed striped" style="margin:20px 0;">

            <thead>
            <tr>
                <th class="manage-column column-title column-primary">Название предмета</th>
                <th class="manage-column column-title column-primary">ID предмета</th>
            </tr>
            </thead>

            <tbody>

            <?php foreach ( $subjects as $subject ) : ?>

                <tr id="subject-row-<?php echo esc_attr( $subject->key ); ?>">

                    <td>

                        <strong>
                            <a
                                    class="row-title"
                                    href="?page=fs_subject_<?php echo esc_attr( $subject->key ); ?>"
                            >
                                <?php echo esc_html( $subject->name ); ?>
                            </a>
                        </strong>

                        <div class="row-actions">

                                <span class="inline">
                                    <button
                                            type="button"
                                            class="button-link open-quick-edit"
                                            data-key="<?php echo esc_attr( $subject->key ); ?>"
                                            data-name="<?php echo esc_attr( $subject->name ); ?>"
                                    >
                                        Изменить
                                    </button>
                                </span> |

                            <span class="trash">
                                    <button
                                            type="button"
                                            class="button-link delete-subject"
                                            style="color:#a00;"
                                            data-key="<?php echo esc_attr( $subject->key ); ?>"
                                    >
                                        Удалить
                                    </button>
                                </span> |

                            <span class="export">
                                    <a
                                            href="#"
                                            class="js-export-subject"
                                            data-key="<?php echo esc_attr( $subject->key ); ?>"
                                            style="color:#2271b1;"
                                    >
                                        Экспорт
                                    </a>
                                </span>

                        </div>

                    </td>

                    <td>
                        <code><?php echo esc_html( $subject->key ); ?></code>
                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

            <tfoot>
            <tr>
                <th class="manage-column column-title column-primary">Название предмета</th>
                <th class="manage-column column-title column-primary">ID предмета</th>
            </tr>
            </tfoot>

        </table>

    <?php endif; ?>


    <table style="display:none;">
        <tr id="fs-quick-edit-row" class="inline-edit-row" style="display:none;">
            <td colspan="4" class="colspanchange">

                <form id="fs-quick-edit-form">

                    <fieldset class="inline-edit-col-left">

                        <legend class="inline-edit-legend">
                            Быстрое редактирование
                        </legend>

                        <div class="inline-edit-col">

                            <label>
                                <span class="title">Название</span>
                                <span class="input-text-wrap">
                                    <input type="text" name="name" value="">
                                </span>
                            </label>

                            <input type="hidden" name="key" value="">

                            <?php
                            use Inc\Enums\Nonce;

                            wp_nonce_field( Nonce::Subject->value, 'security' );
                            ?>

                        </div>

                    </fieldset>

                    <p class="submit inline-edit-save">

                        <button type="button" class="button cancel alignleft">
                            Отмена
                        </button>

                        <button type="submit" class="button button-primary save alignright">
                            Обновить
                        </button>

                        <span class="spinner"></span>

                        <br class="clear">

                    </p>

                </form>

            </td>
        </tr>
    </table>

</div>