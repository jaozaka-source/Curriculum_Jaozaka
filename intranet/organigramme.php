<?php
/**
 * Organigramme : arbre des départements et services, avec responsables, adjoints et membres.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user = require_login();

$all      = entites();
$children = entites_children();

// Membres de chaque entité (personnes actives)
$membres = [];
$rows = db()->query("SELECT r.entite_id, r.nature, r.date_fin, u.id, u.prenom, u.nom, u.fonction
                     FROM rattachements r JOIN users u ON u.id = r.user_id
                     WHERE u.actif = 1 ORDER BY u.nom COLLATE NOCASE, u.prenom COLLATE NOCASE")->fetchAll();
foreach ($rows as $m) { $membres[(int)$m['entite_id']][] = $m; }

/** Effectif total (personnes distinctes) d'une entité et de ses descendantes. */
function effectif_total(int $id, array $membres): int {
    $ids = [];
    foreach (entite_descendants($id) as $eid) {
        foreach ($membres[$eid] ?? [] as $m) { $ids[(int)$m['id']] = true; }
    }
    return count($ids);
}

function render_node(int $id, array $all, array $children, array $membres, int $depth): void {
    $e = $all[$id];
    $kids = $children[$id] ?? [];
    $mem = $membres[$id] ?? [];
    $total = effectif_total($id, $membres);
    $resp = $e['resp_nom'] !== null ? full_name($e, 'resp_') : null;
    $adj  = $e['adj_nom'] !== null ? full_name($e, 'adj_') : null;
    ?>
    <li class="org-item">
      <details class="org-node org-<?= e($e['type']) ?><?= $e['is_root'] ? ' org-root' : '' ?>"<?= $depth < 2 ? ' open' : '' ?>>
        <summary>
          <span class="org-title">
            <span class="org-kind"><?= e(entite_type_label($e['type'])) ?><?= $e['code'] !== '' ? ' · ' . e($e['code']) : '' ?></span>
            <strong><?= e($e['nom']) ?></strong>
          </span>
          <span class="org-meta">
            <?php if ($resp): ?><span class="org-resp"><b>Resp.</b> <a href="personne.php?id=<?= (int)$e['responsable_id'] ?>"><?= e($resp) ?></a></span><?php endif; ?>
            <?php if ($adj): ?><span class="org-resp"><b>Adj.</b> <a href="personne.php?id=<?= (int)$e['adjoint_id'] ?>"><?= e($adj) ?></a></span><?php endif; ?>
            <span class="org-count" title="Effectif, sous-entités comprises"><?= $total ?> pers.</span>
          </span>
        </summary>
        <?php if ($mem): ?>
          <ul class="org-members">
            <?php foreach ($mem as $m): ?>
              <li>
                <a href="personne.php?id=<?= (int)$m['id'] ?>"><?= e(full_name($m)) ?></a>
                <?php if ($m['fonction'] !== ''): ?><span class="sub"><?= e($m['fonction']) ?></span><?php endif; ?>
                <?php if ($m['nature'] === 'interim'): ?><span class="badge badge-pause">Intérim<?= $m['date_fin'] ? ' → ' . date_fr($m['date_fin']) : '' ?></span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($kids): ?>
          <ul class="org-children">
            <?php foreach ($kids as $k) { render_node($k, $all, $children, $membres, $depth + 1); } ?>
          </ul>
        <?php endif; ?>
      </details>
    </li>
    <?php
}

page_header('Organigramme', 'organigramme', $user);
?>
      <div class="toolbar">
        <p class="muted">Cliquez sur un département ou un service pour le déplier et voir ses membres.</p>
        <span class="actions">
          <button class="btn btn-ghost" type="button" data-org="open">Tout déplier</button>
          <button class="btn btn-ghost" type="button" data-org="close">Tout replier</button>
          <?php if (is_admin($user)): ?><a class="btn btn-primary" href="admin-organisation.php">Gérer l'organisation</a><?php endif; ?>
        </span>
      </div>

      <div class="card org-wrap">
        <ul class="org-tree">
          <?php render_node(entite_root_id(), $all, $children, $membres, 0); ?>
        </ul>
      </div>

      <script>
        document.querySelectorAll('[data-org]').forEach(function (b) {
          b.addEventListener('click', function () {
            var open = b.getAttribute('data-org') === 'open';
            document.querySelectorAll('.org-node').forEach(function (d) { d.open = open; });
          });
        });
      </script>
<?php
page_footer($user);
