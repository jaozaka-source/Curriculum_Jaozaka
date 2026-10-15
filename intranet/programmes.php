<?php
/**
 * Programmes : liste, création, modification, suppression.
 * Un programme regroupe plusieurs projets ; il a un chef de projet désigné.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user = require_login();

function find_programme(int $id): ?array {
    $st = db()->prepare('SELECT * FROM programmes WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Chef désigné : choisi par un administrateur, sinon l'utilisateur lui-même (ou le chef actuel). */
function chef_choisi(array $user, ?array $existing): ?int {
    $chefs = chefs_list();
    $id = post_id('chef_projet_id');
    if ($id && isset($chefs[$id]) && (is_admin($user) || $user['role'] === 'chef_projet')) {
        return $id;
    }
    return $existing ? ($existing['chef_projet_id'] !== null ? (int)$existing['chef_projet_id'] : null) : (int)$user['id'];
}

// ---------- Actions ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = post_int('id');
    $existing = $id ? find_programme($id) : null;

    if ($id && (!$existing || !can_edit_programme($user, $existing))) {
        flash("Vous ne pouvez pas modifier ce programme.");
        redirect('programmes.php');
    }
    if (!$id && !can_create($user)) {
        flash("Votre rôle ne permet pas de créer de programme.");
        redirect('programmes.php');
    }

    if (($_POST['action'] ?? '') === 'delete') {
        db()->prepare('DELETE FROM programmes WHERE id = ?')->execute([$id]);
        flash('Programme supprimé. Ses projets ont été conservés, sans programme.');
        redirect('programmes.php');
    }

    $data = [
        post_str('nom', 160),
        post_str('description', 5000),
        post_statut(),
        post_date('date_debut'),
        post_date('date_fin'),
        chef_choisi($user, $existing),
    ];
    if ($data[0] === '') {
        flash('Le nom du programme est obligatoire.');
        redirect('programmes.php?action=' . ($id ? 'edit&id=' . $id : 'new'));
    }

    if ($existing) {
        db()->prepare("UPDATE programmes SET nom = ?, description = ?, statut = ?, date_debut = ?, date_fin = ?,
                       chef_projet_id = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([...$data, $id]);
        flash('Programme mis à jour.');
    } else {
        db()->prepare('INSERT INTO programmes (nom, description, statut, date_debut, date_fin, chef_projet_id)
                       VALUES (?, ?, ?, ?, ?, ?)')
            ->execute($data);
        flash('Programme créé.');
    }
    redirect('programmes.php');
}

// ---------- Formulaire ----------

$action = $_GET['action'] ?? '';
if ($action === 'new' || $action === 'edit') {
    $p = ['id' => 0, 'nom' => '', 'description' => '', 'statut' => STATUTS[0], 'date_debut' => null,
          'date_fin' => null, 'chef_projet_id' => $user['id']];
    if ($action === 'edit') {
        $p = find_programme((int)($_GET['id'] ?? 0)) ?? redirect('programmes.php');
        if (!can_edit_programme($user, $p)) { redirect('programmes.php'); }
    } elseif (!can_create($user)) {
        redirect('programmes.php');
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
        <label>Chef de projet
          <select name="chef_projet_id"><?= user_options(chefs_list(), $p['chef_projet_id'] !== null ? (int)$p['chef_projet_id'] : null, '— Non désigné —') ?></select>
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

[$cond, $params] = programmes_visibles_sql($user);
$st = db()->prepare("
    SELECT p.*, u.prenom AS chef_prenom, u.nom AS chef_nom,
           COUNT(pr.id) AS nb_projets, COALESCE(ROUND(AVG(pr.avancement)), 0) AS avancement
    FROM programmes p
    LEFT JOIN users u ON u.id = p.chef_projet_id
    LEFT JOIN projets pr ON pr.programme_id = p.id
    WHERE $cond
    GROUP BY p.id
    ORDER BY CASE p.statut WHEN 'En cours' THEN 0 WHEN 'À cadrer' THEN 1 WHEN 'En pause' THEN 2 ELSE 3 END, p.nom
");
$st->execute($params);
$programmes = $st->fetchAll();

page_header('Programmes', 'programmes', $user);
?>
      <div class="toolbar">
        <p class="muted"><?= count($programmes) ?> programme<?= count($programmes) > 1 ? 's' : '' ?></p>
        <?php if (can_create($user)): ?>
          <a class="btn btn-primary" href="programmes.php?action=new">+ Nouveau programme</a>
        <?php endif; ?>
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
              <tr><th>Programme</th><th>Chef de projet</th><th>Statut</th><th>Projets</th><th>Avancement moyen</th><th>Début</th><th>Fin</th><th><span class="sr-only">Actions</span></th></tr>
            </thead>
            <tbody>
            <?php foreach ($programmes as $p): ?>
              <tr>
                <td>
                  <strong><?= e($p['nom']) ?></strong>
                  <?php if ($p['description'] !== ''): ?><div class="sub"><?= e(mb_strimwidth($p['description'], 0, 110, '…')) ?></div><?php endif; ?>
                </td>
                <td><?= $p['chef_nom'] !== null ? e(full_name($p, 'chef_')) : '<span class="muted">—</span>' ?></td>
                <td><?= statut_badge($p['statut']) ?></td>
                <td><a href="projets.php?programme=<?= (int)$p['id'] ?>"><?= (int)$p['nb_projets'] ?></a></td>
                <td><?= progress((int)$p['avancement']) ?></td>
                <td><?= date_fr($p['date_debut']) ?></td>
                <td><?= date_fr($p['date_fin']) ?></td>
                <td class="row-actions">
                  <?php if (can_edit_programme($user, $p)): ?>
                    <a href="programmes.php?action=edit&amp;id=<?= (int)$p['id'] ?>">Modifier</a>
                    <form method="post" data-confirm="Supprimer ce programme ? Ses projets seront conservés, sans programme.">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button class="link danger" type="submit">Supprimer</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
<?php
page_footer($user);
