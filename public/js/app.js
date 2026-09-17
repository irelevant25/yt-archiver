/**
 * YT Archiver - Main Application JavaScript
 */

// State
let videos = [];
let sortColumn = 'created_at';
let sortDirection = 'desc';
let deleteVideoId = null;

const ICONS = {
    cancel: '<svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>',
    download: '<svg viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>',
    delete: '<svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>',
    video: '<svg viewBox="0 0 24 24"><path d="M18 4l2 4h-3l-2-4h-2l2 4h-3l-2-4H8l2 4H7L5 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4h-4z" fill="currentColor"/></svg>',
    audio: '<svg viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z" fill="currentColor"/></svg>',
    playlist: '<svg viewBox="0 0 24 24"><path d="M15 6H3v2h12V6zm0 4H3v2h12v-2zM3 16h8v-2H3v2zM17 6v8.18c-.31-.11-.65-.18-1-.18-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3V8h3V6h-5z" fill="currentColor"/></svg>',
    archive: '<svg viewBox="0 0 24 24"><path d="M20.54 5.23l-1.39-1.68C18.88 3.21 18.47 3 18 3H6c-.47 0-.88.21-1.16.55L3.46 5.23C3.17 5.57 3 6.02 3 6.5V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6.5c0-.48-.17-.93-.46-1.27zM12 17.5L6.5 12H10v-2h4v2h3.5L12 17.5zM5.12 5l.81-1h12l.94 1H5.12z" fill="currentColor"/></svg>',
};

// API Functions
const API_URL = '/api.php';

async function fetchApi(action, options = {}) {
    const url = new URL(API_URL, window.location.origin);
    url.searchParams.set('action', action);

    if (options.params) {
        Object.entries(options.params).forEach(([key, value]) => {
            url.searchParams.set(key, value);
        });
    }

    const method = options.method || 'GET';
    const fetchOptions = {
        method,
        headers: options.headers || {}
    };

    // The API requires a JSON Content-Type on every POST (CSRF protection)
    if (options.body || method === 'POST') {
        fetchOptions.headers['Content-Type'] = 'application/json';
        fetchOptions.body = JSON.stringify(options.body || {});
    }

    const response = await fetch(url, fetchOptions);
    const data = await response.json();
    if (response.status === 401 && data.login_required) {
        // Signed out, or the account was blocked meanwhile
        window.location.href = '/login.php';
        return new Promise(() => {});
    }
    return data;
}

// Signed-in user
let currentUser = null;

async function loadCurrentUser() {
    try {
        const data = await fetchApi('me');
        currentUser = data.user;
        renderUserPanel(data);
    } catch (error) {
        console.error('Failed to load the current user:', error);
    }
}

function renderUserPanel(data) {
    const user = data.user || {};
    const isAdmin = user.role === 'admin';
    const label = user.name || user.email || '';

    document.getElementById('userAvatar').innerHTML = /^https:\/\//.test(user.picture || '')
        ? `<img src="${escapeHtml(user.picture)}" alt="" referrerpolicy="no-referrer">`
        : escapeHtml(label.charAt(0).toUpperCase());
    document.getElementById('userName').textContent = label;
    document.getElementById('userEmail').textContent = user.name ? user.email : '';
    document.getElementById('logoutCsrf').value = data.csrf || '';
    document.getElementById('usersLink').hidden = !isAdmin;
    document.getElementById('logsLink').hidden = !isAdmin;

    const badge = document.getElementById('pendingBadge');
    badge.hidden = !(data.pending_users > 0);
    badge.textContent = data.pending_users > 0 ? String(data.pending_users) : '';
    badge.title = 'Accounts waiting for approval';

    // Updating yt-dlp is an administrator task
    document.getElementById('updateBtn').hidden = !isAdmin;
    document.getElementById('userPanel').hidden = false;
}

// Version Check
async function checkVersion() {
    try {
        const data = await fetchApi('version');

        document.getElementById('currentVersion').textContent = data.current;
        document.getElementById('latestVersion').textContent = data.latest;

        const currentEl = document.getElementById('currentVersion');
        const updateBtn = document.getElementById('updateBtn');

        if (data.needs_update) {
            currentEl.classList.add('outdated');
            currentEl.classList.remove('current');
            updateBtn.disabled = false;
        } else {
            currentEl.classList.add('current');
            currentEl.classList.remove('outdated');
            updateBtn.disabled = true;
        }
    } catch (error) {
        console.error('Failed to check version:', error);
    }
}

