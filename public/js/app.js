/**
 * YT Archiver - Main Application JavaScript
 */

// State
let videos = [];
let sortColumn = 'created_at';
let sortDirection = 'desc';
let deleteVideoId = null;

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

    const fetchOptions = {
        method: options.method || 'GET',
        headers: options.headers || {}
    };

    if (options.body) {
        fetchOptions.headers['Content-Type'] = 'application/json';
        fetchOptions.body = JSON.stringify(options.body);
    }

    const response = await fetch(url, fetchOptions);
    return response.json();
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
        showToast('yt-dlp updated to ' + data.version, 'success');
        await checkVersion();
    } catch (error) {
        showToast('Update failed: ' + error.message, 'error');
    } finally {
        btn.innerHTML = originalHtml;
    }
}

// Download
async function startDownload(url, format) {
    try {
        const data = await fetchApi('download', {
            method: 'POST',
            body: { url, format }
        });
        
        if (data.success) {
            showToast('Added to download queue', 'success');
            await updateQueueStatus();
        } else {
            throw new Error(data.error || 'Download failed');
        }
    } catch (error) {
        showToast('Error: ' + error.message, 'error');
    }
}

// Queue Status
async function updateQueueStatus() {
    try {
        const data = await fetchApi('status');
        renderQueue(data);
    } catch (error) {
        console.error('Failed to update queue:', error);
    }
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
    
    container.innerHTML = items.map(item => {
        const isActive = item.active;
        const progress = isActive && item.progress ? item.progress : null;
        const percent = progress ? progress.percent : 0;
        const status = progress ? progress.status : 'queued';
        const title = progress && progress.title ? progress.title : item.url;
        
        return `
        <div class="queue-item ${isActive ? 'active' : ''}">
            <div class="queue-item-header">
                <div class="queue-item-title">
                    ${escapeHtml(title)}
                    <span class="badge ${item.format}">${item.format.toUpperCase()}</span>
                </div>
                <div class="queue-item-actions">
                    <span class="queue-item-status">${isActive ? status : 'Queued'}</span>
                    <button class="cancel-btn" onclick="cancelDownload('${item.id}')" title="Cancel">
                        <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                    </button>
                </div>
            </div>
            ${isActive ? `
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${percent}%"></div>
                </div>
            ` : ''}
        </div>
    `}).join('');
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

function renderLibrary() {
    const tbody = document.getElementById('libraryBody');
    const searchFilter = document.getElementById('searchFilter').value.toLowerCase();
    const typeFilter = document.getElementById('typeFilter').value;
    
    let filtered = videos.filter(video => {
        const matchesSearch = video.title.toLowerCase().includes(searchFilter);
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
    
    tbody.innerHTML = filtered.map(video => `
        <tr>
            <td>
                <div class="video-title" title="${escapeHtml(video.title)}">${escapeHtml(video.title)}</div>
            </td>
            <td>
                <span class="video-type ${video.type}">
                    ${video.type === 'video' ? 
                        '<svg viewBox="0 0 24 24"><path d="M18 4l2 4h-3l-2-4h-2l2 4h-3l-2-4H8l2 4H7L5 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4h-4z" fill="currentColor"/></svg>' :
                        '<svg viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z" fill="currentColor"/></svg>'
                    }
                    ${video.format.toUpperCase()}
                </span>
            </td>
            <td>
                <span class="video-size">${formatSize(video.size)}</span>
            </td>
            <td>
                <span class="video-date">${formatDate(video.created_at)}</span>
            </td>
            <td>
                <div class="video-actions">
                    <button class="action-btn" onclick="downloadFile('${escapeHtml(video.filename)}')">
                        <svg viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                        Download
                    </button>
                    <button class="action-btn delete" onclick="showDeleteModal('${video.id}')">
                        <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                        Delete
                    </button>
                </div>
            </td>
        </tr>
    `).join('');
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
            showToast('Video deleted', 'success');
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
function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatSize(bytes) {
    if (!bytes) return '-';
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i];
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

// Cancel download
async function cancelDownload(id) {
    try {
        const data = await fetchApi('cancel', {
            method: 'POST',
            body: { id }
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
    document.getElementById('downloadForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const url = document.getElementById('urlInput').value.trim();
        const format = document.querySelector('input[name="format"]:checked').value;
        
        if (!url) {
            showToast('Please enter a YouTube URL', 'error');
            return;
        }
        
        const btn = document.getElementById('downloadBtn');
        btn.disabled = true;
        btn.innerHTML = '<div class="spinner"></div><span>Adding...</span>';
        
        await startDownload(url, format);
        
        document.getElementById('urlInput').value = '';
        btn.disabled = false;
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg><span>Download</span>';
    });

    // Update button
    document.getElementById('updateBtn').addEventListener('click', updateYtDlp);

    // Delete confirmation
    document.getElementById('confirmDeleteBtn').addEventListener('click', confirmDelete);

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
