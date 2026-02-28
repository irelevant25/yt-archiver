/**
 * YT Archiver — Logs Page
 */

// ── State ──────────────────────────────────────────────
let currentPage  = 1;
let totalPages   = 1;
let autoRefreshTimer = null;

const ACTION_COLORS = {
    download:   'action-download',
    cancel:     'action-cancel',
    update:     'action-update',
    serve:      'action-serve',
    file_serve: 'action-serve',
    process:    'action-process',
    logs:       'action-logs',
    videos:     'action-cancel',
};

const METHOD_COLORS = {
    GET:    'method-get',
    POST:   'method-post',
    DELETE: 'method-delete',
};

// ── Password ───────────────────────────────────────────
function getPassword() {
    return sessionStorage.getItem('logs_password') || '';
}

function setPassword(pw) {
    sessionStorage.setItem('logs_password', pw);
}

function authHeaders() {
    const pw = getPassword();
    return pw ? { 'X-Logs-Password': pw } : {};
}

function showPasswordModal(wrongPassword = false) {
    document.getElementById('passwordError').style.display = wrongPassword ? 'block' : 'none';
    document.getElementById('passwordInput').value = '';
    document.getElementById('passwordModal').classList.add('active');
    setTimeout(() => document.getElementById('passwordInput').focus(), 50);
}

function hidePasswordModal() {
    document.getElementById('passwordModal').classList.remove('active');
}

async function submitPassword(e) {
    e.preventDefault();
    const pw = document.getElementById('passwordInput').value;
    setPassword(pw);
    hidePasswordModal();
    await loadLogs();
}

// ── API ────────────────────────────────────────────────
function buildUrl() {
    const params = new URLSearchParams({
        action: 'logs',
        page:   currentPage,
        limit:  document.getElementById('limitSelect').value,
    });
    const search       = document.getElementById('searchFilter').value.trim();
    const filterAction = document.getElementById('actionFilter').value;
    const filterMethod = document.getElementById('methodFilter').value;
    if (search)       params.set('search',        search);
    if (filterAction) params.set('filter_action', filterAction);
    if (filterMethod) params.set('filter_method', filterMethod);
    return `/api.php?${params}`;
}

async function loadLogs() {
    const btn = document.getElementById('refreshBtn');
    btn.classList.add('spinning');

    try {
        const res = await fetch(buildUrl(), { headers: authHeaders() });

        if (res.status === 401) {
            showPasswordModal(getPassword() !== '');
            return;
        }

        if (res.status === 403) {
            showError('Access denied — your IP address is not allowed to view logs.');
            return;
        }

        const data = await res.json();
        if (data.error) {
            showError(data.error);
            return;
        }

        hidePasswordModal();
        renderLogs(data);
    } catch (err) {
        console.error('Failed to load logs:', err);
        showError('Failed to load logs. Check the API.');
    } finally {
        btn.classList.remove('spinning');
    }
}

// ── Render ─────────────────────────────────────────────
function formatBytes(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
}

function renderLogs(data) {
    const { logs, total, page, pages, size } = data;
    currentPage = page;
    totalPages  = pages;

    // Meta
    const countEl = document.getElementById('logCount');
    const sizeEl  = document.getElementById('logSize');
    countEl.textContent = `${total} ${total === 1 ? 'entry' : 'entries'}`;
    sizeEl.textContent  = formatBytes(size);

    // Pagination
    const pagination = document.getElementById('pagination');
    const pageInfo   = document.getElementById('pageInfo');
    const prevBtn    = document.getElementById('prevBtn');
    const nextBtn    = document.getElementById('nextBtn');

    if (pages > 1) {
        pagination.style.display = '';
        pageInfo.textContent     = `Page ${page} of ${pages}`;
        prevBtn.disabled         = page <= 1;
        nextBtn.disabled         = page >= pages;
    } else {
        pagination.style.display = 'none';
    }

    // Table
    const container = document.getElementById('logsContainer');

    if (logs.length === 0) {
        container.innerHTML = `
            <div class="logs-empty">
                <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                <p>${total === 0 ? 'No log entries yet.' : 'No entries match the current filters.'}</p>
            </div>`;
        return;
    }

    const rows = logs.map((log, i) => {
        const actionClass = ACTION_COLORS[log.action] || 'action-default';
        const methodClass = METHOD_COLORS[log.method] || 'method-get';
        const hasBody = log.body && log.body !== '';
        const bodyCell = hasBody
            ? `<td class="body-cell clickable" data-idx="${i}">${formatBody(log.body)}</td>`
            : `<td class="body-cell"><span class="body-empty">—</span></td>`;

        return `<tr>
            <td class="ts-cell"><span class="ts-value">${escapeHtml(formatTimestamp(log.timestamp))}</span></td>
            <td><span class="badge ${actionClass}">${escapeHtml(log.action || '—')}</span></td>
            <td><span class="badge ${methodClass}">${escapeHtml(log.method)}</span></td>
            <td class="ip-cell"><span class="ip-value">${escapeHtml(log.ip || '—')}</span></td>
            ${bodyCell}
        </tr>`;
    }).join('');

    container.innerHTML = `
        <table class="logs-table">
            <thead>
                <tr>
                    <th class="th-ts">Timestamp</th>
                    <th class="th-action">Action</th>
                    <th class="th-method">Method</th>
                    <th class="th-ip">IP</th>
                    <th class="th-body">Body</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>`;

    // Body click handlers
    container.querySelectorAll('.body-cell.clickable').forEach(cell => {
        cell.addEventListener('click', () => {
            openBodyModal(logs[parseInt(cell.dataset.idx)].body);
        });
    });
}