// Update yt-dlp
async function updateYtDlp() {
    const btn = document.getElementById('updateBtn');
    const originalHtml = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<div class="spinner"></div><span>Updating...</span>';

    try {
        const data = await fetchApi('update', { method: 'POST' });
        if (!data.success) {
            throw new Error(data.error || 'pip install failed');
        }
        showToast('yt-dlp updated to ' + data.version, 'success');
        await checkVersion();
    } catch (error) {
        showToast('Update failed: ' + error.message, 'error');
    } finally {
        btn.innerHTML = originalHtml;
    }
}

// Download
// Check size and disk space first, ask for confirmation when needed, then queue.
// The server enforces the same rules on `download`.
async function startDownload(url, format, playlist, setButtonLabel = () => {}) {
    try {
        setButtonLabel('Checking...');
        const check = await fetchApi('inspect', {
            method: 'POST',
            body: { url, format, playlist }
        });
        if (!check.success) {
            throw new Error(check.error || 'Could not check the download');
        }
        if (check.storage) {
            renderStorage(check.storage);
        }
        if (check.blocked) {
            throw new Error(check.blocked);
        }
        if (check.confirmation_required && !(await confirmDownload(check))) {
            return false;
        }

        setButtonLabel('Adding...');
        const data = await fetchApi('download', {
            method: 'POST',
            body: { url, format, playlist, confirmed: check.confirmation_required === true }
        });

        if (data.success) {
            showToast(data.message || 'Added to download queue', 'success');
            await updateQueueStatus();
            return true;
        }
        throw new Error(data.error || 'Download failed');
    } catch (error) {
        showToast('Error: ' + error.message, 'error');
        return false;
    }
}

// Large download / low disk space confirmation; resolves true when the user confirms
let resolveConfirmDownload = null;

function confirmDownload(check) {
    const inspection = check.inspection || {};
    const details = [
        ['Title', inspection.title || '-'],
        ['Type', inspection.type === 'playlist' ? `Playlist (${Number(inspection.items) || 0} items)` : 'Video'],
        ['Duration', formatDuration(inspection.duration)],
        ['Estimated size', inspection.estimated_size ? '≈ ' + formatSize(inspection.estimated_size) : 'unknown'],
    ];
    if (inspection.type === 'playlist' && inspection.estimated_size) {
        details.push(['Space while archiving', '≈ ' + formatSize(2 * inspection.estimated_size)]);
    }
    if (check.projected_free !== null && check.projected_free !== undefined) {
        details.push(['Free afterwards', '≈ ' + formatSize(Math.max(0, check.projected_free))]);
    }

    document.getElementById('confirmReasons').innerHTML = (check.reasons || [])
        .map(reason => `<li>${escapeHtml(reason)}</li>`).join('');
    document.getElementById('confirmDetails').innerHTML = details
        .map(([label, value]) => `<dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd>`).join('');
    document.getElementById('confirmDownloadModal').classList.add('active');

    return new Promise(resolve => { resolveConfirmDownload = resolve; });
}

function closeConfirmDownload(confirmed) {
    document.getElementById('confirmDownloadModal').classList.remove('active');
    if (resolveConfirmDownload) {
        resolveConfirmDownload(confirmed);
        resolveConfirmDownload = null;
    }
}

// Storage panel
let storageBlocked = false;

