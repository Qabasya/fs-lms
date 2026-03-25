<?php

    namespace Inc\Core\Callbacks;

    use Inc\Core\BaseController;
    use Inc\Shared\Traits\TemplateRenderer;

    class AdminCallbacks extends BaseController {
        use TemplateRenderer;

        public function adminDashboard(): void {
            $this->render('admin');
        }

        public function adminImport(): void {
            $this->render('import');
        }
    }