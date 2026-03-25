<?php

    namespace Inc\Core\Callbacks;

    use Inc\Core\BaseController;
    use Inc\Shared\Traits\TemplateRenderer;
    use Inc\Api\SubjectRepository;

    class AdminCallbacks extends BaseController
    {
        use TemplateRenderer;

        protected SubjectRepository $subjects;



        public function adminDashboard(): void {
            $this->render('admin');
        }

        public function adminImport(): void {
            $this->render('import');
        }

        public function checkboxField(array $args): void {
            $name = $args['label_for'];
            $option_name = $args['option_name'];
            $options = get_option($option_name);

            // Проверяем значение
            $checked = isset($options[$name]) ? ($options[$name] ? true : false) : false;

            echo '<input type="checkbox" id="' . $name . '" name="' . $option_name . '[' . $name . ']" value="1" ' . ($checked ? 'checked' : '') . '>';
        }
    }