// sw.js — Service Worker
const CACHE = 'civicconnect-v1';
const OFFLINE_QUEUE_KEY = 'offline_submissions';

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
            await fetch('/api/issues/submit', { method: 'POST', body: item.formData });
            await db.delete('offline_queue', item.id);
        } catch {} // Keep in queue, retry next sync
    }
}


// After failed submission in citizen report.js:
if ('serviceWorker' in navigator && 'SyncManager' in window) {
    const reg = await navigator.serviceWorker.ready;
    await reg.sync.register('sync-issues');
    showToast('Issue saved offline. Will auto-upload when connected.', 'info');
}