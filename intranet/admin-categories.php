<?php
/**
 * Administration › Catégories des matériels et des services : ajouter, renommer, désactiver ou supprimer.
 * Une catégorie déjà utilisée par une ressource est désactivée au lieu d'être supprimée.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = post_int('id');
    $action = $_POST['action'] ?? 'save';

    if ($action === 'toggle') {
        db()->prepare('UPDATE categories SET actif = 1 - actif WHERE id = ?')->execute([$id]);
        flash('Catégorie mise à jour.');
        redirect('admin-categories.php');
    }
    if ($action === 'delete') {
        $st = db()->prepare('SELECT COUNT(*) FROM ressources WHERE categorie_id = ?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) {
            db()->prepare('UPDATE categories SET actif = 0 WHERE id = ?')->execute([$id]);
            flash('Cette catégorie est utilisée : elle a été désactivée au lieu d\'être supprimée.');
        } else {
            db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            flash('Catégorie supprimée.');
        }
        redirect('admin-categories.php');
    }

    $nom = post_str('nom', 120);
    $type = post_choice('type', array_keys(TYPES_RESSOURCE), 'materiel');
    if ($nom === '') {
        flash('Le nom est obligatoire.');
        redirect('admin-categories.php' . ($id ? '?edit=' . $id : ''));
    }
    if ($id) {
        db()->prepare('UPDATE categories SET nom = ?, type = ? WHERE id = ?')->execute([$nom, $type, $id]);
        flash('Catégorie mise à jour.');
    } else {
        db()->prepare('INSERT INTO categories (nom, type) VALUES (?, ?)')->execute([$nom, $type]);
        flash('Catégorie ajoutée.');
    }
    redirect('admin-categories.php');
}

$categories = db()->query('SELECT c.*, (SELECT COUNT(*) FROM ressources r WHERE r.categorie_id = c.id) AS usages
                           FROM categories c ORDER BY c.type, c.nom')->fetchAll();
$edit = null;
foreach ($categories as $c) { if ((int)$c['id'] === (int)($_GET['edit'] ?? 0)) { $edit = $c; } }

page_header('Catégories', 'admin-categories', $user);
?>
      <section class="card highlight">
        <h2><?= $edit ? 'Modifier la catégorie' : 'Ajouter une catégorie' ?></h2>
        <form method="post" class="form inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
          <div class="row">
            <label>Nom <input name="nom" required maxlength="120" value="<?= e($edit['nom'] ?? '') ?>"></label>
            <label>Pour
              <select name="type"><?= options(['materiel' => 'Matériels', 'service' => 'Services'], $edit['type'] ?? 'materiel', true) ?></select>
            </label>
          </div>
          <div class="actions">
            <button class="btn btn-primary" type="submit"><?= $edit ? 'Enregistrer' : 'Ajouter' ?></button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="admin-categories.php">Annuler</a><?php endif; ?>
          </div>
        </form>
      </section>

      <div class="grid-2">
        <?php foreach (TYPES_RESSOURCE as $type => $label): ?>
          <section class="card">
            <h2><?= e($label) ?>s</h2>
            <ul class="list">
              <?php foreach (array_filter($categories, fn($c) => $c['type'] === $type) as $c): ?>
                <li<?= $c['actif'] ? '' : ' class="inactive"' ?>>
                  <strong><?= e($c['nom']) ?></strong>
                  <span class="sub"><?= $c['usages'] ? (int)$c['usages'] . ' ressource' . ($c['usages'] > 1 ? 's' : '') : '' ?><?= $c['actif'] ? '' : ' · désactivée' ?></span>
                  <span class="row-actions">
                    <a href="admin-categories.php?edit=<?= (int)$c['id'] ?>">Modifier</a>
                    <form method="post"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                      <button class="link" type="submit"><?= $c['actif'] ? 'Désactiver' : 'Réactiver' ?></button>
                    </form>
                    <form method="post" data-confirm="Supprimer la catégorie « <?= e($c['nom']) ?> » ?"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                      <button class="link danger" type="submit">Supprimer</button>
                    </form>
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endforeach; ?>
      </div>
<?php
page_footer($user);