function renderStorage(storage) {
    const panel = document.getElementById('storagePanel');
    if (!storage || !storage.total) {
        panel.hidden = true;
        return;
    }
    panel.hidden = false;

    const total = storage.total;
    const free = Math.max(0, storage.free);
    const library = Math.min(Math.max(0, storage.library), total - free);
    const other = Math.max(0, total - free - library);
    const reserved = Math.min(Math.max(0, storage.reserved), free);
    const percent = bytes => (bytes * 100 / total);

    document.getElementById('storageSummary').textContent =
        `${formatSize(free)} free of ${formatSize(total)} (${percent(free).toFixed(1)} %)`;

    document.getElementById('storageBar').innerHTML = `
        <div class="storage-segment other" style="width: ${percent(other)}%" title="Other files"></div>
        <div class="storage-segment library" style="width: ${percent(library)}%" title="Library"></div>
        <div class="storage-segment reserved" style="width: ${percent(reserved)}%" title="Reserved by the queue"></div>
        <div class="storage-limit" style="left: ${100 - Number(storage.min_free_percent)}%" title="Minimum free space"></div>
    `;

    document.getElementById('storageLegend').innerHTML = [
        ['#667eea', `Library ${formatSize(library)}`],
        ['var(--text-muted)', `Other files ${formatSize(other)}`],
        ['var(--warning)', `Reserved by queue ≈ ${formatSize(storage.reserved)}`],
        ['var(--bg-primary)', `Free ${formatSize(free)}`],
        ['var(--accent)', `Minimum free ${Number(storage.min_free_percent)} % (${formatSize(storage.min_free)})`],
    ].map(([color, label]) => `<span style="--swatch: ${color}">${escapeHtml(label)}</span>`).join('');

    // Same rule as the server: nothing new may be queued once free space (minus queue reservations) is below the minimum
    const available = free - Math.max(0, storage.reserved);
    storageBlocked = available < storage.min_free;
    const blockedEl = document.getElementById('storageBlocked');
    blockedEl.hidden = !storageBlocked;
    blockedEl.textContent = storageBlocked
        ? (free < storage.min_free
            ? `Downloads are disabled: less than ${Number(storage.min_free_percent)} % of the disk is free. Delete something from the library to continue.`
            : `Downloads are disabled until the queue finishes: queued downloads already need the space above the ${Number(storage.min_free_percent)} % minimum.`)
        : '';
    updateDownloadButton();
}

function updateDownloadButton() {
    const btn = document.getElementById('downloadBtn');
    if (!btn.dataset.busy) {
        btn.disabled = storageBlocked;
    }
}

// Playlist detection for the download form
function detectPlaylist(value) {
    try {
        const url = new URL(value.trim());
        const list = url.searchParams.get('list');
        if (!list) return null;
        return { forced: url.pathname.replace(/\/+$/, '') === '/playlist' };
    } catch {
        return null;
    }
}

function updatePlaylistToggle() {
    const toggle = document.getElementById('playlistToggle');
    const checkbox = document.getElementById('playlistCheckbox');
    const detected = detectPlaylist(document.getElementById('urlInput').value);

    toggle.hidden = !detected;
    if (!detected) {
        checkbox.checked = false;
        checkbox.disabled = false;
    } else if (detected.forced) {
        checkbox.checked = true;
        checkbox.disabled = true;
    } else {
        checkbox.disabled = false;
    }
}

// Queue Status
async function updateQueueStatus() {
    try {
        const data = await fetchApi('status');
        renderQueue(data);
        renderStorage(data.storage);
    } catch (error) {
        console.error('Failed to update queue:', error);
    }
}

/** Split queue items into standalone jobs and groups of consecutive jobs of the same playlist. */
function groupQueueItems(items) {
    const segments = [];
    for (const item of items) {
        const last = segments[segments.length - 1];
        if (item.playlist_id && last && last.playlistId === item.playlist_id) {
            last.items.push(item);
        } else if (item.playlist_id) {
            segments.push({ playlistId: item.playlist_id, items: [item] });
        } else {
            segments.push({ item });
        }
    }
    return segments;
}

function formatBadge(format) {
    const safe = format === 'mp3' ? 'mp3' : 'mp4';
    return `<span class="badge ${safe}">${safe.toUpperCase()}</span>`;
}

function progressBar(percent) {
    const width = Math.max(0, Math.min(100, Number(percent) || 0));
    return `<div class="progress-bar"><div class="progress-fill" style="width: ${width}%"></div></div>`;
}

function statusLabel(item) {
    if (!item.active) return 'Queued';
    const status = item.progress ? item.progress.status : 'starting';
    if (status === 'downloading' || status === 'archiving') {
        return `${escapeHtml(status)} ${Number(item.progress.percent) || 0}%`;
    }
    return escapeHtml(status);
}

function renderSingleJob(item) {
    const progress = item.active ? item.progress : null;
    const isPlaylist = item.type === 'playlist';
    const title = progress && progress.title ? progress.title : item.title || item.playlist_title || item.url;

    return `
        <div class="queue-item ${item.active ? 'active' : ''}">
            <div class="queue-item-header">
                <div class="queue-item-title">
                    <span class="queue-item-name">${escapeHtml(title)}</span>
                    ${isPlaylist ? '<span class="badge playlist">Playlist</span>' : ''}
                    ${formatBadge(item.format)}
                </div>
                <div class="queue-item-actions">
                    ${item.estimated_size ? `<span class="queue-item-size">≈ ${formatSize(item.estimated_size)}</span>` : ''}
                    <span class="queue-item-status">${statusLabel(item)}</span>
                    <button class="cancel-btn" data-cancel-id="${escapeHtml(item.id)}" title="Cancel">${ICONS.cancel}</button>
                </div>
            </div>
            ${item.active ? progressBar(progress ? progress.percent : 0) : ''}
        </div>
    `;
}

