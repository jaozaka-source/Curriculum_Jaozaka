<?php
/**
 * Gabarit des pages de l'intranet.
 * page_header($titre, $menuActif, $user) ... contenu ... page_footer($user)
 */

function page_header(string $title, string $active = '', ?array $user = null): void {
    $icons = [
        'recap'        => '<path d="M3 13h8V3H3zm0 8h8v-6H3zm10 0h8V11h-8zm0-18v6h8V3z"/>',
        'programmes'   => '<path d="M4 6h16v4H4zm0 8h7v4H4zm9 0h7v4h-7z"/>',
        'projets'      => '<path d="M3 5h18v3H3zm0 5.5h12v3H3zM3 16h15v3H3z"/>',
        'humaines'     => '<path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm7 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM1 20c0-3.3 3.6-6 8-6s8 2.7 8 6v1H1zm17.5 1v-1c0-1.9-.8-3.6-2.1-4.8 3.4.3 6.6 2 6.6 4.8v1z"/>',
        'materiels'    => '<path d="M3 4h18v6H3zm0 10h18v6H3zm3-7.5h2v1H6zm0 10h2v1H6z"/>',
        'organigramme' => '<path d="M9 2h6v5h-2v3h7v5h2v6h-6v-6h2v-3h-5v3h2v6H9v-6h2v-3H6v3h2v6H2v-6h2v-5h7V7H9z"/>',
        'admin'        => '<path d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.6 7.6 0 0 0-1.7-1L15 3h-4l-.4 2.7a7.6 7.6 0 0 0-1.7 1l-2.5-1-2 3.5L6.6 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1a7.6 7.6 0 0 0 1.7 1L11 21h4l.4-2.7a7.6 7.6 0 0 0 1.7-1l2.5 1 2-3.5zM13 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7z"/>',
    ];
    $sections = [
        '' => [
            'recap'      => ['Récapitulatif', 'recap.php'],
            'programmes' => ['Programmes', 'programmes.php'],
            'projets'    => ['Projets', 'projets.php'],
        ],
        'Ressources' => [
            'humaines'     => ['Humaines', 'ressources-humaines.php'],
            'materiels'    => ['Matériels & services', 'materiels.php'],
            'organigramme' => ['Organigramme', 'organigramme.php'],
        ],
    ];
    if ($user && is_admin($user)) {
        $sections['Administration'] = [
            'admin-utilisateurs' => ['Utilisateurs', 'admin-utilisateurs.php'],
            'admin-organisation' => ['Organisation', 'admin-organisation.php'],
            'admin-profils'      => ['Profils projet', 'admin-profils.php'],
            'admin-categories'   => ['Catégories', 'admin-categories.php'],
            'admin-parametres'   => ['Paramètres', 'admin-parametres.php'],
        ];
    }
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
      <?php foreach ($sections as $heading => $items): ?>
        <?php if ($heading !== ''): ?><p class="menu-heading"><?= e($heading) ?></p><?php endif; ?>
        <?php foreach ($items as $key => [$label, $href]): ?>
          <a href="<?= $href ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
            <svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$key] ?? $icons['admin'] ?></svg><?= e($label) ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="who">
      <strong><?= e(full_name($user)) ?></strong>
      <span><?= e(ROLES[$user['role']] ?? '') ?></span>
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
      // Champs affichés selon un choix : <div data-show-when="nomDuChamp=valeur1,valeur2">
      document.querySelectorAll('[data-show-when]').forEach(function (block) {
        var rule = block.getAttribute('data-show-when').split('='), form = block.closest('form');
        var values = rule[1].split(','), inputs = form.querySelectorAll('[name="' + rule[0] + '"]');
        function sync() {
          var v = '';
          inputs.forEach(function (i) { if (i.type !== 'radio' || i.checked) { v = i.value; } });
          var on = values.indexOf(v) !== -1;
          block.hidden = !on;
          block.querySelectorAll('input, select, textarea').forEach(function (i) { i.disabled = !on; });
        }
        inputs.forEach(function (i) { i.addEventListener('change', sync); });
        sync();
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
