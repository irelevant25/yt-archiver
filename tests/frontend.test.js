/**
 * Tests for pure functions in public/js/app.js (no browser needed).
 * Run with:  node tests/frontend.test.js
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const code = fs.readFileSync(path.join(__dirname, '../public/js/app.js'), 'utf8');
// Minimal DOM: getElementById returns plain objects that remember what the code sets
const elements = {};
const ctx = {
    document: {
        addEventListener() {},
        getElementById: (id) => (elements[id] ??= { id, hidden: false, disabled: false, dataset: {}, innerHTML: '', textContent: '', classList: { add() {}, remove() {} } }),
    },
    window: {}, URL, console,
};
vm.createContext(ctx);
vm.runInContext(code, ctx);
const run = (expr) => vm.runInContext(expr, ctx);

let failures = 0;
function test(name, fn) {
    try {
        fn();
    } catch (error) {
        failures++;
        console.log(`FAIL ${name}\n  ${error.message}`);
    }
}

const evil = `x" onmouseover="alert(1)' <img src=x onerror=alert(1)>`;
ctx.items = [
    { id: 'itm_1', type: 'playlist_item', playlist_id: 'pl_1', playlist_title: evil, title: evil, index: 2, total: 3, format: 'mp3', active: true, progress: { status: 'downloading', percent: 42 } },
    { id: 'itm_2', type: 'playlist_item', playlist_id: 'pl_1', playlist_title: 'P', title: 'b', index: 3, total: 3, format: 'mp3', active: false },
    { id: 'zip_1', type: 'archive', playlist_id: 'pl_1', playlist_title: 'P', total: 3, format: 'mp3', active: false },
    { id: 'vid_1', type: 'video', url: 'https://www.youtube.com/watch?v=abc', format: '<b>', active: false },
    { id: 'pl_2', type: 'playlist', url: 'https://www.youtube.com/playlist?list=PL', format: 'mp4', active: false },
];

test('groups consecutive playlist jobs', () => {
    const segments = run('groupQueueItems(items)');
    // Compare as JSON: arrays from the vm context have a different prototype
    assert.strictEqual(JSON.stringify(segments.map(s => s.playlistId || s.item.id)), '["pl_1","vid_1","pl_2"]');
    assert.strictEqual(segments[0].items.length, 3);
});

test('playlist group shows progress and counts', () => {
    const html = run('renderPlaylistGroup(groupQueueItems(items)[0])');
    assert.ok(html.includes('1 / 3 done'), 'done counter');
    assert.ok(html.includes('downloading 42%'), 'active item percent');
    assert.ok(html.includes('data-cancel-playlist="pl_1"'), 'cancel playlist button');
});

test('queue rendering escapes untrusted strings', () => {
    const html = run('renderPlaylistGroup(groupQueueItems(items)[0]) + renderSingleJob(items[3])');
    assert.ok(!html.includes('<img'), 'no injected element');
    assert.ok(!html.includes('" onmouseover'), 'no attribute breakout');
    assert.ok(!html.includes('<b>'), 'format is whitelisted');
});

test('escapeHtml escapes quotes', () => {
    assert.strictEqual(run(`escapeHtml(${JSON.stringify(`'"<>&`)})`), '&#39;&quot;&lt;&gt;&amp;');
});

test('detectPlaylist', () => {
    const detect = (url) => JSON.stringify(run(`detectPlaylist(${JSON.stringify(url)})`));
    assert.strictEqual(detect('https://www.youtube.com/watch?v=abc&list=PL1'), '{"forced":false}');
    assert.strictEqual(detect('https://www.youtube.com/playlist?list=PL1'), '{"forced":true}');
    assert.strictEqual(detect('https://youtu.be/abc'), 'null');
    assert.strictEqual(detect('not a url'), 'null');
});

test('formatSize', () => {
    assert.strictEqual(run('formatSize(5 * 1024 ** 4)'), '5.0 TB');
    assert.strictEqual(run('formatSize(0)'), '-');
});

test('formatDuration', () => {
    assert.strictEqual(run('formatDuration(11520)'), '3 h 12 min');
    assert.strictEqual(run('formatDuration(95)'), '1 min 35 s');
    assert.strictEqual(run('formatDuration(null)'), 'unknown');
});

const GB = 1024 ** 3;
const storage = (free, reserved = 0) => ({ total: 100 * GB, free, library: 20 * GB, reserved, min_free: 10 * GB, min_free_percent: 10 });

test('storage panel shows usage and keeps downloads enabled', () => {
    ctx.storage = storage(50 * GB, 5 * GB);
    run('renderStorage(storage)');
    assert.strictEqual(elements.storagePanel.hidden, false);
    assert.strictEqual(elements.storageSummary.textContent, '50.0 GB free of 100.0 GB (50.0 %)');
    assert.ok(elements.storageLegend.innerHTML.includes('Library 20.0 GB'), 'library size');
    assert.ok(elements.storageLegend.innerHTML.includes('Reserved by queue ≈ 5.0 GB'), 'reservation');
    assert.ok(elements.storageBar.innerHTML.includes('left: 90%'), 'minimum marker');
    assert.strictEqual(elements.storageBlocked.hidden, true);
    assert.strictEqual(elements.downloadBtn.disabled, false);
});

test('storage panel blocks downloads below the minimum', () => {
    ctx.storage = storage(9 * GB);
    run('renderStorage(storage)');
    assert.strictEqual(elements.storageBlocked.hidden, false);
    assert.ok(elements.storageBlocked.textContent.includes('less than 10 %'));
    assert.strictEqual(elements.downloadBtn.disabled, true);
});

test('storage panel blocks downloads when the queue claims the remaining space', () => {
    ctx.storage = storage(15 * GB, 6 * GB);
    run('renderStorage(storage)');
    assert.ok(elements.storageBlocked.textContent.includes('until the queue finishes'));
    assert.strictEqual(elements.downloadBtn.disabled, true);
});

test('storage panel hides when the disk size is unknown', () => {
    ctx.storage = { total: 0 };
    run('renderStorage(storage)');
    assert.strictEqual(elements.storagePanel.hidden, true);
});

test('user panel escapes the name and shows admin links only to admins', () => {
    ctx.me = { user: { email: 'eve@example.com', name: '<img src=x onerror=alert(1)>', picture: 'javascript:alert(1)', role: 'user' }, csrf: 'tok', pending_users: null };
    run('renderUserPanel(me)');
    assert.ok(!elements.userAvatar.innerHTML.includes('<img'), 'non-https picture is not rendered');
    assert.strictEqual(elements.userName.textContent, '<img src=x onerror=alert(1)>');
    assert.strictEqual(elements.usersLink.hidden, true);
    assert.strictEqual(elements.updateBtn.hidden, true);
    assert.strictEqual(elements.logoutCsrf.value, 'tok');

    ctx.me = { user: { email: 'a@example.com', name: 'Admin', picture: 'https://lh3.googleusercontent.com/a', role: 'admin' }, csrf: 't', pending_users: 2 };
    run('renderUserPanel(me)');
    assert.strictEqual(elements.usersLink.hidden, false);
    assert.strictEqual(elements.pendingBadge.hidden, false);
    assert.strictEqual(elements.pendingBadge.textContent, '2');
    assert.ok(elements.userAvatar.innerHTML.includes('referrerpolicy="no-referrer"'));
});

console.log(failures ? `\n${failures} FAILURE(S)` : 'All frontend tests passed');
process.exit(failures ? 1 : 0);
