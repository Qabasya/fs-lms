import { esc, ajax } from './step-editor.js';
import { showToast } from '../modules/toast.js';

/* global fs_lms_vars */
const acts = () => fs_lms_vars.ajax_actions;

/**
 * Фабрика модуля персистентности курс-билдера.
 * Изолирует таймеры автосейва, AJAX-сохранение и управление статусом.
 *
 * @param {{ courseId: number, mount: Element, state: object, onPublishToggle?: Function }} opts
 * @returns {{ setStatus, structurePayload, saveStructure, saveCourseMeta, scheduleStructure, scheduleCourseMeta, scheduleLessonMeta, togglePublish }}
 */
export function createPersistence( { courseId, mount, state, onPublishToggle } ) {
	let lessonMetaTimer = null;
	let structureTimer  = null;
	let courseMetaTimer = null;

	function setStatus( text ) {
		const s = mount.querySelector( '[data-status]' );
		if ( s ) { s.innerHTML = `<span class="saved-dot"></span> ${ esc( text ) }`; }
	}

	function structurePayload() {
		return state.course.modules.map( ( m ) => ( {
			id:          m.id,
			title:       m.title,
			description: m.description || '',
			lesson_ids:  m.lessons.map( ( l ) => l.id ),
		} ) );
	}

	function saveStructure( okMsg ) {
		ajax( acts().saveCourseStructure, { course_id: courseId, modules: structurePayload() } )
			.then( () => {
				if ( okMsg ) { showToast( okMsg, 'success' ); }
				const mc = mount.querySelector( '[data-module-count]' );
				const lc = mount.querySelector( '[data-lesson-count]' );
				if ( mc ) { mc.textContent = state.course.modules.length; }
				if ( lc ) { lc.textContent = state.course.modules.reduce( ( n, m ) => n + m.lessons.length, 0 ); }
			} )
			.catch( ( msg ) => showToast( msg, 'error' ) );
	}

	function scheduleStructure() {
		setStatus( 'Изменения…' );
		clearTimeout( structureTimer );
		structureTimer = setTimeout( () => {
			ajax( acts().saveCourseStructure, { course_id: courseId, modules: structurePayload() } )
				.then( () => setStatus( 'Все изменения сохранены' ) )
				.catch( ( msg ) => { setStatus( 'Ошибка сохранения' ); showToast( msg, 'error' ); } );
		}, 800 );
	}

	function saveCourseMeta() {
		const pub = 'publish' === state.course.status;
		ajax( acts().saveCourseMeta, { course_id: courseId, title: state.course.title, published: pub ? '1' : '' } )
			.then( () => setStatus( 'Все изменения сохранены' ) )
			.catch( ( msg ) => { setStatus( 'Ошибка сохранения' ); showToast( msg, 'error' ); } );
	}

	function scheduleCourseMeta() {
		setStatus( 'Изменения…' );
		clearTimeout( courseMetaTimer );
		courseMetaTimer = setTimeout( saveCourseMeta, 800 );
	}

	function scheduleLessonMeta( lesson ) {
		setStatus( 'Изменения…' );
		clearTimeout( lessonMetaTimer );
		lessonMetaTimer = setTimeout( () => {
			ajax( acts().updateLessonMeta, { lesson_id: lesson.id, title: lesson.title, published: lesson.published ? '1' : '' } )
				.then( () => setStatus( 'Все изменения сохранены' ) )
				.catch( ( msg ) => { setStatus( 'Ошибка сохранения' ); showToast( msg, 'error' ); } );
		}, 800 );
	}

	function togglePublish( lesson ) {
		lesson.published = ! lesson.published;
		if ( onPublishToggle ) { onPublishToggle(); }
		ajax( acts().updateLessonMeta, { lesson_id: lesson.id, title: lesson.title, published: lesson.published ? '1' : '' } )
			.then( () => showToast( lesson.published ? 'Урок опубликован' : 'Урок снят с публикации', 'success' ) )
			.catch( ( msg ) => showToast( msg, 'error' ) );
	}

	return { setStatus, structurePayload, saveStructure, saveCourseMeta, scheduleStructure, scheduleCourseMeta, scheduleLessonMeta, togglePublish };
}
