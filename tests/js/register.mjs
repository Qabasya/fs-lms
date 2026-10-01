// Исходники src/js — ES-модули, но package.json без "type": "module" (сборка идёт через
// webpack), поэтому Node по умолчанию читает их как CommonJS. Этот хук подключается
// флагом `--import` и объявляет их ES-модулями — только для тестов, сборку не затрагивает.
import { registerHooks } from 'node:module';

registerHooks( {
	load( url, context, nextLoad ) {
		if ( url.includes( '/src/js/' ) && url.endsWith( '.js' ) ) {
			return nextLoad( url, { ...context, format: 'module' } );
		}

		return nextLoad( url, context );
	},
} );
