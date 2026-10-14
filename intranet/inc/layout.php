<?php
/**
 * Gabarit des pages de l'intranet.
 * page_header($titre, $menuActif) ... contenu ... page_footer()
 */

function page_header(string $title, string $active = '', ?array $user = null): void {
    $menu = [
        'recap'      => ['Récapitulatif', 'recap.php',
            '<path d="M3 13h8V3H3zm0 8h8v-6H3zm10 0h8V11h-8zm0-18v6h8V3z"/>'],
        'programmes' => ['Programmes', 'programmes.php',
            '<path d="M4 6h16v4H4zm0 8h7v4H4zm9 0h7v4h-7z"/>'],
        'projets'    => ['Projets', 'projets.php',
            '<path d="M3 5h18v3H3zm0 5.5h12v3H3zM3 16h15v3H3z"/>'],
    ];
    $msg = flash();
    ?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?> — Intranet</title>
  <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="assets/intranet.css">
</head>
<body class="<?= $user ? 'app' : 'auth' ?>">
<?php if ($user): ?>
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="recap.php">
      <span class="brand-mark">JR</span>
      <span class="brand-name">Intranet<small>Suivi de projets</small></span>
    </a>
    <nav class="menu" aria-label="Menu principal">
      <?php foreach ($menu as $key => [$label, $href, $icon]): ?>
        <a href="<?= $href ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
          <svg viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg><?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="who">
      <strong><?= e($user['nom']) ?></strong>
      <span><?= e($user['email']) ?></span>
      <form method="post" action="logout.php">
        <?= csrf_field() ?>
        <button class="link" type="submit">Se déconnecter</button>
      </form>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="menu-toggle" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="Ouvrir le menu">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v2H3zm0 5h18v2H3zm0 5h18v2H3z"/></svg>
      </button>
      <h1><?= e($title) ?></h1>
    </header>
    <main class="content">
      <?php if ($msg): ?><p class="flash" role="status"><?= e($msg) ?></p><?php endif; ?>
<?php else: ?>
  <main class="auth-box">
    <a class="brand" href="../index.html">
      <span class="brand-mark">JR</span>
      <span class="brand-name">Intranet<small>Suivi de projets</small></span>
    </a>
    <?php if ($msg): ?><p class="flash" role="status"><?= e($msg) ?></p><?php endif; ?>
<?php endif;
}

function page_footer(?array $user = null): void {
    if ($user): ?>
    </main>
  </div>
  <script>
    (function () {
      var btn = document.querySelector('.menu-toggle'), bar = document.getElementById('sidebar');
      btn.addEventListener('click', function () {
        var open = bar.classList.toggle('open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      document.querySelectorAll('form[data-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (ev) {
          if (!confirm(f.getAttribute('data-confirm'))) { ev.preventDefault(); }
        });
      });
    })();
  </script>
<?php else: ?>
  </main>
<?php endif; ?>
</body>
</html>
<?php
}
