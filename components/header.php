<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="CivicConnect account - Report & Track Civic Issues">
  <meta name="theme-color" content="#4F46E5">

  <title>CivicConnect</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="<?= htmlspecialchars($urlForAssets . 'css/bootstrap.css', ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" type="text/css" href="<?= htmlspecialchars($urlForAssets . 'css/civic-ui.css', ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" type="text/css" href="<?= htmlspecialchars($urlForAssets . 'css/toast.css', ENT_QUOTES, 'UTF-8') ?>">

  <script src="https://kit.fontawesome.com/dba62debdb.js" crossorigin="anonymous"></script>
</head>

<?php if (!empty($_SESSION['toasts'])): ?>
  <div class="toast-float" aria-live="polite" aria-atomic="true">
    <?php foreach ($_SESSION['toasts'] as $toast): ?>
      <div class="toast toast-<?= $toast['type'] ?>"
        role="alert"
        data-duration="<?= (int) $toast['duration'] ?>">
        <div class="toast-body">
          <?= htmlspecialchars((string) $toast['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php unset($_SESSION['toasts']); ?>
<?php endif; ?>

<script type="text/javascript">
  window.addEventListener('load', () => {
    document.querySelectorAll('.toast').forEach(toast => {
      const duration = parseInt(toast.dataset.duration, 10) || 7000;

      setTimeout(() => {
        toast.classList.add('toast-hide');

        const removeToast = () => toast.remove();

        toast.addEventListener('animationend', removeToast, {
          once: true
        });
        toast.addEventListener('transitionend', removeToast, {
          once: true
        });

        setTimeout(removeToast, 500);
      }, duration);
    });
  });
</script>
