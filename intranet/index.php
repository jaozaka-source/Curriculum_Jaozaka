<?php
require __DIR__ . '/inc/bootstrap.php';

if (current_user()) {
    redirect('recap.php');
}
redirect(has_users() ? 'login.php' : 'setup.php');