function showError(msg) {
    document.getElementById('logsContainer').innerHTML = `
        <div class="logs-empty error">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
            <p>${escapeHtml(msg)}</p>
        </div>`;
}

// ── Pagination ─────────────────────────────────────────
function goToPage(page) {
    if (page < 1 || page > totalPages) return;
    currentPage = page;
    loadLogs();
}

// ── Filters ────────────────────────────────────────────
function clearFilters() {
    document.getElementById('searchFilter').value  = '';
    document.getElementById('actionFilter').value  = '';
    document.getElementById('methodFilter').value  = '';
    currentPage = 1;
    loadLogs();
}

// All filter/limit changes reset to page 1 and reload
let searchDebounce = null;
document.getElementById('searchFilter').addEventListener('input', () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => { currentPage = 1; loadLogs(); }, 400);
});

['actionFilter', 'methodFilter', 'limitSelect'].forEach(id => {
    document.getElementById(id).addEventListener('change', () => { currentPage = 1; loadLogs(); });
});

// ── Body Modal ─────────────────────────────────────────
function openBodyModal(raw) {
    if (!raw || raw === '') return;
    let display;
    try { display = JSON.stringify(JSON.parse(raw), null, 2); }
    catch { display = raw; }
    document.getElementById('bodyContent').textContent = display;
    document.getElementById('bodyModal').classList.add('active');
}

function closeBodyModal(event) {
    if (event && event.target !== document.getElementById('bodyModal')) return;
    document.getElementById('bodyModal').classList.remove('active');
    document.getElementById('bodyContent').textContent = '';
}

// ── Clear Logs ─────────────────────────────────────────
function confirmClearLogs() {
    document.getElementById('clearModal').classList.add('active');
}

function closeClearModal() {
    document.getElementById('clearModal').classList.remove('active');
}

async function clearLogs() {
    closeClearModal();
    try {
        const res = await fetch('/api.php?action=logs', {
            method: 'DELETE',
            headers: authHeaders(),
        });
        if (res.status === 401) { showPasswordModal(true); return; }
        currentPage = 1;
        await loadLogs();
    } catch (err) {
        console.error('Failed to clear logs:', err);
    }
}

// ── Auto-refresh ───────────────────────────────────────
document.getElementById('autoRefresh').addEventListener('change', function () {
    if (this.checked) {
        autoRefreshTimer = setInterval(loadLogs, 5000);
    } else {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
    }
});

// ── Helpers ────────────────────────────────────────────
function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function formatTimestamp(ts) {
    try {
        return new Date(ts).toLocaleString(undefined, {
            year: 'numeric', month: 'short', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false,
        });
    } catch { return ts; }
}

function formatBody(raw) {
    if (!raw || raw === '') return '<span class="body-empty">—</span>';
    try {
        const pretty = JSON.stringify(JSON.parse(raw), null, 2);
        const short  = pretty.length > 80 ? pretty.slice(0, 77) + '…' : pretty;
        return `<span class="body-preview" title="Click to expand">${escapeHtml(short)}</span>`;
    } catch {
        const short = raw.length > 80 ? raw.slice(0, 77) + '…' : raw;
        return `<span class="body-preview">${escapeHtml(short)}</span>`;
    }
}

// ── Keyboard ───────────────────────────────────────────
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.getElementById('bodyModal').classList.remove('active');
        closeClearModal();
    }
});

// ── Init ───────────────────────────────────────────────
// If we have no password stored, show the modal upfront only if the API demands it.
// We attempt the load and let the 401 handler show the modal if needed.
hidePasswordModal();
loadLogs();
