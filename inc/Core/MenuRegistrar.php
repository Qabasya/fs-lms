<?php

    namespace Inc\Core;
    use Inc\Core\Dto\MenuPageDTO;

    /**
     * Фасад для регистрации страниц админ-меню.
     */
    class MenuRegistrar
    {
        private MenuManager $manager;
        private array $pages = [];
        private array $subpages = [];

        public function __construct(MenuManager $manager)
        {
            $this->manager = $manager;
        }

        public function addPages(array $pages): self
        {
            $this->pages = array_merge($this->pages, $pages);
            return $this;
        }

        public function addSubPages(array $subpages): self
        {
            $this->subpages = array_merge($this->subpages, $subpages);
            return $this;
        }

        public function register(): void
        {
            if (empty($this->pages)) {
                return;
            }

            $this->manager->register($this->pages, $this->subpages);
        }
    }