function renderPlaylistGroup(segment) {
    const items = segment.items;
    const first = items[0];
    const total = first.total || items.filter(i => i.type === 'playlist_item').length;
    const remaining = items.filter(i => i.type === 'playlist_item').length;
    const done = total - remaining;
    const isActive = items.some(i => i.active);
    const archiveEstimate = (items.find(i => i.type === 'archive') || {}).estimated_size;

    const rows = items.map(item => {
        const isArchive = item.type === 'archive';
        const percent = item.active && item.progress ? item.progress.percent : 0;
        const index = isArchive ? ICONS.archive : escapeHtml(String(item.index || ''));
        const name = isArchive ? 'Create ZIP archive' : item.title || item.url;

        return `
            <div class="queue-subitem ${item.active ? 'active' : ''}">
                <div class="queue-subitem-row">
                    <span class="queue-subitem-index">${index}</span>
                    <span class="queue-subitem-title" title="${escapeHtml(name)}">${escapeHtml(name)}</span>
                    <span class="queue-item-status">${statusLabel(item)}</span>
                    ${isArchive ? '' : `<button class="cancel-btn small" data-cancel-id="${escapeHtml(item.id)}" title="Skip this item">${ICONS.cancel}</button>`}
                </div>
                ${item.active ? progressBar(percent) : ''}
            </div>
        `;
    }).join('');

    return `
        <div class="queue-item queue-group ${isActive ? 'active' : ''}">
            <div class="queue-item-header">
                <div class="queue-item-title">
                    <span class="queue-group-icon">${ICONS.playlist}</span>
                    <span class="queue-item-name">${escapeHtml(first.playlist_title || 'Playlist')}</span>
                    <span class="badge playlist">Playlist</span>
                    ${formatBadge(first.format)}
                </div>
                <div class="queue-item-actions">
                    ${archiveEstimate ? `<span class="queue-item-size">≈ ${formatSize(archiveEstimate)}</span>` : ''}
                    <span class="queue-item-status">${done} / ${total} done</span>
                    <button class="cancel-btn" data-cancel-playlist="${escapeHtml(segment.playlistId)}" title="Cancel whole playlist">${ICONS.cancel}</button>
                </div>
            </div>
            ${progressBar(total ? (done / total) * 100 : 0)}
            <div class="queue-group-items" data-group-list="${escapeHtml(segment.playlistId)}">${rows}</div>
        </div>
    `;
}

function renderQueue(data) {
    const container = document.getElementById('queueContainer');

    const items = [];

    if (data.current) {
        items.push({
            ...data.current,
            active: true,
            progress: data.progress
        });
    }

    if (data.queue) {
        items.push(...data.queue.map(item => ({ ...item, active: false })));
    }

    if (items.length === 0) {
        container.innerHTML = `
            <div class="queue-empty">
                <svg viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                <p>No downloads in progress</p>
            </div>
        `;
        return;
    }

    // Re-rendering replaces the scrollable playlist lists; keep their scroll position
    const scrollPositions = {};
    container.querySelectorAll('[data-group-list]').forEach(el => {
        scrollPositions[el.dataset.groupList] = el.scrollTop;
    });

    container.innerHTML = groupQueueItems(items)
        .map(segment => segment.playlistId ? renderPlaylistGroup(segment) : renderSingleJob(segment.item))
        .join('');

    container.querySelectorAll('[data-group-list]').forEach(el => {
        if (scrollPositions[el.dataset.groupList] !== undefined) {
            el.scrollTop = scrollPositions[el.dataset.groupList];
        }
    });
}

// Library
async function loadLibrary() {
    try {
        const data = await fetchApi('videos');
        videos = data.videos || [];
        renderLibrary();
    } catch (error) {
        console.error('Failed to load library:', error);
    }
}

function renderTypeCell(video) {
    if (video.type === 'playlist') {
        const content = video.content_format ? ` · ${escapeHtml(video.content_format.toUpperCase())}` : '';
        return `<span class="video-type playlist">${ICONS.playlist} ZIP${content}</span>`;
    }
    const type = video.type === 'audio' ? 'audio' : 'video';
    return `<span class="video-type ${type}">${ICONS[type]} ${escapeHtml(String(video.format || '').toUpperCase())}</span>`;
}

