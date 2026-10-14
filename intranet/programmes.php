<?php
/**
 * Programmes : liste, création, modification, suppression.
 * Un programme regroupe plusieurs projets du chef de projet connecté.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user = require_login();
$uid  = (int)$user['id'];

function find_programme(int $id, int $uid): ?array {
    $st = db()->prepare('SELECT * FROM programmes WHERE id = ? AND owner_id = ?');
    $st->execute([$id, $uid]);
    return $st->fetch() ?: null;
}

// ---------- Actions ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);

    if (($_POST['action'] ?? '') === 'delete') {
        if (find_programme($id, $uid)) {
            db()->prepare('DELETE FROM programmes WHERE id = ? AND owner_id = ?')->execute([$id, $uid]);
            flash('Programme supprimé. Ses projets ont été conservés, sans programme.');
        }
        redirect('programmes.php');
    }

    $data = [
        'nom'         => post_str('nom', 160),
        'description' => post_str('description', 5000),
        'statut'      => post_statut(),
        'date_debut'  => post_date('date_debut'),
        'date_fin'    => post_date('date_fin'),
    ];
    if ($data['nom'] === '') {
        flash('Le nom du programme est obligatoire.');
        redirect('programmes.php?action=' . ($id ? 'edit&id=' . $id : 'new'));
    }

    if ($id && find_programme($id, $uid)) {
        db()->prepare("UPDATE programmes SET nom = ?, description = ?, statut = ?, date_debut = ?, date_fin = ?,
                       updated_at = datetime('now') WHERE id = ? AND owner_id = ?")
            ->execute([...array_values($data), $id, $uid]);
        flash('Programme mis à jour.');
    } else {
        db()->prepare('INSERT INTO programmes (nom, description, statut, date_debut, date_fin, owner_id)
                       VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([...array_values($data), $uid]);
        flash('Programme créé.');
    }
    redirect('programmes.php');
}

// ---------- Formulaire ----------

$action = $_GET['action'] ?? '';
if ($action === 'new' || $action === 'edit') {
    $p = ['id' => 0, 'nom' => '', 'description' => '', 'statut' => STATUTS[0], 'date_debut' => null, 'date_fin' => null];
    if ($action === 'edit') {
        $p = find_programme((int)($_GET['id'] ?? 0), $uid) ?? redirect('programmes.php');
    }
    page_header($p['id'] ? 'Modifier le programme' : 'Nouveau programme', 'programmes', $user);
    ?>
      <p><a class="back" href="programmes.php">← Tous les programmes</a></p>
      <form method="post" class="form card">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <label>Nom du programme
          <input name="nom" required maxlength="160" value="<?= e($p['nom']) ?>">
        </label>
        <label>Description
          <textarea name="description" rows="4" maxlength="5000"><?= e($p['description']) ?></textarea>
        </label>
        <div class="row">
          <label>Statut
            <select name="statut"><?= statut_options($p['statut']) ?></select>
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
          <a class="btn btn-ghost" href="programmes.php">Annuler</a>
        </div>
      </form>
    <?php
    page_footer($user);
    exit;
}

// ---------- Liste ----------

$st = db()->prepare("
    SELECT p.*, COUNT(pr.id) AS nb_projets, COALESCE(ROUND(AVG(pr.avancement)), 0) AS avancement
    FROM programmes p
    LEFT JOIN projets pr ON pr.programme_id = p.id
    WHERE p.owner_id = ?
    GROUP BY p.id
    ORDER BY CASE p.statut WHEN 'En cours' THEN 0 WHEN 'À cadrer' THEN 1 WHEN 'En pause' THEN 2 ELSE 3 END, p.nom
");
$st->execute([$uid]);
$programmes = $st->fetchAll();

page_header('Programmes', 'programmes', $user);
?>
      <div class="toolbar">
        <p class="muted"><?= count($programmes) ?> programme<?= count($programmes) > 1 ? 's' : '' ?></p>
        <a class="btn btn-primary" href="programmes.php?action=new">+ Nouveau programme</a>
      </div>

      <?php if (!$programmes): ?>
        <div class="empty card">
          <p>Aucun programme pour l'instant.</p>
          <p class="muted">Un programme regroupe plusieurs projets qui servent un même objectif.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap card">
          <table>
            <thead>
              <tr><th>Programme</th><th>Statut</th><th>Projets</th><th>Avancement moyen</th><th>Début</th><th>Fin</th><th><span class="sr-only">Actions</span></th></tr>
            </thead>
            <tbody>
            <?php foreach ($programmes as $p): ?>
              <tr>
                <td>
                  <strong><?= e($p['nom']) ?></strong>
                  <?php if ($p['description'] !== ''): ?><div class="sub"><?= e(mb_strimwidth($p['description'], 0, 110, '…')) ?></div><?php endif; ?>
                </td>
                <td><?= statut_badge($p['statut']) ?></td>
                <td><a href="projets.php?programme=<?= (int)$p['id'] ?>"><?= (int)$p['nb_projets'] ?></a></td>
                <td><?= progress((int)$p['avancement']) ?></td>
                <td><?= date_fr($p['date_debut']) ?></td>
                <td><?= date_fr($p['date_fin']) ?></td>
                <td class="row-actions">
                  <a href="programmes.php?action=edit&amp;id=<?= (int)$p['id'] ?>">Modifier</a>
                  <form method="post" data-confirm="Supprimer ce programme ? Ses projets seront conservés, sans programme.">
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
