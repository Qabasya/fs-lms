<?php

    namespace Inc\Pages\Builders;

    use Inc\Pages\Contracts\MenuBuilderInterface;
    use Inc\Api\SubjectRepository;
    use Inc\Core\Callbacks\AdminCallbacks;
    use Inc\Core\PluginConstants;



    /**
     * Строит меню для раздела "Предметы".
     * Динамически создаёт подменю на основе данных из репозитория.
     */
    class SubjectsMenuBuilder implements MenuBuilderInterface
    {
        private SubjectRepository $subjectRepository;
        private AdminCallbacks $callbacks;

        /**
         * @param SubjectRepository $subjectRepository
         */
        public function __construct(SubjectRepository $subjectRepository, AdminCallbacks $callbacks) {
            $this->subjectRepository = $subjectRepository;
            $this->callbacks = $callbacks;
        }


        /**
         * @inheritDoc
         */
        public function buildPages(): array {
            $subjects = $this->subjectRepository->get_all();

            if (empty($subjects)) {
                return [];
            }

            return [
                [
                    'page_title' => 'Предметы',
                    'menu_title' => 'Предметы',
                    'capability' => PluginConstants::ADMIN_CAPABILITY,
                    'menu_slug' => PluginConstants::SUBJECTS_MENU_SLUG,
                    'callback' => [$this->callbacks, 'subjectsRoot'],
                    'icon_url' => 'dashicons-category',
                    'position' => 5
                ]
            ];
        }

        /**
         * @inheritDoc
         */
        public function buildSubPages(): array {
            $subjects = $this->subjectRepository->get_all();
            $subpages = [];

            foreach ($subjects as $key => $subject) {
                $subpages[] = [
                    'parent_slug' => PluginConstants::SUBJECTS_MENU_SLUG,
                    'page_title' => $subject['name'],
                    'menu_title' => $subject['name'],
                    'capability' => PluginConstants::ADMIN_CAPABILITY,
                    'menu_slug' => 'fs_subject_' . $key,
                    'callback' => [$this->callbacks, 'subjectPage'],
                ];
            }

            return $subpages;
        }

    }