function renderLibrary() {
    const tbody = document.getElementById('libraryBody');
    const searchFilter = document.getElementById('searchFilter').value.toLowerCase();
    const typeFilter = document.getElementById('typeFilter').value;

    let filtered = videos.filter(video => {
        const matchesSearch = String(video.title || '').toLowerCase().includes(searchFilter);
        const matchesType = !typeFilter || video.type === typeFilter;
        return matchesSearch && matchesType;
    });

    // Sort
    filtered.sort((a, b) => {
        let aVal = a[sortColumn];
        let bVal = b[sortColumn];

        if (sortColumn === 'created_at') {
            aVal = new Date(aVal).getTime();
            bVal = new Date(bVal).getTime();
        } else if (sortColumn === 'size') {
            aVal = aVal || 0;
            bVal = bVal || 0;
        } else {
            aVal = (aVal || '').toString().toLowerCase();
            bVal = (bVal || '').toString().toLowerCase();
        }

        if (sortDirection === 'asc') {
            return aVal > bVal ? 1 : -1;
        } else {
            return aVal < bVal ? 1 : -1;
        }
    });

    // Update sort indicators
    document.querySelectorAll('.library-table th').forEach(th => {
        th.classList.remove('sorted');
        if (th.dataset.sort === sortColumn) {
            th.classList.add('sorted');
            th.querySelector('.sort-icon').textContent = sortDirection === 'asc' ? '↑' : '↓';
        } else if (th.querySelector('.sort-icon')) {
            th.querySelector('.sort-icon').textContent = '↕';
        }
    });

    if (filtered.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5">
                    <div class="library-empty">
                        <svg viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-1 9h-4v4h-2v-4H9V9h4V5h2v4h4v2z"/></svg>
                        <p>No videos in library</p>
                    </div>
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = filtered.map(video => {
        const itemCount = video.type === 'playlist'
            ? `<span class="video-subtitle">${Number(video.items) || 0}${video.total && video.total !== video.items ? ' of ' + Number(video.total) : ''} items</span>`
            : '';
        return `
        <tr>
            <td>
                <div class="video-title" title="${escapeHtml(video.title)}">${escapeHtml(video.title)}</div>
                ${itemCount}
            </td>
            <td>${renderTypeCell(video)}</td>
            <td>
                <span class="video-size">${formatSize(video.size)}</span>
            </td>
            <td>
                <span class="video-date">${formatDate(video.created_at)}</span>
            </td>
            <td>
                <div class="video-actions">
                    <button class="action-btn" data-download-file="${escapeHtml(video.filename)}">
                        ${ICONS.download}
                        Download
                    </button>
                    <button class="action-btn delete" data-delete-id="${escapeHtml(video.id)}">
                        ${ICONS.delete}
                        Delete
                    </button>
                </div>
            </td>
        </tr>
    `;
    }).join('');
}

// Delete Modal
function showDeleteModal(videoId) {
    deleteVideoId = videoId;
    document.getElementById('deleteModal').classList.add('active');
}

function closeDeleteModal() {
    deleteVideoId = null;
    document.getElementById('deleteModal').classList.remove('active');
}

async function confirmDelete() {
    if (!deleteVideoId) return;

    try {
        const data = await fetchApi('videos', {
            method: 'DELETE',
            params: { id: deleteVideoId }
        });

        if (data.success) {
            showToast('Deleted from library', 'success');
            await loadLibrary();
        } else {
            throw new Error(data.message || 'Delete failed');
        }
    } catch (error) {
        showToast('Error: ' + error.message, 'error');
    } finally {
        closeDeleteModal();
    }
}

// Toast
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        ${type === 'success' ?
            '<svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>' :
            '<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>'
        }
        <span>${escapeHtml(message)}</span>
    `;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(20px)';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// Utilities
/** Escapes text for HTML content and quoted attribute values. */
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatSize(bytes) {
    if (!bytes) return '-';
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), sizes.length - 1);
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i];
}

function formatDuration(seconds) {
    const total = Math.round(Number(seconds));
    if (!Number.isFinite(total) || total <= 0) return 'unknown';
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    return h ? `${h} h ${m} min` : m ? `${m} min ${s} s` : `${s} s`;
}

function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Cancel download (a single job, or a whole playlist)
async function cancelDownload(body) {
    try {
        const data = await fetchApi('cancel', {
            method: 'POST',
            body
        });

        if (data.success) {
            showToast(data.message, 'success');
            await updateQueueStatus();
        } else {
            throw new Error(data.message || 'Cancel failed');
        }
    } catch (error) {
        showToast('Error: ' + error.message, 'error');
    }
}

// Download file - use direct link for better large file handling
function downloadFile(filename) {
    // Log the file download (fire-and-forget; nginx serves the file directly)
    fetch(`${API_URL}?action=file_serve`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ filename })
    }).catch(() => {});

    const link = document.createElement('a');
    link.href = `/download/${encodeURIComponent(filename)}`;
    link.download = filename;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    showToast('Download started...', 'success');
}

// Event Listeners Setup
function setupEventListeners() {
    // Download form
    document.getElementById('urlInput').addEventListener('input', updatePlaylistToggle);

    document.getElementById('downloadForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const url = document.getElementById('urlInput').value.trim();
        const format = document.querySelector('input[name="format"]:checked').value;
        const playlist = document.getElementById('playlistCheckbox').checked;

        if (!url) {
            showToast('Please enter a YouTube URL', 'error');
            return;
        }

        const btn = document.getElementById('downloadBtn');
        btn.disabled = true;
        btn.dataset.busy = '1';
        const setButtonLabel = label => {
            btn.innerHTML = `<div class="spinner"></div><span>${escapeHtml(label)}</span>`;
        };

        const added = await startDownload(url, format, playlist, setButtonLabel);

        if (added) {
            document.getElementById('urlInput').value = '';
            updatePlaylistToggle();
        }
        delete btn.dataset.busy;
        updateDownloadButton();
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg><span>Download</span>';
    });

    // Large download confirmation
    document.getElementById('confirmDownloadBtn').addEventListener('click', () => closeConfirmDownload(true));
    document.getElementById('cancelDownloadBtn').addEventListener('click', () => closeConfirmDownload(false));
    document.getElementById('confirmDownloadModal').addEventListener('click', (e) => {
        if (e.target.classList.contains('modal-overlay')) {
            closeConfirmDownload(false);
        }
    });

    // Update button
    document.getElementById('updateBtn').addEventListener('click', updateYtDlp);

    // Delete confirmation
    document.getElementById('confirmDeleteBtn').addEventListener('click', confirmDelete);
    document.getElementById('cancelDeleteBtn').addEventListener('click', closeDeleteModal);

    // Queue actions (delegated: the queue is re-rendered on every poll)
    document.getElementById('queueContainer').addEventListener('click', (e) => {
        const btn = e.target.closest('button');
        if (!btn) return;
        if (btn.dataset.cancelPlaylist) {
            cancelDownload({ playlist_id: btn.dataset.cancelPlaylist });
        } else if (btn.dataset.cancelId) {
            cancelDownload({ id: btn.dataset.cancelId });
        }
    });

    // Library actions (delegated)
    document.getElementById('libraryBody').addEventListener('click', (e) => {
        const btn = e.target.closest('button');
        if (!btn) return;
        if (btn.dataset.downloadFile) {
            downloadFile(btn.dataset.downloadFile);
        } else if (btn.dataset.deleteId) {
            showDeleteModal(btn.dataset.deleteId);
        }
    });

    // Filters
    document.getElementById('searchFilter').addEventListener('input', renderLibrary);
    document.getElementById('typeFilter').addEventListener('change', renderLibrary);

    // Table sorting
    document.querySelectorAll('.library-table th[data-sort]').forEach(th => {
        th.addEventListener('click', () => {
            const column = th.dataset.sort;
            if (sortColumn === column) {
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                sortColumn = column;
                sortDirection = 'desc';
            }
            renderLibrary();
        });
    });

    // Close modal on overlay click
    document.getElementById('deleteModal').addEventListener('click', (e) => {
        if (e.target.classList.contains('modal-overlay')) {
            closeDeleteModal();
        }
    });
}

// Initialize
async function init() {
    setupEventListeners();
    await loadCurrentUser();
    await checkVersion();
    await loadLibrary();
    await updateQueueStatus();

    // Poll for updates
    setInterval(async () => {
        await updateQueueStatus();
        await loadLibrary();
    }, 2000);
}

// Start the app when DOM is ready
document.addEventListener('DOMContentLoaded', init);
