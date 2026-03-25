<?php

    namespace Inc\Core;

    use Inc\Api\SubjectRepository;
    use Inc\Core\Service;

    class CPTManager implements Service
    {
        protected SubjectRepository $subjects;

        public function __construct(SubjectRepository $subjects) {
            $this->subjects = $subjects;
        }
        public function register(): void {
            $list = $this->subjects->get_all();

            foreach ($list as $key => $data) {
                $name = $data['name'];

                // 1. Регистрируем тип для ЗАДАНИЙ
                $this->register_type($key . '_tasks', "Задания ($name)", "Задание");

                // 2. Регистрируем тип для СТАТЕЙ
                $this->register_type($key . '_articles', "Статьи ($name)", "Статья");
            }
        }

        private function register_type(string $post_type, string $plural, string $singular): void {
            register_post_type($post_type, [
                'labels' => [
                    'name'               => $plural,
                    'singular_name'      => $singular,
                    'add_new'            => 'Добавить',
                    'add_new_item'       => "Добавить $singular",
                    'edit_item'          => "Редактировать $singular",
                    'all_items'          => "Все $plural",
                ],
                'public'      => true,
                'has_archive' => true,
                'show_in_menu'=> 'fs_lms',
                'supports'    => ['title', 'editor', 'thumbnail', 'excerpt'],
                'show_in_rest'=> true,
            ]);
        }
    }