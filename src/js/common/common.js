import { ToggleComponent } from './components/toggle.js';
import { BadgeComponent } from './components/badge.js';
import { ToggleSecretComponent } from './components/toggle-secret.js';

(function ($) {
    'use strict';

    $(document).ready(function () {
        ToggleComponent.init();
        BadgeComponent.init();
        ToggleSecretComponent.init();
    });

})(jQuery);
