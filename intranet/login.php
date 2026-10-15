<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (!has_users()) {
    redirect('setup.php');
}
if (current_user()) {
    redirect('recap.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    // Ralentit les tentatives répétées depuis la même session.
    $fails = (int)($_SESSION['login_fails'] ?? 0);
    if ($fails >= 3) {
        sleep(min($fails, 10));
    }

    $email    = post_str('email', 160);
    $password = (string)($_POST['password'] ?? '');

    $st = db()->prepare('SELECT id, password_hash FROM users WHERE email = ? AND acces = 1 AND actif = 1');
    $st->execute([$email]);
    $row = $st->fetch();

    if ($row && $row['password_hash'] && password_verify($password, $row['password_hash'])) {
        unset($_SESSION['login_fails']);
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }
        login_user((int)$row['id']);
        redirect('recap.php');
    }
    $_SESSION['login_fails'] = $fails + 1;
    $error = 'E-mail ou mot de passe incorrect.';
}

page_header('Connexion');
?>
    <h1>Connexion</h1>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>E-mail
        <input name="email" type="email" required autocomplete="username" value="<?= e($email) ?>" autofocus>
      </label>
      <label>Mot de passe
        <input name="password" type="password" required autocomplete="current-password">
      </label>
      <button class="btn btn-primary" type="submit">Se connecter</button>
    </form>
<?php
page_footer();
