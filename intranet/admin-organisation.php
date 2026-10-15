<?php
/**
 * Administration › Organisation : départements (imbriqués sur plusieurs niveaux) et services.
 * Chaque entité a un département parent, sauf la racine « Comité de direction ».
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user = require_admin();

// ---------- Actions ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = post_int('id');
    $all = entites();
    $existing = $all[$id] ?? null;
    if ($id && !$existing) { redirect('admin-organisation.php'); }

    if (($_POST['action'] ?? '') === 'delete') {
        $blocages = [];
        if ($existing['is_root']) { $blocages[] = 'la racine ne peut pas être supprimée'; }
        if (!empty(entites_children()[$id])) { $blocages[] = 'elle contient des départements ou des services'; }
        $st = db()->prepare('SELECT (SELECT COUNT(*) FROM rattachements WHERE entite_id = ?), (SELECT COUNT(*) FROM affectations WHERE entite_id = ?)');
        $st->execute([$id, $id]);
        [$nbMembres, $nbAff] = array_map('intval', $st->fetch(PDO::FETCH_NUM));
        if ($nbMembres) { $blocages[] = "$nbMembres personne(s) y sont rattachées"; }
        if ($nbAff) { $blocages[] = "elle est affectée à $nbAff projet(s) comme ressource à nommer"; }
        if ($blocages) {
            flash('Suppression impossible : ' . implode(', ', $blocages) . '.');
        } else {
            db()->prepare('DELETE FROM entites WHERE id = ?')->execute([$id]);
            flash(entite_type_label($existing['type']) . ' supprimé.');
        }
        redirect('admin-organisation.php');
    }

    $users = users_list();
    $type = post_choice('type', ['departement', 'service'], 'departement');
    $parentId = post_id('parent_id');
    $respId = post_id('responsable_id');
    $adjId = post_id('adjoint_id');
    $errors = [];

    if ($existing && $existing['is_root']) {
        $type = 'departement';
        $parentId = null;
    } else {
        $interdits = $existing ? entite_descendants($id) : [];
        if (!$parentId || ($all[$parentId]['type'] ?? '') !== 'departement' || in_array($parentId, $interdits, true)) {
            $errors[] = 'Choisissez un département parent valide.';
        }
        if ($existing && $type === 'service' && !empty(entites_children()[$id])) {
            $errors[] = 'Ce département contient des sous-entités : il ne peut pas devenir un service.';
        }
    }
    if (post_str('nom') === '') { $errors[] = 'Le nom est obligatoire.'; }
    if ($respId && !isset($users[$respId])) { $respId = null; }
    if ($adjId && !isset($users[$adjId])) { $adjId = null; }
    if ($respId && $respId === $adjId) { $errors[] = "Le responsable et l'adjoint doivent être deux personnes différentes."; }

    if ($errors) {
        flash(implode(' ', $errors));
        redirect('admin-organisation.php?' . ($id ? 'edit=' . $id : 'new=' . $type . '&parent=' . (int)$parentId));
    }

    $row = [$type, $parentId, post_str('nom', 160), post_str('code', 30), post_str('description', 2000), $respId, $adjId];
    if ($existing) {
        db()->prepare('UPDATE entites SET type = ?, parent_id = ?, nom = ?, code = ?, description = ?, responsable_id = ?, adjoint_id = ? WHERE id = ?')
            ->execute([...$row, $id]);
        flash('Organisation mise à jour.');
    } else {
        db()->prepare('INSERT INTO entites (type, parent_id, nom, code, description, responsable_id, adjoint_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute($row);
        flash(entite_type_label($type) . ' créé.');
    }
    redirect('admin-organisation.php');
}

// ---------- Formulaire (création ou modification) ----------

$editId = (int)($_GET['edit'] ?? 0);
$newType = in_array($_GET['new'] ?? '', ['departement', 'service'], true) ? $_GET['new'] : null;
$form = null;
if ($editId && isset(entites()[$editId])) {
    $form = entites()[$editId];
} elseif ($newType) {
    $form = ['id' => 0, 'type' => $newType, 'parent_id' => (int)($_GET['parent'] ?? 0) ?: entite_root_id(), 'nom' => '',
             'code' => '', 'description' => '', 'responsable_id' => null, 'adjoint_id' => null, 'is_root' => 0];
}

// Effectifs directs par entité
$effectifs = array_column(db()->query('SELECT entite_id, COUNT(*) AS n FROM rattachements GROUP BY entite_id')->fetchAll(), 'n', 'entite_id');

page_header('Organisation', 'admin-organisation', $user);
?>
      <div class="toolbar">
        <p class="muted">Un département peut contenir d'autres départements et des services. Les responsables sont choisis parmi les utilisateurs.</p>
        <span class="actions">
          <a class="btn btn-primary" href="admin-organisation.php?new=departement#form">+ Département</a>
          <a class="btn btn-primary" href="admin-organisation.php?new=service#form">+ Service</a>
          <a class="btn btn-ghost" href="organigramme.php">Voir l'organigramme</a>
        </span>
      </div>

      <?php if ($form):
          $fid = (int)$form['id'];
          $users = users_list();
          $exclude = $fid ? entite_descendants($fid) : []; ?>
        <section class="card highlight" id="form">
          <h2><?= $fid ? 'Modifier : ' . e($form['nom']) : 'Nouveau ' . mb_strtolower(entite_type_label($form['type'])) ?></h2>
          <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $fid ?>">
            <?php if ($form['is_root']): ?>
              <input type="hidden" name="type" value="departement">
              <p class="muted">Racine de l'organisation : elle n'a pas de parent et ne peut pas être supprimée.</p>
            <?php else: ?>
              <div class="row">
                <label>Type
                  <select name="type"><?= options(['departement' => 'Département', 'service' => 'Service'], $form['type'], true) ?></select>
                </label>
                <label>Département parent
                  <select name="parent_id" required><?= entite_options((int)$form['parent_id'], 'departement', $exclude) ?></select>
                </label>
              </div>
            <?php endif; ?>
            <div class="row">
              <label>Nom <input name="nom" required maxlength="160" value="<?= e($form['nom']) ?>"></label>
              <label>Code <small>(facultatif)</small> <input name="code" maxlength="30" value="<?= e($form['code']) ?>"></label>
            </div>
            <div class="row">
              <label>Responsable
                <select name="responsable_id"><?= user_options($users, $form['responsable_id'] !== null ? (int)$form['responsable_id'] : null) ?></select>
              </label>
              <label>Adjoint
                <select name="adjoint_id"><?= user_options($users, $form['adjoint_id'] !== null ? (int)$form['adjoint_id'] : null) ?></select>
              </label>
            </div>
            <label>Description
              <textarea name="description" rows="2" maxlength="2000"><?= e($form['description']) ?></textarea>
            </label>
            <div class="actions">
              <button class="btn btn-primary" type="submit">Enregistrer</button>
              <a class="btn btn-ghost" href="admin-organisation.php">Annuler</a>
            </div>
          </form>
        </section>
      <?php endif; ?>

      <div class="table-wrap card">
        <table class="tree-table">
          <thead><tr><th>Entité</th><th>Type</th><th>Responsable</th><th>Adjoint</th><th>Effectif</th><th><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
          <?php foreach (entites_tree_flat() as $row): $e = $row['e']; $eid = $row['id']; ?>
            <tr>
              <td style="padding-left: <?= 16 + $row['depth'] * 24 ?>px">
                <strong><?= $e['type'] === 'service' ? '◦ ' : '' ?><?= e($e['nom']) ?></strong>
                <?php if ($e['code'] !== ''): ?><span class="sub"> · <?= e($e['code']) ?></span><?php endif; ?>
              </td>
              <td><?= e(entite_type_label($e['type'])) ?></td>
              <td><?= $e['resp_nom'] !== null ? e(full_name($e, 'resp_')) : '<span class="muted">—</span>' ?></td>
              <td><?= $e['adj_nom'] !== null ? e(full_name($e, 'adj_')) : '<span class="muted">—</span>' ?></td>
              <td><?= (int)($effectifs[$eid] ?? 0) ?></td>
              <td class="row-actions">
                <?php if ($e['type'] === 'departement'): ?>
                  <a href="admin-organisation.php?new=departement&amp;parent=<?= $eid ?>#form" title="Ajouter un sous-département">+ Dépt</a>
                  <a href="admin-organisation.php?new=service&amp;parent=<?= $eid ?>#form" title="Ajouter un service">+ Service</a>
                <?php endif; ?>
                <a href="admin-organisation.php?edit=<?= $eid ?>#form">Modifier</a>
                <?php if (!$e['is_root']): ?>
                  <form method="post" data-confirm="Supprimer « <?= e($e['nom']) ?> » ?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $eid ?>">
                    <button class="link danger" type="submit">Supprimer</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
<?php
page_footer($user);
