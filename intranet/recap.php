<?php
/**
 * Récapitulatif : vue d'ensemble des programmes et projets du chef de projet.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user = require_login();
$uid  = (int)$user['id'];

$count = function (string $sql, array $params = []) use ($uid): int {
    $st = db()->prepare($sql);
    $st->execute([$uid, ...$params]);
    return (int)$st->fetchColumn();
};

$today = date('Y-m-d');

$nbProgrammes = $count('SELECT COUNT(*) FROM programmes WHERE owner_id = ?');
$nbProjets    = $count('SELECT COUNT(*) FROM projets WHERE owner_id = ?');
$nbEnCours    = $count("SELECT COUNT(*) FROM projets WHERE owner_id = ? AND statut = 'En cours'");
$nbRetard     = $count("SELECT COUNT(*) FROM projets WHERE owner_id = ? AND date_fin < ?
                        AND statut NOT IN ('Terminé', 'Annulé')", [$today]);

// Répartition des projets par statut
$st = db()->prepare('SELECT statut, COUNT(*) AS n FROM projets WHERE owner_id = ? GROUP BY statut');
$st->execute([$uid]);
$parStatut = array_column($st->fetchAll(), 'n', 'statut');

// Prochaines échéances (projets actifs)
$st = db()->prepare("
    SELECT pr.id, pr.nom, pr.date_fin, pr.statut, pr.avancement, pg.nom AS programme
    FROM projets pr LEFT JOIN programmes pg ON pg.id = pr.programme_id
    WHERE pr.owner_id = ? AND pr.date_fin IS NOT NULL AND pr.statut NOT IN ('Terminé', 'Annulé')
    ORDER BY pr.date_fin LIMIT 6
");
$st->execute([$uid]);
$echeances = $st->fetchAll();

// Programmes actifs avec avancement moyen
$st = db()->prepare("
    SELECT p.id, p.nom, p.statut, COUNT(pr.id) AS nb, COALESCE(ROUND(AVG(pr.avancement)), 0) AS avancement
    FROM programmes p LEFT JOIN projets pr ON pr.programme_id = p.id
    WHERE p.owner_id = ? AND p.statut NOT IN ('Terminé', 'Annulé')
    GROUP BY p.id ORDER BY p.nom
");
$st->execute([$uid]);
$programmesActifs = $st->fetchAll();

page_header('Récapitulatif', 'recap', $user);
?>
      <p class="hello">Bonjour <?= e(explode(' ', $user['nom'])[0]) ?>, voici l'état de votre portefeuille.</p>

      <section class="kpis" aria-label="Indicateurs clés">
        <a class="kpi card" href="programmes.php"><span class="n"><?= $nbProgrammes ?></span><span class="l">Programmes</span></a>
        <a class="kpi card" href="projets.php"><span class="n"><?= $nbProjets ?></span><span class="l">Projets</span></a>
        <a class="kpi card" href="projets.php?statut=En+cours"><span class="n"><?= $nbEnCours ?></span><span class="l">Projets en cours</span></a>
        <div class="kpi card<?= $nbRetard ? ' alert' : '' ?>"><span class="n"><?= $nbRetard ?></span><span class="l">En retard</span></div>
      </section>

      <?php if (!$nbProgrammes && !$nbProjets): ?>
        <div class="empty card">
          <p>Votre portefeuille est vide pour l'instant.</p>
          <p class="actions">
            <a class="btn btn-primary" href="programmes.php?action=new">Créer un programme</a>
            <a class="btn btn-ghost" href="projets.php?action=new">Créer un projet</a>
          </p>
        </div>
      <?php else: ?>
      <div class="grid-2">
        <section class="card">
          <h2>Prochaines échéances</h2>
          <?php if (!$echeances): ?>
            <p class="muted">Aucune échéance renseignée sur les projets actifs.</p>
          <?php else: ?>
            <ul class="list">
              <?php foreach ($echeances as $p): ?>
                <li>
                  <a href="projets.php?action=edit&amp;id=<?= (int)$p['id'] ?>"><strong><?= e($p['nom']) ?></strong></a>
                  <span class="sub"><?= $p['programme'] !== null ? e($p['programme']) . ' · ' : '' ?><?= statut_badge($p['statut']) ?></span>
                  <span class="date<?= $p['date_fin'] < $today ? ' late' : '' ?>"><?= date_fr($p['date_fin']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>

        <section class="card">
          <h2>Projets par statut</h2>
          <ul class="list">
            <?php foreach (STATUTS as $s): ?>
              <li>
                <a href="projets.php?statut=<?= urlencode($s) ?>"><?= statut_badge($s) ?></a>
                <span class="date"><?= (int)($parStatut[$s] ?? 0) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      </div>

      <section class="card">
        <h2>Programmes actifs</h2>
        <?php if (!$programmesActifs): ?>
          <p class="muted">Aucun programme actif.</p>
        <?php else: ?>
          <ul class="list">
            <?php foreach ($programmesActifs as $p): ?>
              <li>
                <a href="projets.php?programme=<?= (int)$p['id'] ?>"><strong><?= e($p['nom']) ?></strong></a>
                <span class="sub"><?= (int)$p['nb'] ?> projet<?= $p['nb'] > 1 ? 's' : '' ?> · <?= statut_badge($p['statut']) ?></span>
                <?= progress((int)$p['avancement']) ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
      <?php endif; ?>
<?php
page_footer($user);
