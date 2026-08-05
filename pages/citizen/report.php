<script type="text/javascript">
// GPS auto-fetch with graceful fallback
async function fetchGPS() {
    return new Promise((resolve) => {
        if (!navigator.geolocation) {
            resolve({ lat: null, lng: null, source: 'manual' });
            return;
        }
        navigator.geolocation.getCurrentPosition(
            pos => resolve({ 
                lat: pos.coords.latitude, 
                lng: pos.coords.longitude, 
                accuracy: pos.coords.accuracy,
                source: 'gps' 
            }),
            () => resolve({ lat: null, lng: null, source: 'manual' }),
            { timeout: 8000, enableHighAccuracy: true }
        );
    });
}

// Client-side image compression before upload (save bandwidth, faster AI inference)
async function compressImage(file, maxPx = 1024, quality = 0.85) {
    return new Promise(resolve => {
        const canvas = document.createElement('canvas');
        const img = new Image();
        img.onload = () => {
            const scale = Math.min(maxPx / img.width, maxPx / img.height, 1);
            canvas.width = img.width * scale;
            canvas.height = img.height * scale;
            canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(resolve, 'image/jpeg', quality);
        };
        img.src = URL.createObjectURL(file);
    });
}

// On image select: compress → preview → call AI → pre-fill form
document.getElementById('issueImage').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    
    showSkeleton('aiResultCard');
    
    const compressed = await compressImage(file);
    showPreview(compressed);
    
    // Send to PHP API which relays to FastAPI
    const formData = new FormData();
    formData.append('image', compressed, 'issue.jpg');
    
    const res = await fetch('/api/issues/analyze-preview', { method: 'POST', body: formData });
    const result = await res.json();
    
    // Pre-fill form fields
    document.getElementById('category').value = result.data.category;
    document.getElementById('severityDisplay').textContent = result.data.severity;
    document.getElementById('aiConfidence').textContent = 
        `${Math.round(result.data.confidence * 100)}% confidence`;
    
    if (result.data.is_manipulated) {
        showToast('Warning: This image may have been altered.', 'danger', 6000);
    }
    
    hideSkeleton('aiResultCard');
});
</script>