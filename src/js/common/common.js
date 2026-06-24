import { ToggleComponent } from './components/toggle.js';
import { BadgeComponent } from './components/badge.js';
import { ToggleSecretComponent } from './components/toggle-secret.js';
import { CopyButton } from './components/copy-button.js';
import { TooltipComponent } from './components/tooltip.js';
import { initFormValidation } from './validation-manager.js';

function initGlobalFormValidation() {
    const forms = document.querySelectorAll( 'form[data-fs-validate], .fs-lms-form' );

    if ( 0 === forms.length ) {
        return;
    }

    forms.forEach( form => {
        const validateAll = initFormValidation( form );

        form.addEventListener( 'submit', ( e ) => {
            if ( ! validateAll() ) {
                e.preventDefault();
            }
        } );
    } );
}

(function ($) {
    'use strict';

    $(document).ready(function () {
        ToggleComponent.init();
        BadgeComponent.init();
        ToggleSecretComponent.init();
        CopyButton.init();
        TooltipComponent.init();
        initGlobalFormValidation();
    });

})(jQuery);