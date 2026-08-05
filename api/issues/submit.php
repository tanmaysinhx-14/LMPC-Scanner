// Creates Issue and Triggers AI

<?php 
  // In api/issues/submit.php
  function callAIService(string $imagePath): array {
      $url = 'http://localhost:8000/analyze';
      $ch = curl_init($url);
      
      $postData = ['file' => new CURLFile($imagePath, 'image/jpeg', 'issue.jpg')];
      curl_setopt_array($ch, [
          CURLOPT_POST           => true,
          CURLOPT_POSTFIELDS     => $postData,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_TIMEOUT        => 10,
      ]);
      
      $response = curl_exec($ch);
      $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      
      if ($status !== 200 || !$response) {
          // Graceful fallback: return unknown category, let user confirm manually
          return ['category' => 'unknown', 'severity' => 1, 'confidence' => 0.0, 'is_manipulated' => false];
      }
      
      return json_decode($response, true);
  }

  function handleImageUpload(): string {
    $file = $_FILES['image'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(400, 'Image upload failed or missing');
    }
    
    // Validate MIME type (not just extension)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    
    if (!in_array($mime, $allowed)) {
        jsonResponse(400, 'Invalid image type. Only JPEG, PNG, WebP allowed.');
    }
    
    // Validate file size (max 10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        jsonResponse(400, 'Image exceeds 10MB limit.');
    }
    
    // Generate secure filename
    $ext = match($mime) {
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'
    };
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = __DIR__ . '/../../uploads/' . date('Y/m/d/') . $filename;
    
    // Ensure directory exists
    if (!is_dir(dirname($destPath))) mkdir(dirname($destPath), 0755, true);
    
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        jsonResponse(500, 'Failed to save image.');
    }
    
    return $destPath;
}



// In api/issues/submit.php
function validateGPSCoordinates(float $submittedLat, float $submittedLng, string $imageFile): array {
    
    // Layer 1: EXIF GPS vs submitted GPS
    $exif = @exif_read_data($imageFile);
    if ($exif && isset($exif['GPSLatitude'])) {
        $exifLat = convertExifGPS($exif['GPSLatitude'], $exif['GPSLatitudeRef']);
        $exifLng = convertExifGPS($exif['GPSLongitude'], $exif['GPSLongitudeRef']);
        $distance = haversineDistance($submittedLat, $submittedLng, $exifLat, $exifLng);
        if ($distance > 500) { // >500m discrepancy → flag
            return ['valid' => false, 'reason' => 'GPS mismatch between device and image metadata'];
        }
    }
    
    // Layer 2: Known emulator/fake GPS coordinates
    $fakeCoords = [[0.0, 0.0], [37.4220, -122.0841]]; // Null island, Googleplex
    foreach ($fakeCoords as [$fLat, $fLng]) {
        if (haversineDistance($submittedLat, $submittedLng, $fLat, $fLng) < 100) {
            return ['valid' => false, 'reason' => 'Suspicious coordinate (known emulator location)'];
        }
    }
    
    // Layer 3: Rate limit — max 3 submissions per citizen per ward per hour
    $count = $db->prepare("SELECT COUNT(*) FROM issues 
                           WHERE user_id = ? AND geohash LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $count->execute([$userId, substr($geohash, 0, 5) . '%']);
    if ($count->fetchColumn() >= 3) {
        return ['valid' => false, 'reason' => 'Rate limit: max 3 reports per hour in same area'];
    }
    
    return ['valid' => true, 'reason' => null];
}
?>