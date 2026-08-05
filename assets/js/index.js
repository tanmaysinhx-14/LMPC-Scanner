// In any page's <script> block, read and render:
const toast = <?= json_encode(consumeToast()) ?>;
if (toast) showToast(toast.message, toast.type, toast.duration);