<?php
declare(strict_types=1);

/** Hauptseite des Panels: nur die Anmeldung. Alles Weitere liegt hinter dem Login. */

require __DIR__ . '/bootstrap.php';

use App\Env;

if (logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $now = time();
    $_SESSION['fails'] = array_values(array_filter($_SESSION['fails'] ?? [], fn($t) => $now - $t < 300));
    if (count($_SESSION['fails']) >= 5) {
        $error = 'Zu viele Versuche. Bitte 5 Minuten warten.';
    } else {
        $user = (string) ($_POST['user'] ?? '');
        $pass = (string) ($_POST['pass'] ?? '');
        $hash = Env::get('PANEL_PASSWORD_HASH');
        if ($hash !== '' && hash_equals(Env::get('PANEL_USER', 'admin'), $user) && password_verify($pass, $hash)) {
            session_regenerate_id(true);
            $_SESSION['user'] = $user;
            $_SESSION['fails'] = [];
            header('Location: dashboard.php');
            exit;
        }
        $_SESSION['fails'][] = $now;
        $error = 'Benutzername oder Passwort falsch.';
    }
}

page_header('Login');
echo '<div class="card" style="max-width:360px;margin:60px auto"><h1>Anmelden</h1>';
if ($error) {
    echo '<div class="flash err">' . h($error) . '</div>';
}
echo '<form method="post">' . csrf_field()
    . '<label>Benutzername</label><input name="user" autofocus required>'
    . '<label>Passwort</label><input name="pass" type="password" required>'
    . '<p><button>Anmelden</button></p></form></div>';
page_footer();
