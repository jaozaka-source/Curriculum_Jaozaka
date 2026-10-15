<?php
/**
 * Administration › Paramètres : TJM par défaut (interne et externe).
 * Ils s'appliquent aux personnes sans TJM propre et aux services « à nommer » (TJM interne).
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $st = db()->prepare('INSERT INTO parametres (cle, valeur) VALUES (?, ?) ON CONFLICT (cle) DO UPDATE SET valeur = excluded.valeur');
    foreach (['tjm_interne', 'tjm_externe'] as $cle) {
        $st->execute([$cle, (string)(post_decimal($cle, 2) ?? 0)]);
    }
    flash('Paramètres enregistrés.');
    redirect('admin-parametres.php');
}

page_header('Paramètres', 'admin-parametres', $user);
?>
      <form method="post" class="form card">
        <?= csrf_field() ?>
        <h2>TJM par défaut</h2>
        <p class="muted">Utilisés pour valoriser les affectations des personnes sans TJM propre.
          Le TJM interne s'applique aussi aux services affectés en « ressource à nommer ».</p>
        <div class="row">
          <label>TJM interne (€)
            <input name="tjm_interne" type="number" min="0" step="0.01" inputmode="decimal" value="<?= e(num_value((float)param('tjm_interne'))) ?>">
          </label>
          <label>TJM externe (€)
            <input name="tjm_externe" type="number" min="0" step="0.01" inputmode="decimal" value="<?= e(num_value((float)param('tjm_externe'))) ?>">
          </label>
        </div>
        <div class="actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
      </form>
<?php
page_footer($user);
