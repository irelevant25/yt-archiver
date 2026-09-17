/**
 * YT Archiver — Users page (administrators only)
 */

async function api(action, options = {}) {
    const init = { method: options.method || 'GET', headers: {} };
    if (init.method === 'POST') {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(options.body || {});
    }
    const res = await fetch(`/api.php?action=${encodeURIComponent(action)}`, init);
    const data = await res.json().catch(() => ({}));
    if (res.status === 401 && data.login_required) {
        window.location.href = '/login.php';
        return new Promise(() => {});
    }
    if (!res.ok) {
        throw new Error(data.error || `Request failed (${res.status})`);
    }
    return data;
}

const OPERATIONS = {
    approve:    { label: 'Approve', className: 'primary' },
    enable:     { label: 'Enable', className: 'primary' },
    disable:    { label: 'Disable', className: 'danger', confirm: 'Disable {email}? They are signed out immediately and cannot sign in until enabled again.' },
    make_admin: { label: 'Make admin', className: '', confirm: 'Make {email} an administrator? Administrators can approve and disable accounts.' },
    make_user:  { label: 'Remove admin', className: '' },
};

// Accounts are never deleted, only disabled
function operationsFor(user) {
    if (user.status === 'pending') return ['approve', 'disable'];
    if (user.status === 'disabled') return ['enable'];
    return [user.role === 'admin' ? 'make_user' : 'make_admin', 'disable'];
}

async function loadUsers() {
    const btn = document.getElementById('refreshBtn');
    btn.classList.add('spinning');
    try {
        const data = await api('users');
        renderUsers(data.users || [], data.me);
    } catch (error) {
        document.getElementById('usersContainer').innerHTML =
            `<div class="logs-empty error"><p>${escapeHtml(error.message)}</p></div>`;
    } finally {
        btn.classList.remove('spinning');
    }
}

function renderUsers(users, me) {
    // Accounts added in advance are not waiting for anything until the person signs in
    const pending = users.filter(u => u.status === 'pending' && !u.invited).length;
    document.getElementById('userCount').textContent =
        `${users.length} ${users.length === 1 ? 'account' : 'accounts'}` + (pending ? ` · ${pending} pending` : '');

    const rows = users.map(user => {
        const label = user.name || user.email;
        const avatar = /^https:\/\//.test(user.picture || '')
            ? `<img src="${escapeHtml(user.picture)}" alt="" referrerpolicy="no-referrer">`
            : escapeHtml(label.charAt(0).toUpperCase());
        const actions = Number(user.id) === Number(me)
            ? '<span class="you">This is you</span>'
            : operationsFor(user).map(op =>
                `<button class="${OPERATIONS[op].className}" data-user-id="${escapeHtml(user.id)}" data-operation="${op}" data-email="${escapeHtml(user.email)}">${OPERATIONS[op].label}</button>`
            ).join('');

        return `<tr>
            <td><div class="user-cell-main"><span class="user-avatar">${avatar}</span>
                <div><strong>${escapeHtml(label)}</strong><span>${escapeHtml(user.name ? user.email : '')}</span>
                ${user.invited ? '<span class="invited">Added in advance · not signed in yet</span>' : ''}</div></div></td>
            <td><span class="badge status-${escapeHtml(user.status)}">${escapeHtml(user.status)}</span></td>
            <td><span class="badge role-${escapeHtml(user.role)}">${escapeHtml(user.role)}</span></td>
            <td class="date-cell" title="${escapeHtml([user.created_by && 'Added by ' + user.created_by, user.approved_by && 'Approved by ' + user.approved_by].filter(Boolean).join(' · '))}">${formatDate(user.created_at)}</td>
            <td class="date-cell">${user.invited ? 'never' : formatDate(user.last_login_at)}</td>
            <td><div class="user-actions">${actions}</div></td>
        </tr>`;
    }).join('');

    document.getElementById('usersContainer').innerHTML = `
        <table class="logs-table">
            <thead><tr><th>Account</th><th>Status</th><th>Role</th><th>Created</th><th>Last sign-in</th><th>Actions</th></tr></thead>
            <tbody>${rows}</tbody>
        </table>`;
}

async function runOperation(button) {
    const { userId, operation, email } = button.dataset;
    const definition = OPERATIONS[operation];
    if (definition.confirm && !window.confirm(definition.confirm.replace('{email}', email))) {
        return;
    }
    button.disabled = true;
    try {
        const data = await api('user', { method: 'POST', body: { id: Number(userId), operation } });
        showToast(data.message || `${definition.label}: ${email}`, 'success');
        await loadUsers();
    } catch (error) {
        showToast(error.message, 'error');
        button.disabled = false;
    }
}

function showToast(message, type) {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `<span>${escapeHtml(message)}</span>`;
    document.getElementById('toastContainer').appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}

function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false,
    });
}

async function addUser(event) {
    event.preventDefault();
    const form = document.getElementById('addUserForm');
    const email = document.getElementById('newUserEmail');
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
        const data = await api('create_user', {
            method: 'POST',
            body: {
                email: email.value.trim(),
                role: document.getElementById('newUserRole').value,
                approved: document.getElementById('newUserApproved').checked,
            },
        });
        showToast(data.message, 'success');
        email.value = '';
        await loadUsers();
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
}

// An administrator account is always approved
function syncApprovedCheckbox() {
    const isAdmin = document.getElementById('newUserRole').value === 'admin';
    const approved = document.getElementById('newUserApproved');
    if (isAdmin) approved.checked = true;
    approved.disabled = isAdmin;
}

document.getElementById('addUserForm').addEventListener('submit', addUser);
document.getElementById('newUserRole').addEventListener('change', syncApprovedCheckbox);
document.getElementById('refreshBtn').addEventListener('click', loadUsers);
document.getElementById('usersContainer').addEventListener('click', (e) => {
    const button = e.target.closest('button[data-operation]');
    if (button) runOperation(button);
});

loadUsers();
