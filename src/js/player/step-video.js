/**
 * Видео-шаг (D21, T14.12): кастомный хром нативного <video> для прямых URL —
 * play/pause, ±10 сек, ползунок, время, fullscreen; главы-чипы = перемотка
 * (только нативный режим). По окончании ролика шаг отмечается пройденным.
 */
import { getCore, onPanelShow } from './core.js';

const mounted = new WeakSet();

export function initStepVideo() {
	onPanelShow( setup );
	const core = getCore();
	if ( core ) { setup( core.panels[ core.activeIndex() ] ); }
}

function setup( panel ) {
	if ( 'video' !== panel.dataset.stepType || mounted.has( panel ) ) { return; }
	const root = panel.querySelector( '[data-video-root]' );
	if ( ! root ) { return; } // oembed-режим — хром не нужен
	mounted.add( panel );
	mountChrome( panel, root );
}

function fmt( sec ) {
	sec = Math.max( 0, Math.round( sec || 0 ) );
	return `${ Math.floor( sec / 60 ) }:${ String( sec % 60 ).padStart( 2, '0' ) }`;
}

function mountChrome( panel, root ) {
	const video  = root.querySelector( '[data-vp-el]' );
	const bigBtn = root.querySelector( '[data-vp-big]' );
	const line   = root.querySelector( '[data-vp-line]' );
	const fill   = root.querySelector( '[data-vp-fill]' );
	const knob   = root.querySelector( '[data-vp-knob]' );
	const timeEl = root.querySelector( '[data-vp-time]' );
	if ( ! video ) { return; }

	const toggle = () => { if ( video.paused ) { video.play(); } else { video.pause(); } };

	const updateTime = () => {
		const dur = video.duration || 0;
		const pct = dur ? ( video.currentTime / dur ) * 100 : 0;
		if ( fill ) { fill.style.width = `${ pct }%`; }
		if ( knob ) { knob.style.left = `${ pct }%`; }
		if ( timeEl ) { timeEl.textContent = `${ fmt( video.currentTime ) } / ${ fmt( dur ) }`; }
	};

	video.addEventListener( 'play', () => root.classList.add( 'playing' ) );
	video.addEventListener( 'pause', () => root.classList.remove( 'playing' ) );
	video.addEventListener( 'timeupdate', updateTime );
	video.addEventListener( 'loadedmetadata', updateTime );
	video.addEventListener( 'click', toggle );

	// Просмотр до конца = шаг пройден (как «Далее», но без перехода).
	video.addEventListener( 'ended', () => {
		const core = getCore();
		if ( ! core ) { return; }
		const idx = core.panels.indexOf( panel );
		if ( ! [ 'completed', 'failed' ].includes( panel.dataset.status ) ) {
			core.setStatus( idx, 'completed' );
			core.mark( panel.dataset.step, 'completed' );
			core.unlockNext();
		}
	} );

	bigBtn?.addEventListener( 'click', toggle );
	root.querySelector( '[data-vp-toggle]' )?.addEventListener( 'click', toggle );
	root.querySelector( '[data-vp-b10]' )?.addEventListener( 'click', () => {
		video.currentTime = Math.max( 0, video.currentTime - 10 );
	} );
	root.querySelector( '[data-vp-f10]' )?.addEventListener( 'click', () => {
		video.currentTime = Math.min( video.duration || 0, video.currentTime + 10 );
	} );

	line?.addEventListener( 'click', ( e ) => {
		const r = line.getBoundingClientRect();
		const k = Math.min( 1, Math.max( 0, ( e.clientX - r.left ) / r.width ) );
		video.currentTime = k * ( video.duration || 0 );
		updateTime();
	} );

	root.querySelector( '[data-vp-fs]' )?.addEventListener( 'click', () => {
		if ( document.fullscreenElement ) {
			document.exitFullscreen();
		} else {
			( root.requestFullscreen || video.requestFullscreen )?.call( root );
		}
	} );

	// Главы-чипы: перемотка (рендерятся только в нативном режиме, D21).
	panel.querySelectorAll( '[data-chap-t]' ).forEach( ( chip ) => {
		chip.addEventListener( 'click', () => {
			video.currentTime = parseInt( chip.dataset.chapT, 10 ) || 0;
			video.play();
		} );
	} );

	updateTime();
}
