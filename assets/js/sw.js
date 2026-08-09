// sw.js — Service Worker
const CACHE = 'civicconnect-v2';
const DB_NAME = 'civicconnect-offline';
const DB_VERSION = 1;
const STORE_NAME = 'offline_queue';

self.addEventListener('fetch', event => {
    if (event.request.url.includes('/api/issues/submit') && event.request.method === 'POST') {
        event.respondWith(
            fetch(event.request.clone()).catch(async () => {
                // Network failed → queue in IndexedDB
                const body = await event.request.clone().formData();
                await queueOfflineSubmission(body);
                return new Response(JSON.stringify({
                    status: 202, message: 'Saved offline. Will sync when connected.', data: null
                }), { headers: { 'Content-Type': 'application/json' } });
            })
        );
    }
});

// Background sync when connection restored
self.addEventListener('sync', async event => {
    if (event.tag === 'sync-issues') {
        event.waitUntil(syncQueuedIssues());
    }
});

async function syncQueuedIssues() {
    const db = await openIDB();
    const queued = await db.getAll('offline_queue');
    for (const item of queued) {
        try {
            const formData = new FormData();
            for (const entry of item.entries) formData.append(entry.name, entry.value, entry.filename || undefined);
            const response = await fetch('/api/issues/submit.php', { method: 'POST', body: formData, credentials: 'include' });
            if (!response.ok) throw new Error(`Submission retry failed with ${response.status}`);
            await db.delete('offline_queue', item.id);
        } catch {} // Keep in queue, retry next sync
    }
}

function openIDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onupgradeneeded = () => {
            const database = request.result;
            if (!database.objectStoreNames.contains(STORE_NAME)) database.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
        };
        request.onsuccess = () => {
            const database = request.result;
            resolve({
                getAll: () => new Promise((ok, fail) => { const tx = database.transaction(STORE_NAME, 'readonly'); const get = tx.objectStore(STORE_NAME).getAll(); get.onsuccess = () => ok(get.result); get.onerror = () => fail(get.error); }),
                add: item => new Promise((ok, fail) => { const tx = database.transaction(STORE_NAME, 'readwrite'); const add = tx.objectStore(STORE_NAME).add(item); add.onsuccess = () => ok(add.result); add.onerror = () => fail(add.error); }),
                delete: id => new Promise((ok, fail) => { const tx = database.transaction(STORE_NAME, 'readwrite'); const del = tx.objectStore(STORE_NAME).delete(id); del.onsuccess = () => ok(); del.onerror = () => fail(del.error); })
            });
        };
        request.onerror = () => reject(request.error);
    });
}

async function queueOfflineSubmission(formData) {
    const entries = [];
    for (const [name, value] of formData.entries()) {
        entries.push({ name, value, filename: value instanceof File ? value.name : undefined });
    }
    await (await openIDB()).add({ entries, queued_at: Date.now() });
}

self.addEventListener('online', () => self.registration.sync?.register('sync-issues'));

// After failed submission in citizen report.js:
if ('serviceWorker' in navigator && 'SyncManager' in window) {
    const reg = await navigator.serviceWorker.ready;
    await reg.sync.register('sync-issues');
    showToast('Issue saved offline. Will auto-upload when connected.', 'info');
}
