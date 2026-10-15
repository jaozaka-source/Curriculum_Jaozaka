<?php
/**
 * Projets : liste (filtrable par programme et statut), création, modification, suppression.
 * Le détail d'un projet et ses affectations de ressources sont dans projet.php.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user = require_login();

function find_projet(int $id): ?array {
    $st = db()->prepare('SELECT * FROM projets WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Programmes auxquels l'utilisateur peut rattacher un projet : [id => nom]. */
function programmes_rattachables(array $user, ?int $keep): array {
    $sql = 'SELECT id, nom FROM programmes WHERE ' . (is_admin($user) ? '1 = 1' : '(chef_projet_id = ? OR id = ?)') . ' ORDER BY nom';
    $st = db()->prepare($sql);
    $st->execute(is_admin($user) ? [] : [$user['id'], $keep ?? 0]);
    return array_column($st->fetchAll(), 'nom', 'id');
}

// ---------- Actions ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = post_int('id');
    $existing = $id ? find_projet($id) : null;

    if ($id && (!$existing || !can_edit_projet($user, $existing))) {
        flash('Vous ne pouvez pas modifier ce projet.');
        redirect('projets.php');
    }
    if (!$id && !can_create($user)) {
        flash('Votre rôle ne permet pas de créer de projet.');
        redirect('projets.php');
    }

    if (($_POST['action'] ?? '') === 'delete') {
        db()->prepare('DELETE FROM projets WHERE id = ?')->execute([$id]);
        flash('Projet supprimé.');
        redirect('projets.php');
    }

    $programmes  = programmes_rattachables($user, $existing ? (int)$existing['programme_id'] : null);
    $programmeId = post_int('programme_id');
    $chefs       = chefs_list();
    $chefId      = post_id('chef_projet_id');
    if (!$chefId || !isset($chefs[$chefId])) {
        $chefId = $existing ? ($existing['chef_projet_id'] !== null ? (int)$existing['chef_projet_id'] : null) : (int)$user['id'];
    }

    $data = [
        post_str('nom', 160),
        post_str('description', 5000),
        post_statut(),
        max(0, min(100, post_int('avancement'))),
        post_date('date_debut'),
        post_date('date_fin'),
        isset($programmes[$programmeId]) ? $programmeId : null,
        $chefId,
    ];
    if ($data[0] === '') {
        flash('Le nom du projet est obligatoire.');
        redirect('projets.php?action=' . ($id ? 'edit&id=' . $id : 'new'));
    }

    if ($existing) {
        db()->prepare("UPDATE projets SET nom = ?, description = ?, statut = ?, avancement = ?, date_debut = ?,
                       date_fin = ?, programme_id = ?, chef_projet_id = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([...$data, $id]);
        flash('Projet mis à jour.');
    } else {
        db()->prepare('INSERT INTO projets (nom, description, statut, avancement, date_debut, date_fin, programme_id, chef_projet_id)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute($data);
        $id = (int)db()->lastInsertId();
        flash('Projet créé. Vous pouvez maintenant lui affecter des ressources.');
    }
    redirect('projet.php?id=' . $id);
}

// ---------- Formulaire ----------

$action = $_GET['action'] ?? '';
if ($action === 'new' || $action === 'edit') {
    $p = [
        'id' => 0, 'nom' => '', 'description' => '', 'statut' => STATUTS[0], 'avancement' => 0,
        'date_debut' => null, 'date_fin' => null, 'programme_id' => (int)($_GET['programme'] ?? 0),
        'chef_projet_id' => $user['id'],
    ];
    if ($action === 'edit') {
        $p = find_projet((int)($_GET['id'] ?? 0)) ?? redirect('projets.php');
        if (!can_edit_projet($user, $p)) { redirect('projets.php'); }
    } elseif (!can_create($user)) {
        redirect('projets.php');
    }
    $programmes = programmes_rattachables($user, $p['programme_id'] !== null ? (int)$p['programme_id'] : null);
    $back = $p['id'] ? 'projet.php?id=' . (int)$p['id'] : 'projets.php';
    page_header($p['id'] ? 'Modifier le projet' : 'Nouveau projet', 'projets', $user);
    ?>
      <p><a class="back" href="<?= $back ?>">← Retour</a></p>
      <form method="post" class="form card">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <label>Nom du projet
          <input name="nom" required maxlength="160" value="<?= e($p['nom']) ?>">
        </label>
        <div class="row">
          <label>Programme
            <select name="programme_id">
              <option value="0">— Aucun programme —</option>
              <?php foreach ($programmes as $pid => $pnom): ?>
                <option value="<?= (int)$pid ?>"<?= (int)$p['programme_id'] === (int)$pid ? ' selected' : '' ?>><?= e($pnom) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Chef de projet
            <select name="chef_projet_id"><?= user_options(chefs_list(), $p['chef_projet_id'] !== null ? (int)$p['chef_projet_id'] : null, '— Non désigné —') ?></select>
          </label>
        </div>
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
          <a class="btn btn-ghost" href="<?= $back ?>">Annuler</a>
        </div>
      </form>
    <?php
    page_footer($user);
    exit;
}

// ---------- Liste ----------

$filtreProgramme = (int)($_GET['programme'] ?? 0);
$filtreStatut    = in_array($_GET['statut'] ?? '', STATUTS, true) ? $_GET['statut'] : '';

[$cond, $params] = projets_visibles_sql($user);
$sql = "SELECT pr.*, pg.nom AS programme, u.prenom AS chef_prenom, u.nom AS chef_nom
        FROM projets pr
        LEFT JOIN programmes pg ON pg.id = pr.programme_id
        LEFT JOIN users u ON u.id = pr.chef_projet_id
        WHERE $cond";
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

$totaux = totaux_affectations(array_map('intval', array_column($projets, 'id')));

[$pcond, $pparams] = programmes_visibles_sql($user);
$st = db()->prepare("SELECT p.id, p.nom FROM programmes p WHERE $pcond ORDER BY p.nom");
$st->execute($pparams);
$programmesFiltre = array_column($st->fetchAll(), 'nom', 'id');

$today = date('Y-m-d');
$costs = sees_costs($user);

page_header('Projets', 'projets', $user);
?>
      <div class="toolbar">
        <form method="get" class="filters">
          <label><span class="sr-only">Programme</span>
            <select name="programme" onchange="this.form.submit()">
              <option value="0">Tous les programmes</option>
              <option value="-1"<?= $filtreProgramme === -1 ? ' selected' : '' ?>>Sans programme</option>
              <?php foreach ($programmesFiltre as $pid => $pnom): ?>
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
        <?php if (can_create($user)): ?>
          <a class="btn btn-primary" href="projets.php?action=new<?= $filtreProgramme > 0 ? '&amp;programme=' . $filtreProgramme : '' ?>">+ Nouveau projet</a>
        <?php endif; ?>
      </div>

      <?php if (!$projets): ?>
        <div class="empty card">
          <p>Aucun projet<?= ($filtreProgramme || $filtreStatut) ? ' pour ce filtre' : " pour l'instant" ?>.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap card">
          <table>
            <thead>
              <tr>
                <th>Projet</th><th>Chef de projet</th><th>Statut</th><th>Avancement</th><th>Charge</th>
                <?php if ($costs): ?><th>Coût estimé</th><?php endif; ?>
                <th>Fin</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($projets as $p):
                $t = $totaux[(int)$p['id']] ?? ['jh' => 0.0, 'cout' => 0.0, 'a_nommer' => 0];
                $late = $p['date_fin'] && $p['date_fin'] < $today && !in_array($p['statut'], ['Terminé', 'Annulé'], true); ?>
              <tr>
                <td>
                  <a href="projet.php?id=<?= (int)$p['id'] ?>"><strong><?= e($p['nom']) ?></strong></a>
                  <div class="sub"><?= $p['programme'] !== null ? e($p['programme']) : 'Sans programme' ?></div>
                </td>
                <td><?= $p['chef_nom'] !== null ? e(full_name($p, 'chef_')) : '<span class="muted">—</span>' ?></td>
                <td><?= statut_badge($p['statut']) ?></td>
                <td><?= progress((int)$p['avancement']) ?></td>
                <td>
                  <?= jh($t['jh']) ?> J/H
                  <?php if ($t['a_nommer']): ?><div class="sub warn"><?= $t['a_nommer'] ?> à nommer</div><?php endif; ?>
                </td>
                <?php if ($costs): ?><td><?= euros($t['cout']) ?></td><?php endif; ?>
                <td<?= $late ? ' class="late" title="Date de fin dépassée"' : '' ?>><?= date_fr($p['date_fin']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
<?php
page_footer($user);
