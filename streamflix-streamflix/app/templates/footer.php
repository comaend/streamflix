<?php
// StreamFlix — Footer-Template
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
$year = date('Y');
?>
</main>

<footer class="site-footer">
  <div class="footer-inner">
    <span>&copy; <?= Security::sanitize($year) ?> StreamFlix — Selbst gehostete Streaming-Plattform</span>
    <span class="footer-right">
      <a href="https://github.com/comaend/streamflix" target="_blank" rel="noopener">GitHub</a>
      <a href="/admin/">Admin-Center</a>
    </span>
  </div>
</footer>

<script src="/assets/js/app.js"></script>
<?php if (isset($extraJs)): ?>
    <script src="/assets/js/<?= Security::sanitize($extraJs) ?>"></script>
<?php endif; ?>
<script>
  // Profil-Dropdown toggle
  (function(){
    const btn = document.getElementById('profileMenuBtn');
    const dd = document.getElementById('profileDropdown');
    if (btn && dd) {
      btn.addEventListener('click', function(e){
        e.stopPropagation();
        dd.classList.toggle('hidden');
      });
      document.addEventListener('click', function(){
        dd.classList.add('hidden');
      });
    }
  })();
</script>
</body>
</html>
