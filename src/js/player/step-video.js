/**
 * Видео-шаг (D21, T14.12): кастомный хром нативного <video> для прямых URL —
 * play/pause, ±10 сек, ±5 сек (стрелки ←/→ и двойной тап по краям экрана), громкость ↑/↓, Пробел/F/M, ползунок, время, fullscreen; главы-чипы = перемотка
 * (только нативный режим). По окончании ролика шаг отмечается пройденным.
 * Тот же хром переиспользует шаг-трансляция (broadcast, Этап 1), когда у
 * занятия уже есть запись — see partials/video-chrome.php.
 */
import { getCore, onPanelShow } from './core.js';

const mounted = new WeakSet();
const CHROME_TYPES = [ 'video', 'broadcast' ];
const SKIP_SECONDS = 5;
const VOLUME_STEP = 0.05;
const DOUBLE_TAP_MS = 300;
const SKIP_HINT_MS = 700;
/** Доля ширины с каждого края, где двойной тап перематывает; середина остаётся «плей/пауза». */
const EDGE_ZONE = 0.33;

export function initStepVideo() {
	onPanelShow( setup );
	const core = getCore();
	if ( core ) { setup( core.panels[ core.activeIndex() ] ); }
}

function setup( panel ) {
	if ( ! CHROME_TYPES.includes( panel.dataset.stepType ) || mounted.has( panel ) ) { return; }
	const root = panel.querySelector( '[data-video-root]' );
	if ( ! root ) { return; } // oembed-режим/заглушка без записи — хром не нужен
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
	initMediaControls( root, video );

	const toggle = () => { if ( video.paused ) { video.play(); } else { video.pause(); } };

	const updateTime = () => {
		const dur = video.duration || 0;
		const pct = dur ? ( video.currentTime / dur ) * 100 : 0;
		// Одна переменная на контейнере двигает и заливку, и бегунок (CSS var(--progress)).
		if ( line ) { line.style.setProperty( '--progress', `${ pct }%` ); }
		if ( timeEl ) { timeEl.textContent = `${ fmt( video.currentTime ) } / ${ fmt( dur ) }`; }
	};

	video.addEventListener( 'play', () => root.classList.add( 'playing' ) );
	video.addEventListener( 'pause', () => root.classList.remove( 'playing' ) );
	video.addEventListener( 'timeupdate', updateTime );
	video.addEventListener( 'loadedmetadata', updateTime );
	const skipHint = root.querySelector( '[data-vp-skip]' );
	let hintTimer = 0;
	const seekBy = ( delta ) => {
		const end = video.duration || 0;
		video.currentTime = Math.min( end, Math.max( 0, video.currentTime + delta ) );
		updateTime();
		if ( ! skipHint ) { return; }
		skipHint.dataset.side = delta < 0 ? 'back' : 'forward';
		skipHint.textContent = `${ delta < 0 ? '−' : '+' }${ Math.abs( delta ) } с`;
		skipHint.hidden = false;
		window.clearTimeout( hintTimer );
		hintTimer = window.setTimeout( () => { skipHint.hidden = true; }, SKIP_HINT_MS );
	};

	// Подсказка по центру кадра: громкость и звук.
	const showHint = ( text, side ) => {
		if ( ! skipHint ) { return; }
		skipHint.dataset.side = side;
		skipHint.textContent = text;
		skipHint.hidden = false;
		window.clearTimeout( hintTimer );
		hintTimer = window.setTimeout( () => { skipHint.hidden = true; }, SKIP_HINT_MS );
	};
	const volumeBy = ( delta ) => {
		const next = Math.min( 1, Math.max( 0, Math.round( ( ( video.muted ? 0 : video.volume ) + delta ) * 100 ) / 100 ) );
		video.volume = next;
		video.muted = 0 === next;
		showHint( `Громкость ${ Math.round( next * 100 ) }%`, 'center' );
	};
	const toggleMute = () => {
		video.muted = ! video.muted;
		showHint( video.muted ? 'Звук выключен' : `Громкость ${ Math.round( video.volume * 100 ) }%`, 'center' );
	};
	const toggleFullscreen = () => {
		if ( document.fullscreenElement ) {
			document.exitFullscreen();
		} else {
			( root.requestFullscreen || video.requestFullscreen )?.call( root );
		}
	};

	// Клавиатура, пока этот шаг на экране и фокус не в поле/кнопке: ←/→ — ∓5 с, ↑/↓ — громкость ±5%,
	// Пробел — плей/пауза, F — во весь экран, M — звук. Листание шагов стрелками на видео отключено (core.js).
	document.addEventListener( 'keydown', ( e ) => {
		if ( e.altKey || e.ctrlKey || e.metaKey || e.shiftKey ) { return; }
		const key = e.key.length === 1 ? e.key.toLowerCase() : e.key;
		if ( ! [ 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', ' ', 'f', 'а', 'm', 'ь' ].includes( key ) ) { return; }
		if ( ! root.isConnected || ! root.offsetParent || e.defaultPrevented ) { return; }
		if ( document.querySelector( '.cp-dim' ) ) { return; }
		if ( e.target.closest?.( 'input, select, textarea, [contenteditable="true"]' ) ) { return; }
		if ( ' ' === key && e.target.closest?.( 'button, a' ) ) { return; } // Пробел на кнопке — её нажатие
		e.preventDefault();
		if ( 'ArrowLeft' === key ) { seekBy( -SKIP_SECONDS ); }
		else if ( 'ArrowRight' === key ) { seekBy( SKIP_SECONDS ); }
		else if ( 'ArrowUp' === key ) { volumeBy( VOLUME_STEP ); }
		else if ( 'ArrowDown' === key ) { volumeBy( -VOLUME_STEP ); }
		else if ( ' ' === key ) { toggle(); }
		else if ( 'f' === key || 'а' === key ) { toggleFullscreen(); }
		else { toggleMute(); }
	} );

	// Сенсорные экраны: двойной тап по левому/правому краю — ∓5 секунд, тап по середине и одиночный — плей/пауза.
	// Мышь не затронута. Одиночный тап по краю ждёт окно двойного, поэтому реагирует с задержкой в DOUBLE_TAP_MS.
	let pointerType = 'mouse';
	let lastTap = { time: 0, side: '' };
	let tapTimer = 0;
	video.addEventListener( 'pointerdown', ( e ) => { pointerType = e.pointerType; } );
	video.addEventListener( 'click', ( e ) => {
		if ( 'touch' !== pointerType ) { toggle(); return; }
		const r = video.getBoundingClientRect();
		const k = ( e.clientX - r.left ) / r.width;
		const side = k < EDGE_ZONE ? 'back' : ( k > 1 - EDGE_ZONE ? 'forward' : '' );
		if ( ! side ) { toggle(); return; }
		const now = Date.now();
		if ( lastTap.side === side && now - lastTap.time < DOUBLE_TAP_MS ) {
			window.clearTimeout( tapTimer );
			lastTap = { time: 0, side: '' };
			seekBy( 'back' === side ? -SKIP_SECONDS : SKIP_SECONDS );
			return;
		}
		lastTap = { time: now, side };
		window.clearTimeout( tapTimer );
		tapTimer = window.setTimeout( () => { lastTap = { time: 0, side: '' }; toggle(); }, DOUBLE_TAP_MS );
	} );

	// Просмотр до конца = шаг пройден (обычно он засчитан уже при открытии — core.js).
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

	// Трансляция: запись из хранилища не открылась (объекта нет, бакет недоступен) —
	// вместо мёртвого плеера показываем запасную ссылку (Tasks.md З1).
	const fallback = panel.querySelector( '[data-bc-fallback]' );
	if ( fallback ) {
		video.addEventListener( 'error', () => {
			root.hidden = true;
			fallback.hidden = false;
		} );
	}

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

	root.querySelector( '[data-vp-fs]' )?.addEventListener( 'click', toggleFullscreen );

	// Главы-чипы: перемотка (рендерятся только в нативном режиме, D21).
	panel.querySelectorAll( '[data-chap-t]' ).forEach( ( chip ) => {
		chip.addEventListener( 'click', () => {
			video.currentTime = parseInt( chip.dataset.chapT, 10 ) || 0;
			video.play();
		} );
	} );

	updateTime();
}

/** Громкость, скорость и PiP общие для видео и записи трансляции. */
export function initMediaControls( root, video ) {
	const mute = root.querySelector( '[data-vp-mute]' );
	const volume = root.querySelector( '[data-vp-volume]' );
	const speed = root.querySelector( '[data-vp-speed]' );
	const pip = root.querySelector( '[data-vp-pip]' );
	let lastVolume = video.volume || 1;

	const syncVolume = () => {
		const silent = video.muted || video.volume === 0;
		root.classList.toggle( 'muted', silent );
		if ( volume ) { volume.value = String( video.muted ? 0 : video.volume ); }
		if ( mute ) {
			mute.setAttribute( 'aria-pressed', String( silent ) );
			mute.setAttribute( 'aria-label', silent ? mute.dataset.labelUnmute : mute.dataset.labelMute );
		}
		if ( video.volume > 0 ) { lastVolume = video.volume; }
	};
	video.addEventListener( 'volumechange', syncVolume );
	mute?.addEventListener( 'click', () => {
		if ( video.muted || video.volume === 0 ) {
			video.volume = lastVolume;
			video.muted = false;
		} else {
			video.muted = true;
		}
		syncVolume();
	} );
	volume?.addEventListener( 'input', () => {
		const value = Number( volume.value );
		if ( ! Number.isFinite( value ) ) { return; }
		video.volume = Math.min( 1, Math.max( 0, value ) );
		video.muted = video.volume === 0;
		syncVolume();
	} );
	speed?.addEventListener( 'change', () => {
		const rate = Number( speed.value );
		if ( [ 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2 ].includes( rate ) ) {
			video.playbackRate = rate;
		}
	} );
	const syncSpeed = () => { if ( speed ) { speed.value = String( video.playbackRate ); } };
	video.addEventListener( 'ratechange', syncSpeed );

	if ( pip && document.pictureInPictureEnabled && video.requestPictureInPicture ) {
		pip.hidden = false;
		pip.disabled = video.readyState === 0;
		video.addEventListener( 'loadedmetadata', () => { pip.disabled = false; } );
		const syncPip = () => pip.setAttribute( 'aria-pressed', String( document.pictureInPictureElement === video ) );
		video.addEventListener( 'enterpictureinpicture', syncPip );
		video.addEventListener( 'leavepictureinpicture', syncPip );
		pip.addEventListener( 'click', async () => {
			pip.disabled = true;
			try {
				if ( document.pictureInPictureElement === video ) {
					await document.exitPictureInPicture();
				} else {
					await video.requestPictureInPicture();
				}
			} catch {
				// Браузер может запретить PiP для конкретного видео; плеер продолжает работать.
			} finally {
				pip.disabled = false;
				syncPip();
			}
		} );
	}
	syncVolume();
	syncSpeed();
}
