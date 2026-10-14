import assert from 'node:assert/strict';
import { test } from 'node:test';

globalThis.window = {};
const { initMediaControls } = await import('../../src/js/player/step-video.js');

class Control {
	listeners = {};
	attributes = {};
	value = '';
	hidden = true;
	disabled = false;
	dataset = { labelMute: 'Выключить звук', labelUnmute: 'Включить звук' };
	addEventListener(name, listener) { this.listeners[name] = listener; }
	setAttribute(name, value) { this.attributes[name] = value; }
	async emit(name) { await this.listeners[name]?.(); }
}

function fixture(document = {}) {
	globalThis.document = document;
	const controls = Object.fromEntries(['mute', 'volume', 'speed', 'pip'].map(name => [name, new Control()]));
	const classes = new Set();
	const root = {
		querySelector(selector) { return controls[selector.match(/data-vp-(\w+)/)[1]]; },
		classList: { toggle(name, enabled) { if (enabled) { classes.add(name); } else { classes.delete(name); } } },
	};
	const video = Object.assign(new Control(), { volume: 0.4, muted: false, playbackRate: 1, readyState: 0 });
	return { root, video, controls, classes };
}

test('выключение звука и нулевая громкость восстанавливают прежний уровень', async () => {
	const { root, video, controls, classes } = fixture();
	initMediaControls(root, video);
	await controls.mute.emit('click');
	assert.equal(video.muted, true);
	assert.equal(controls.mute.attributes['aria-label'], 'Включить звук');
	assert.equal(controls.volume.value, '0');
	await controls.mute.emit('click');
	assert.equal(video.volume, 0.4);
	assert.equal(video.muted, false);
	controls.volume.value = '0';
	await controls.volume.emit('input');
	assert.equal(video.volume, 0);
	assert.equal(classes.has('muted'), true);
	await controls.mute.emit('click');
	assert.equal(video.volume, 0.4);
	assert.equal(classes.has('muted'), false);
});

test('внешнее изменение громкости синхронизирует панель; скорость меняет видео', async () => {
	const { root, video, controls } = fixture();
	initMediaControls(root, video);
	video.volume = 0.7;
	await video.emit('volumechange');
	assert.equal(controls.volume.value, '0.7');
	controls.speed.value = '1.75';
	await controls.speed.emit('change');
	assert.equal(video.playbackRate, 1.75);
	video.playbackRate = 0.75;
	await video.emit('ratechange');
	assert.equal(controls.speed.value, '0.75');
	controls.speed.value = '0';
	await controls.speed.emit('change');
	assert.equal(video.playbackRate, 0.75);
});

test('PiP скрыт без поддержки и доступен после загрузки метаданных', async () => {
	let f = fixture();
	initMediaControls(f.root, f.video);
	assert.equal(f.controls.pip.hidden, true);
	const doc = { pictureInPictureEnabled: true, pictureInPictureElement: null };
	f = fixture(doc);
	f.video.requestPictureInPicture = async () => { doc.pictureInPictureElement = f.video; };
	doc.exitPictureInPicture = async () => { doc.pictureInPictureElement = null; };
	initMediaControls(f.root, f.video);
	assert.equal(f.controls.pip.hidden, false);
	assert.equal(f.controls.pip.disabled, true);
	await f.video.emit('loadedmetadata');
	assert.equal(f.controls.pip.disabled, false);
	await f.controls.pip.emit('click');
	assert.equal(f.controls.pip.attributes['aria-pressed'], 'true');
	await f.controls.pip.emit('click');
	assert.equal(f.controls.pip.attributes['aria-pressed'], 'false');
	f.video.requestPictureInPicture = async () => { throw new Error('unsupported'); };
	await f.controls.pip.emit('click');
	assert.equal(f.controls.pip.disabled, false);
});
