import { describe, test } from 'node:test';
import assert from 'node:assert/strict';

import { formatPhone } from '../../src/js/common/input-masks.js';
import { PhoneValidator } from '../../src/js/common/validators/PhoneValidator.js';

const validator = new PhoneValidator();

describe( 'Телефон в форме заявки', () => {
	test( 'вставленный и заполненный браузером номер принимается в обоих форматах', () => {
		for ( const phone of [ '+7 (999) 000-00-00', '+7(999)-000-00-00', '89990000000' ] ) {
			assert.equal( validator.checkCustom( phone ), null, phone );
		}
	} );

	test( 'неполный и слишком длинный номер не проходят проверку', () => {
		assert.match( validator.checkCustom( '+7 (999) 000-00' ), /не полностью/ );
		assert.notEqual( validator.checkCustom( '+7 (999) 000-00-001' ), null );
	} );

	test( 'маска соответствует подсказке и сохраняет цифры при вставке', () => {
		for ( const value of [ '89990000000', '+7(999)-000-00-00', '+7 (999) 000-00-00' ] ) {
			const input = { value };
			formatPhone( input );
			assert.equal( input.value, '+7 (999) 000-00-00' );
			assert.equal( validator.checkCustom( input.value ), null );
		}
	} );
} );
