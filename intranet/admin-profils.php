<?php
/**
 * Administration › Profils projet : ajouter, renommer, désactiver ou supprimer.
 * Un profil déjà utilisé dans une affectation est désactivé au lieu d'être supprimé.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = post_int('id');
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete' || $action === 'toggle') {
        if ($action === 'toggle') {
            db()->prepare('UPDATE profils SET actif = 1 - actif WHERE id = ?')->execute([$id]);
            flash('Profil mis à jour.');
        } else {
            $st = db()->prepare('SELECT COUNT(*) FROM affectations WHERE profil_id = ?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) {
                db()->prepare('UPDATE profils SET actif = 0 WHERE id = ?')->execute([$id]);
                flash('Ce profil est utilisé dans des projets : il a été désactivé au lieu d\'être supprimé.');
            } else {
                db()->prepare('DELETE FROM profils WHERE id = ?')->execute([$id]);
                flash('Profil supprimé.');
            }
        }
        redirect('admin-profils.php');
    }

    $nom = post_str('nom', 120);
    $categorie = post_str('categorie', 80);
    if ($nom === '' || $categorie === '') {
        flash('Le nom et la catégorie sont obligatoires.');
        redirect('admin-profils.php' . ($id ? '?edit=' . $id : ''));
    }
    if ($id) {
        db()->prepare('UPDATE profils SET nom = ?, categorie = ? WHERE id = ?')->execute([$nom, $categorie, $id]);
        flash('Profil mis à jour.');
    } else {
        db()->prepare('INSERT INTO profils (nom, categorie) VALUES (?, ?)')->execute([$nom, $categorie]);
        flash('Profil ajouté.');
    }
    redirect('admin-profils.php');
}

$profils = db()->query('SELECT p.*, (SELECT COUNT(*) FROM affectations a WHERE a.profil_id = p.id) AS usages
                        FROM profils p ORDER BY p.categorie, p.nom')->fetchAll();
$groupes = [];
foreach ($profils as $p) { $groupes[$p['categorie']][] = $p; }

$edit = null;
foreach ($profils as $p) { if ((int)$p['id'] === (int)($_GET['edit'] ?? 0)) { $edit = $p; } }

page_header('Profils projet', 'admin-profils', $user);
?>
      <section class="card highlight">
        <h2><?= $edit ? 'Modifier le profil' : 'Ajouter un profil' ?></h2>
        <form method="post" class="form inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
          <div class="row">
            <label>Nom du profil <input name="nom" required maxlength="120" value="<?= e($edit['nom'] ?? '') ?>"></label>
            <label>Catégorie <small>(existante ou nouvelle)</small>
              <input name="categorie" required maxlength="80" list="categories-profils" value="<?= e($edit['categorie'] ?? '') ?>">
              <datalist id="categories-profils"><?php foreach (array_keys($groupes) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
            </label>
          </div>
          <div class="actions">
            <button class="btn btn-primary" type="submit"><?= $edit ? 'Enregistrer' : 'Ajouter' ?></button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="admin-profils.php">Annuler</a><?php endif; ?>
          </div>
        </form>
      </section>

      <div class="grid-2 masonry">
        <?php foreach ($groupes as $cat => $items): ?>
          <section class="card">
            <h2><?= e($cat) ?></h2>
            <ul class="list">
              <?php foreach ($items as $p): ?>
                <li<?= $p['actif'] ? '' : ' class="inactive"' ?>>
                  <strong><?= e($p['nom']) ?></strong>
                  <span class="sub"><?= $p['usages'] ? (int)$p['usages'] . ' affectation' . ($p['usages'] > 1 ? 's' : '') : '' ?><?= $p['actif'] ? '' : ' · désactivé' ?></span>
                  <span class="row-actions">
                    <a href="admin-profils.php?edit=<?= (int)$p['id'] ?>">Modifier</a>
                    <form method="post"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button class="link" type="submit"><?= $p['actif'] ? 'Désactiver' : 'Réactiver' ?></button>
                    </form>
                    <form method="post" data-confirm="Supprimer le profil « <?= e($p['nom']) ?> » ?"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
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
