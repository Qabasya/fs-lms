import { escapeHtml as esc } from '../../common/utils.js';

/** Access is saved explicitly; ordinary title/structure autosaves omit this patch. */
export const CourseAccess = {
	markup( course, subjects ) {
		const selected = course.audience_subject_keys || [];
		return `<section class="course-access" id="fs-course-access" aria-label="Доступ к курсу" hidden>
			<fieldset data-access-fields>
				<legend>Доступ к курсу</legend>
				<label><input type="checkbox" data-access-enabled ${ course.self_paced_enabled ? 'checked' : '' }> Самостоятельное прохождение</label>
				<p class="description">Курс появится в «Моих курсах» у учеников выбранных направлений после публикации. Они смогут приступить самостоятельно. Допустимы только задания с автоматической проверкой.</p>
				<fieldset class="course-access-audience">
					<legend>Аудитория — направления учеников</legend>
					${ subjects.map( ( subject ) => `<label><input type="checkbox" data-access-subject value="${ esc( subject.key ) }" ${ selected.includes( subject.key ) ? 'checked' : '' }> ${ esc( subject.name ) }</label>` ).join( '' ) }
				</fieldset>
				<p class="description">Без выбранной аудитории самостоятельная запись недоступна. Существующие зачисления сохраняются.</p>
				<button type="button" class="button button-primary" data-access-save>Сохранить доступ</button>
			</fieldset>
			<p data-access-feedback role="status" aria-live="polite"></p>
		</section>`;
	},

	patch( panel ) {
		return {
			self_paced_enabled: panel.querySelector( '[data-access-enabled]' ).checked ? '1' : '0',
			// jQuery drops empty arrays in form encoding. JSON preserves explicit clearing.
			audience_subject_keys: JSON.stringify( Array.from( panel.querySelectorAll( '[data-access-subject]:checked' ), ( input ) => input.value ) ),
		};
	},

	bind( mount, { state, save } ) {
		const button = mount.querySelector( '[data-strip-access]' );
		const panel = mount.querySelector( '#fs-course-access' );
		const fields = panel.querySelector( '[data-access-fields]' );
		const feedback = panel.querySelector( '[data-access-feedback]' );
		button.addEventListener( 'click', () => {
			panel.hidden = ! panel.hidden;
			button.setAttribute( 'aria-expanded', String( ! panel.hidden ) );
			if ( ! panel.hidden ) { panel.querySelector( '[data-access-enabled]' ).focus(); }
		} );
		panel.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Escape' ) {
				panel.hidden = true;
				button.setAttribute( 'aria-expanded', 'false' );
				button.focus();
			}
		} );
		panel.querySelector( '[data-access-save]' ).addEventListener( 'click', async () => {
			if ( fields.disabled ) { return; }
			const patch = CourseAccess.patch( panel );
			fields.disabled = true;
			feedback.classList.remove( 'is-error' );
			feedback.textContent = 'Сохраняем…';
			try {
				await save( patch );
				state.course.self_paced_enabled = patch.self_paced_enabled === '1';
				state.course.audience_subject_keys = JSON.parse( patch.audience_subject_keys );
				feedback.textContent = 'Настройки доступа сохранены';
			} catch ( error ) {
				feedback.classList.add( 'is-error' );
				feedback.textContent = typeof error === 'string' ? error : 'Не удалось сохранить доступ. Попробуйте ещё раз.';
			} finally {
				fields.disabled = false;
			}
		} );
	},
};
