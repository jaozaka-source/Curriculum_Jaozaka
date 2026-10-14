<?php
/**
 * Projets : liste (filtrable par programme et statut), création, modification, suppression.
 * Un projet appartient au chef de projet connecté et, au plus, à un programme.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user = require_login();
$uid  = (int)$user['id'];

function find_projet(int $id, int $uid): ?array {
    $st = db()->prepare('SELECT * FROM projets WHERE id = ? AND owner_id = ?');
    $st->execute([$id, $uid]);
    return $st->fetch() ?: null;
}

/** Programmes du chef de projet, indexés par id. */
$st = db()->prepare('SELECT id, nom FROM programmes WHERE owner_id = ? ORDER BY nom');
$st->execute([$uid]);
$programmes = array_column($st->fetchAll(), 'nom', 'id');

// ---------- Actions ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);

    if (($_POST['action'] ?? '') === 'delete') {
        if (find_projet($id, $uid)) {
            db()->prepare('DELETE FROM projets WHERE id = ? AND owner_id = ?')->execute([$id, $uid]);
            flash('Projet supprimé.');
        }
        redirect('projets.php');
    }

    $programmeId = (int)($_POST['programme_id'] ?? 0);
    $data = [
        'nom'          => post_str('nom', 160),
        'description'  => post_str('description', 5000),
        'statut'       => post_statut(),
        'avancement'   => max(0, min(100, (int)($_POST['avancement'] ?? 0))),
        'date_debut'   => post_date('date_debut'),
        'date_fin'     => post_date('date_fin'),
        'programme_id' => isset($programmes[$programmeId]) ? $programmeId : null,
    ];
    if ($data['nom'] === '') {
        flash('Le nom du projet est obligatoire.');
        redirect('projets.php?action=' . ($id ? 'edit&id=' . $id : 'new'));
    }

    if ($id && find_projet($id, $uid)) {
        db()->prepare("UPDATE projets SET nom = ?, description = ?, statut = ?, avancement = ?, date_debut = ?,
                       date_fin = ?, programme_id = ?, updated_at = datetime('now') WHERE id = ? AND owner_id = ?")
            ->execute([...array_values($data), $id, $uid]);
        flash('Projet mis à jour.');
    } else {
        db()->prepare('INSERT INTO projets (nom, description, statut, avancement, date_debut, date_fin, programme_id, owner_id)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([...array_values($data), $uid]);
        flash('Projet créé.');
    }
    redirect('projets.php');
}

// ---------- Formulaire ----------

$action = $_GET['action'] ?? '';
if ($action === 'new' || $action === 'edit') {
    $p = [
        'id' => 0, 'nom' => '', 'description' => '', 'statut' => STATUTS[0], 'avancement' => 0,
        'date_debut' => null, 'date_fin' => null, 'programme_id' => (int)($_GET['programme'] ?? 0),
    ];
    if ($action === 'edit') {
        $p = find_projet((int)($_GET['id'] ?? 0), $uid) ?? redirect('projets.php');
    }
    page_header($p['id'] ? 'Modifier le projet' : 'Nouveau projet', 'projets', $user);
    ?>
      <p><a class="back" href="projets.php">← Tous les projets</a></p>
      <form method="post" class="form card">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <label>Nom du projet
          <input name="nom" required maxlength="160" value="<?= e($p['nom']) ?>">
        </label>
        <label>Programme
          <select name="programme_id">
            <option value="0">— Aucun programme —</option>
            <?php foreach ($programmes as $pid => $pnom): ?>
              <option value="<?= (int)$pid ?>"<?= (int)$p['programme_id'] === (int)$pid ? ' selected' : '' ?>><?= e($pnom) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Description
          <textarea name="description" rows="4" maxlength="5000"><?= e($p['description']) ?></textarea>
        </label>
        <div class="row">
          <label>Statut
            <select name="statut"><?= statut_options($p['statut']) ?></select>
          </label>
          <label>Avancement (%)
            <input name="avancement" type="number" min="0" max="100" step="5" value="<?= (int)$p['avancement'] ?>">
          </label>
          <label>Date de début
            <input name="date_debut" type="date" value="<?= e($p['date_debut']) ?>">
          </label>
          <label>Date de fin
            <input name="date_fin" type="date" value="<?= e($p['date_fin']) ?>">
          </label>
        </div>
        <div class="actions">
          <button class="btn btn-primary" type="submit">Enregistrer</button>
          <a class="btn btn-ghost" href="projets.php">Annuler</a>
        </div>
      </form>
    <?php
    page_footer($user);
    exit;
}

// ---------- Liste ----------

$filtreProgramme = (int)($_GET['programme'] ?? 0);
$filtreStatut    = in_array($_GET['statut'] ?? '', STATUTS, true) ? $_GET['statut'] : '';

$sql    = 'SELECT pr.*, pg.nom AS programme FROM projets pr
           LEFT JOIN programmes pg ON pg.id = pr.programme_id
           WHERE pr.owner_id = ?';
$params = [$uid];
if ($filtreProgramme === -1) {
    $sql .= ' AND pr.programme_id IS NULL';
} elseif ($filtreProgramme > 0) {
    $sql .= ' AND pr.programme_id = ?';
    $params[] = $filtreProgramme;
}
if ($filtreStatut !== '') {
    $sql .= ' AND pr.statut = ?';
    $params[] = $filtreStatut;
}
$sql .= " ORDER BY CASE pr.statut WHEN 'En cours' THEN 0 WHEN 'À cadrer' THEN 1 WHEN 'En pause' THEN 2 ELSE 3 END,
          pr.date_fin IS NULL, pr.date_fin, pr.nom";
$st = db()->prepare($sql);
$st->execute($params);
$projets = $st->fetchAll();

$today = date('Y-m-d');

page_header('Projets', 'projets', $user);
?>
      <div class="toolbar">
        <form method="get" class="filters">
          <label><span class="sr-only">Programme</span>
            <select name="programme" onchange="this.form.submit()">
              <option value="0">Tous les programmes</option>
              <option value="-1"<?= $filtreProgramme === -1 ? ' selected' : '' ?>>Sans programme</option>
              <?php foreach ($programmes as $pid => $pnom): ?>
                <option value="<?= (int)$pid ?>"<?= $filtreProgramme === (int)$pid ? ' selected' : '' ?>><?= e($pnom) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><span class="sr-only">Statut</span>
            <select name="statut" onchange="this.form.submit()">
              <option value="">Tous les statuts</option>
              <?= statut_options($filtreStatut) ?>
            </select>
          </label>
          <noscript><button class="btn btn-ghost" type="submit">Filtrer</button></noscript>
        </form>
        <a class="btn btn-primary" href="projets.php?action=new<?= $filtreProgramme > 0 ? '&amp;programme=' . $filtreProgramme : '' ?>">+ Nouveau projet</a>
      </div>

      <?php if (!$projets): ?>
        <div class="empty card">
          <p>Aucun projet<?= ($filtreProgramme || $filtreStatut) ? ' pour ce filtre' : " pour l'instant" ?>.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap card">
          <table>
            <thead>
              <tr><th>Projet</th><th>Programme</th><th>Statut</th><th>Avancement</th><th>Début</th><th>Fin</th><th><span class="sr-only">Actions</span></th></tr>
            </thead>
            <tbody>
            <?php foreach ($projets as $p):
                $late = $p['date_fin'] && $p['date_fin'] < $today && !in_array($p['statut'], ['Terminé', 'Annulé'], true); ?>
              <tr>
                <td>
                  <strong><?= e($p['nom']) ?></strong>
                  <?php if ($p['description'] !== ''): ?><div class="sub"><?= e(mb_strimwidth($p['description'], 0, 110, '…')) ?></div><?php endif; ?>
                </td>
                <td><?= $p['programme'] !== null ? e($p['programme']) : '<span class="muted">—</span>' ?></td>
                <td><?= statut_badge($p['statut']) ?></td>
                <td><?= progress((int)$p['avancement']) ?></td>
                <td><?= date_fr($p['date_debut']) ?></td>
                <td<?= $late ? ' class="late" title="Date de fin dépassée"' : '' ?>><?= date_fr($p['date_fin']) ?></td>
                <td class="row-actions">
                  <a href="projets.php?action=edit&amp;id=<?= (int)$p['id'] ?>">Modifier</a>
                  <form method="post" data-confirm="Supprimer définitivement ce projet ?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button class="link danger" type="submit">Supprimer</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
<?php
page_footer($user);
