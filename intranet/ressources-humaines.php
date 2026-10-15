<?php
/**
 * Ressources humaines : annuaire des personnes, filtrable par entité, type et recherche.
 * La création et la modification des personnes se font dans Administration › Utilisateurs.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user  = require_login();
$costs = sees_costs($user);

$filtreEntite = (int)($_GET['entite'] ?? 0);
$filtreType   = array_key_exists($_GET['type'] ?? '', TYPES_PERSONNE) ? $_GET['type'] : '';
$recherche    = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
$inactifs     = !empty($_GET['inactifs']);

$sql = 'SELECT u.* FROM users u WHERE 1 = 1';
$params = [];
if (!$inactifs) {
    $sql .= ' AND u.actif = 1';
}
if ($filtreEntite && isset(entites()[$filtreEntite])) {
    $ids = entite_descendants($filtreEntite);
    $sql .= ' AND EXISTS (SELECT 1 FROM rattachements r WHERE r.user_id = u.id AND r.entite_id IN ('
          . implode(',', array_fill(0, count($ids), '?')) . '))';
    array_push($params, ...$ids);
}
if ($filtreType !== '') {
    $sql .= ' AND u.type = ?';
    $params[] = $filtreType;
}
if ($recherche !== '') {
    $sql .= " AND (u.prenom || ' ' || u.nom || ' ' || u.fonction || ' ' || u.societe || ' ' || u.competences || ' ' || COALESCE(u.email, '')) LIKE ?";
    $params[] = '%' . $recherche . '%';
}
$st = db()->prepare($sql . ' ORDER BY u.nom COLLATE NOCASE, u.prenom COLLATE NOCASE');
$st->execute($params);
$personnes = $st->fetchAll();

// Rattachements de toutes les personnes affichées : [user_id => [rattachement, …]], principal en premier
$ratt = [];
foreach (db()->query('SELECT * FROM rattachements ORDER BY principal DESC, id')->fetchAll() as $r) {
    $ratt[(int)$r['user_id']][] = $r;
}

page_header('Ressources humaines', 'humaines', $user);
?>
      <div class="toolbar">
        <form method="get" class="filters">
          <label><span class="sr-only">Rechercher</span>
            <input type="search" name="q" value="<?= e($recherche) ?>" placeholder="Nom, fonction, compétence…">
          </label>
          <label><span class="sr-only">Département ou service</span>
            <select name="entite" onchange="this.form.submit()">
              <option value="0">Toute l'organisation</option>
              <?= entite_options($filtreEntite ?: null) ?>
            </select>
          </label>
          <label><span class="sr-only">Type</span>
            <select name="type" onchange="this.form.submit()">
              <option value="">Internes et externes</option>
              <?= options(TYPES_PERSONNE, $filtreType, true) ?>
            </select>
          </label>
          <label class="check"><input type="checkbox" name="inactifs" value="1"<?= $inactifs ? ' checked' : '' ?> onchange="this.form.submit()"> Inclure les inactifs</label>
          <button class="btn btn-ghost" type="submit">Rechercher</button>
        </form>
        <?php if (is_admin($user)): ?>
          <a class="btn btn-primary" href="admin-utilisateurs.php?action=new">+ Nouvelle personne</a>
        <?php endif; ?>
      </div>

      <p class="muted"><?= count($personnes) ?> personne<?= count($personnes) > 1 ? 's' : '' ?></p>

      <?php if (!$personnes): ?>
        <div class="empty card"><p>Aucune personne ne correspond.</p></div>
      <?php else: ?>
        <div class="table-wrap card">
          <table>
            <thead><tr>
              <th>Personne</th><th>Type</th><th>Rattachement</th><th>Contact</th>
              <?php if ($costs): ?><th>TJM</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($personnes as $p):
                $r = $ratt[(int)$p['id']] ?? []; ?>
              <tr<?= $p['actif'] ? '' : ' class="inactive"' ?>>
                <td>
                  <a href="personne.php?id=<?= (int)$p['id'] ?>"><strong><?= e(full_name($p)) ?></strong></a>
                  <?php if ($p['fonction'] !== ''): ?><div class="sub"><?= e($p['fonction']) ?></div><?php endif; ?>
                </td>
                <td>
                  <?= e(TYPES_PERSONNE[$p['type']] ?? '') ?>
                  <?php if ($p['type'] === 'externe' && $p['societe'] !== ''): ?><div class="sub"><?= e($p['societe']) ?></div><?php endif; ?>
                </td>
                <td>
                  <?php if (!$r): ?><span class="muted">—</span><?php else: ?>
                    <?= e(entites()[(int)$r[0]['entite_id']]['nom'] ?? '') ?>
                    <?php if ($r[0]['nature'] === 'interim'): ?> <span class="badge badge-pause">Intérim</span><?php endif; ?>
                    <?php if (count($r) > 1): ?><div class="sub">+ <?= count($r) - 1 ?> autre<?= count($r) > 2 ? 's' : '' ?></div><?php endif; ?>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($p['email']): ?><a href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a><?php endif; ?>
                  <?php if ($p['telephone'] !== ''): ?><div class="sub"><?= e($p['telephone']) ?></div><?php endif; ?>
                </td>
                <?php if ($costs): ?>
                  <td>
                    <?= euros(tjm_personne($p['tjm'] !== null ? (float)$p['tjm'] : null, $p['type'])) ?>
                    <?php if ($p['tjm'] === null || (float)$p['tjm'] <= 0): ?><div class="sub">par défaut</div><?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
<?php
page_footer($user);
