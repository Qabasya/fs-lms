import { ToggleComponent } from './components/toggle.js';
import { BadgeComponent } from './components/badge.js';

(function ($) {
    'use strict';

    $(document).ready(function () {
        ToggleComponent.init();
        BadgeComponent.init();
    });

})(jQuery);
