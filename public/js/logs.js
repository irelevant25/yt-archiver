/**
 * YT Archiver — Logs Page
 */

let allLogs = [];
let autoRefreshTimer = null;

const ACTION_COLORS = {
    download: 'action-download',
    cancel:   'action-cancel',
    update:   'action-update',
    serve:    'action-serve',
    process:  'action-process',
    logs:     'action-logs',
};

const METHOD_COLORS = {
    GET:    'method-get',
    POST:   'method-post',
    DELETE: 'method-delete',
};

async function loadLogs() {
    const btn = document.getElementById('refreshBtn');
    btn.classList.add('spinning');

    try {
        const res = await fetch('/api.php?action=logs&limit=1000');
        const data = await res.json();
        allLogs = data.logs || [];
        renderLogs();
    } catch (err) {
        console.error('Failed to load logs:', err);
        document.getElementById('logsContainer').innerHTML = `
            <div class="logs-empty error">
                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                <p>Failed to load logs. Check the API.</p>
            </div>`;
    } finally {
        btn.classList.remove('spinning');
    }
}

function getFilters() {
    return {
        search: document.getElementById('searchFilter').value.toLowerCase().trim(),
        action: document.getElementById('actionFilter').value,
        method: document.getElementById('methodFilter').value,
    };
}

function filterLogs(logs) {
    const { search, action, method } = getFilters();
    return logs.filter(log => {
        if (action && log.action !== action) return false;
        if (method && log.method !== method) return false;
        if (search && !log.action.toLowerCase().includes(search) && !log.body.toLowerCase().includes(search)) return false;
        return true;
    });
}

function formatTimestamp(ts) {
    try {
        const d = new Date(ts);
        return d.toLocaleString(undefined, {
            year: 'numeric', month: 'short', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false,
        });
    } catch {
        return ts;
    }
}

function formatBody(raw) {
    if (!raw || raw === '') return '<span class="body-empty">—</span>';
    try {
        const parsed = JSON.parse(raw);
        const pretty = JSON.stringify(parsed, null, 2);
        // Truncate for table cell
        const short = pretty.length > 80 ? pretty.slice(0, 77) + '…' : pretty;
        return `<span class="body-preview" title="Click to expand">${escapeHtml(short)}</span>`;
    } catch {
        const short = raw.length > 80 ? raw.slice(0, 77) + '…' : raw;
        return `<span class="body-preview">${escapeHtml(short)}</span>`;
    }
}

function escapeHtml(str) {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function openBodyModal(raw) {
    if (!raw || raw === '') return;
    let display;
    try {
        display = JSON.stringify(JSON.parse(raw), null, 2);
    } catch {
        display = raw;
    }
    document.getElementById('bodyContent').textContent = display;
    document.getElementById('bodyModal').classList.add('active');
}

function closeBodyModal(event) {
    if (event && event.target !== document.getElementById('bodyModal')) return;
    document.getElementById('bodyModal').classList.remove('active');
    document.getElementById('bodyContent').textContent = '';
}

function renderLogs() {
    const filtered = filterLogs(allLogs);
    const container = document.getElementById('logsContainer');
    const countEl = document.getElementById('logCount');
    const total = allLogs.length;
    const shown = filtered.length;

    countEl.textContent = shown === total
        ? `${total} ${total === 1 ? 'entry' : 'entries'}`
        : `${shown} of ${total} entries`;

    if (filtered.length === 0) {
        container.innerHTML = `
            <div class="logs-empty">
                <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                <p>${allLogs.length === 0 ? 'No log entries yet.' : 'No entries match the current filters.'}</p>
            </div>`;
        return;
    }

    const rows = filtered.map((log, i) => {
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
                    <th class="th-body">Body</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>`;

    // Attach click handlers for body cells
    container.querySelectorAll('.body-cell.clickable').forEach(cell => {
        cell.addEventListener('click', () => {
            const log = filtered[parseInt(cell.dataset.idx)];
            openBodyModal(log.body);
        });
    });
}

function clearFilters() {
    document.getElementById('searchFilter').value = '';
    document.getElementById('actionFilter').value = '';
    document.getElementById('methodFilter').value = '';
    renderLogs();
}

// Auto-refresh
document.getElementById('autoRefresh').addEventListener('change', function () {
    if (this.checked) {
        autoRefreshTimer = setInterval(loadLogs, 5000);
    } else {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
    }
});

// Filter listeners — live re-render (no network call)
['searchFilter', 'actionFilter', 'methodFilter'].forEach(id => {
    document.getElementById(id).addEventListener('input', renderLogs);
    document.getElementById(id).addEventListener('change', renderLogs);
});

// Close body modal on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.getElementById('bodyModal').classList.remove('active');
});

// Initial load
loadLogs();
