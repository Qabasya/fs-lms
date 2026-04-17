<?php /** @var \Inc\DTO\SubjectViewDTO $dto */ ?>

<div class="taxonomy-manager-wrapper">
    <h3>Дополнительные классификаторы</h3>
    <p class="description">Управление разделами, темами и авторами.</p>

    <table class="widefat fixed striped js-taxonomy-table">
        <thead>
        <tr>
            <th class="column-primary" style="width: 40%;">Название</th>
            <th style="width: 30%;">Ярлык (Slug)</th>
            <th style="width: 30%;">Действия</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($dto->taxonomies as $tax) : ?>
            <?php $is_protected = ($tax->slug === $dto->protected_tax); ?>
            <tr data-slug="<?php echo esc_attr($tax->slug); ?>">
                <td class="column-title has-row-actions">
                    <strong><?php echo esc_html($tax->name); ?></strong>
                    <?php if ($is_protected) : ?>
                        <span class="dashicons dashicons-lock" title="Системная таксономия" style="font-size: 14px; color: #999; vertical-align: middle;"></span>
                    <?php endif; ?>
                </td>
                <td><code><?php echo esc_html($tax->slug); ?></code></td>
                <td>
                    <div class="row-actions visible">
                        <span class="edit"><a href="<?php echo admin_url("edit-tags.php?taxonomy={$tax->slug}"); ?>">Настроить</a></span>
                        <?php if (!$is_protected) : ?>
                            <span class="inline-edit"> | <a href="#" class="js-edit-tax">Изменить</a></span>
                            <span class="trash"> | <a href="#" class="js-delete-tax" style="color: #d63638;">Удалить</a></span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="fs-add-row-container">
        <button type="button" class="fs-add-row-button js-add-taxonomy" title="Добавить новую таксономию">
            <span class="dashicons dashicons-plus-alt2"></span>
        </button>
    </div>
</div>