<?php
/**
 * Fiche d'une personne : informations, rattachements, responsabilités et affectations aux projets.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user  = require_login();
$costs = sees_costs($user);

$st = db()->prepare('SELECT * FROM users WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$p = $st->fetch() ?: redirect('ressources-humaines.php');
$id = (int)$p['id'];

$st = db()->prepare('SELECT * FROM rattachements WHERE user_id = ? ORDER BY principal DESC, id');
$st->execute([$id]);
$rattachements = $st->fetchAll();

$responsabilites = array_filter(entites(), fn($e) => (int)$e['responsable_id'] === $id || (int)$e['adjoint_id'] === $id);

// Affectations de la personne, limitées aux projets visibles par l'utilisateur connecté
[$cond, $params] = projets_visibles_sql($user);
$st = db()->prepare("SELECT pr.id FROM projets pr WHERE $cond");
$st->execute($params);
$visibles = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
$affectations = array_values(array_filter(affectations($visibles), fn($a) => $a['kind'] === 'personne' && (int)$a['user_id'] === $id));
$nomsProjets = [];
if ($affectations) {
    $ids = array_unique(array_map(fn($a) => (int)$a['projet_id'], $affectations));
    $st = db()->prepare('SELECT id, nom, statut FROM projets WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute(array_values($ids));
    $nomsProjets = array_column($st->fetchAll(), null, 'id');
}

page_header(full_name($p), 'humaines', $user);
?>
      <p><a class="back" href="ressources-humaines.php">← Annuaire</a></p>

      <section class="card project-head">
        <div class="ph-main">
          <p class="sub">
            <?= e(TYPES_PERSONNE[$p['type']] ?? '') ?><?= $p['type'] === 'externe' && $p['societe'] !== '' ? ' · ' . e($p['societe']) : '' ?>
            <?= $p['actif'] ? '' : ' · <span class="badge badge-annule">Inactif</span>' ?>
          </p>
          <?php if ($p['fonction'] !== ''): ?><p><strong><?= e($p['fonction']) ?></strong></p><?php endif; ?>
          <dl class="facts">
            <div><dt>E-mail</dt><dd><?= $p['email'] ? '<a href="mailto:' . e($p['email']) . '">' . e($p['email']) . '</a>' : '—' ?></dd></div>
            <div><dt>Téléphone</dt><dd><?= $p['telephone'] !== '' ? e($p['telephone']) : '—' ?></dd></div>
            <div><dt>Rôle dans l'intranet</dt><dd><?= e(ROLES[$p['role']] ?? '') ?><?= $p['acces'] ? '' : ' (sans accès)' ?></dd></div>
            <?php if ($costs): ?>
              <div><dt>TJM</dt><dd><?= euros(tjm_personne($p['tjm'] !== null ? (float)$p['tjm'] : null, $p['type'])) ?><?= ($p['tjm'] === null || (float)$p['tjm'] <= 0) ? ' <small>(par défaut)</small>' : '' ?></dd></div>
            <?php endif; ?>
          </dl>
          <?php if ($p['competences'] !== ''): ?><p class="sub">Compétences : <?= e($p['competences']) ?></p><?php endif; ?>
        </div>
        <?php if (is_admin($user)): ?>
          <div class="ph-actions"><a class="btn btn-ghost" href="admin-utilisateurs.php?action=edit&amp;id=<?= $id ?>">Modifier</a></div>
        <?php endif; ?>
      </section>

      <div class="grid-2">
        <section class="card">
          <h2>Rattachements</h2>
          <?php if (!$rattachements): ?>
            <p class="muted">Aucun rattachement.</p>
          <?php else: ?>
            <ul class="list">
              <?php foreach ($rattachements as $r): ?>
                <li>
                  <strong><?= e(entites()[(int)$r['entite_id']]['nom'] ?? '') ?></strong>
                  <span class="sub"><?= e(entite_path((int)$r['entite_id'])) ?></span>
                  <span class="date">
                    <?= $r['principal'] ? '<span class="badge badge-cours">Principal</span> ' : '' ?>
                    <?= $r['nature'] === 'interim' ? '<span class="badge badge-pause">Intérim</span>' : '' ?>
                    <?php if ($r['date_debut'] || $r['date_fin']): ?><small><?= date_fr($r['date_debut']) ?> → <?= date_fr($r['date_fin']) ?></small><?php endif; ?>
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>

        <section class="card">
          <h2>Responsabilités</h2>
          <?php if (!$responsabilites): ?>
            <p class="muted">Aucune responsabilité de département ou de service.</p>
          <?php else: ?>
            <ul class="list">
              <?php foreach ($responsabilites as $e): ?>
                <li>
                  <strong><?= e($e['nom']) ?></strong>
                  <span class="sub"><?= e(entite_type_label($e['type'])) ?></span>
                  <span class="date"><?= (int)$e['responsable_id'] === $id ? 'Responsable' : 'Adjoint' ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>
      </div>

      <section class="card">
        <h2>Affectations aux projets</h2>
        <?php if (!$affectations): ?>
          <p class="muted">Aucune affectation sur les projets que vous pouvez consulter.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Projet</th><th>Profil</th><th>Charge</th><th>Période</th><?php if ($costs): ?><th>Coût</th><?php endif; ?></tr></thead>
              <tbody>
              <?php foreach ($affectations as $a): $pr = $nomsProjets[(int)$a['projet_id']] ?? null; ?>
                <tr>
                  <td><a href="projet.php?id=<?= (int)$a['projet_id'] ?>"><strong><?= e($pr['nom'] ?? '') ?></strong></a>
                    <?php if ($pr): ?><div class="sub"><?= statut_badge($pr['statut']) ?></div><?php endif; ?></td>
                  <td><?= $a['profil'] !== null ? e($a['profil']) : '—' ?></td>
                  <td><?= jh((float)$a['charge_jh']) ?> J/H</td>
                  <td><?= date_fr($a['date_debut']) ?> → <?= date_fr($a['date_fin']) ?></td>
                  <?php if ($costs): ?><td><?= euros($a['cout']) ?></td><?php endif; ?>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
<?php
page_footer($user);
