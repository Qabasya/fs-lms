<div class="wrap">
    <h1>FS LMS Dashboard</h1>
    <?php settings_errors(); ?>

    <h2 class="nav-tab-wrapper">
        <a href="#tab-1" class="nav-tab nav-tab-active">Предметы</a>
        <a href="#tab-2" class="nav-tab">Настройки системы</a>
        <a href="#tab-3" class="nav-tab">О плагине</a>
    </h2>

    <div class="tab-content">
        <div id="tab-1" class="tab-pane active" style="margin-top: 20px;">
            <h3>Список активных курсов</h3>
            <p>Здесь будут отображаться динамические CPT для каждого предмета.</p>

            <button type="button" class="button button-primary" id="open-subject-modal">
                + Добавить новый предмет
            </button>
        </div>

        <div id="tab-2" class="tab-pane" style="display:none; margin-top: 20px;">
            <form method="post" action="options.php">
                <?php
                    settings_fields('fs_tasks_settings_group');
                    do_settings_sections('fs_tasks');
                    submit_button();
                ?>
            </form>
        </div>

        <div id="tab-3" class="tab-pane" style="display:none; margin-top: 20px;">
            <h3>FS Tasks v0.0.1</h3>
            <p>Система автоматической генерации курсов и импорта заданий.</p>
        </div>
    </div>
</div>

<script>
    // Простейший переключатель табов без JQuery
    document.querySelectorAll('.nav-tab').forEach(tab => {
        tab.addEventListener('click', function(e) {
            e.preventDefault();

            // Убираем активные классы
            document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.style.display = 'none');

            // Добавляем активный класс
            this.classList.add('nav-tab-active');
            const target = this.getAttribute('href');
            document.querySelector(target).style.display = 'block';
        });
    });
</script>