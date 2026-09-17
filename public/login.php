<?php
/**
 * Sign in with Google. The only page reachable without an approved account.
 *
 *   GET  /login.php                  sign-in button, or the account status (pending / disabled) of a signed-in user
 *   POST /login.php?action=google    ID token from Google's sign-in button (also finishes the setup)
 *   POST /login.php?action=logout    sign out (CSRF token required)
 */

require_once __DIR__ . '/includes/auth.php';

if (!is_file(CONFIG_FILE)) {
    header('Location: /setup.php', true, 302);
    exit;
}

$action = (string)($_GET['action'] ?? '');

/** One-time message across the redirect back to a page. */
function flash(?string $message = null): ?string {
    if ($message !== null) {
        startSession(true);
        $_SESSION['flash'] = $message;
        session_write_close();
        return null;
    }
    if (!is_string(sessionValue('flash'))) {
        return null;
    }
    startSession(true);
    $value = $_SESSION['flash'];
    unset($_SESSION['flash']);
    session_write_close();
    return $value;
}

// ── Sign out ─────────────────────────────────────────────
if ($action === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid($_POST['csrf'] ?? null)) {
        logout();
    }
    header('Location: /login.php', true, 303);
    exit;
}

// ── ID token from the sign-in button ─────────────────────
if ($action === 'google' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Errors during the setup sign-in go back to the setup page
    $setupPending = !isInstalled() && !empty(sessionValue('google')['setup'] ?? false);
    try {
        $result = completeGoogleSignIn($_POST['credential'] ?? null, $_POST['csrf'] ?? null);

        if ($result['setup']) {
            // Setup: the first sign-in proves the Google configuration works and creates the administrator
            if (isInstalled()) {
                throw new RuntimeException('The installation is already finished.');
            }
            $user = upsertGoogleUser($result['claims'], true);
            $config = appConfig();
            $config['installed'] = true;
            $config['installed_at'] = gmdate('c');
            unset($config['setup_token']);
            saveConfig($config);
            signIn($user);
            header('Location: /', true, 303);
            exit;
        }

        if (!isInstalled()) {
            throw new RuntimeException('The installation is not finished yet.');
        }
        $user = upsertGoogleUser($result['claims']);
        if ($user['status'] === 'disabled') {
            throw new RuntimeException('The account ' . $user['email'] . ' has been disabled by an administrator.');
        }
        signIn($user);
        header('Location: ' . ($user['status'] === 'approved' ? $result['next'] : '/login.php'), true, 303);
        exit;
    } catch (Throwable $e) {
        error_log('[login] ' . $e->getMessage());
        flash($e instanceof PDOException ? 'The database is not available. Please try again later.' : $e->getMessage());
        $setupToken = (string)(appConfig()['setup_token'] ?? '');
        header('Location: ' . ($setupPending && $setupToken !== '' ? '/setup.php?token=' . rawurlencode($setupToken) : '/login.php'), true, 303);
        exit;
    }
}

// ── Page ─────────────────────────────────────────────────
if (!isInstalled()) {
    header('Location: /setup.php', true, 302);
    exit;
}

$next = safeNextPath($_GET['next'] ?? '/');
$message = flash();

try {
    $user = currentUser();
} catch (Throwable $e) {
    error_log('[login] ' . $e->getMessage());
    renderAuthPage('Sign in', '<p class="notice error">The database is not available. Please try again later.</p>');
    exit;
}

if (isApproved($user)) {
    header('Location: ' . $next, true, 303);
    exit;
}

$body = $message !== null ? '<p class="notice error" role="alert">' . e($message) . '</p>' : '';

if ($user !== null) {
    $avatar = $user['picture'] !== ''
        ? '<img src="' . e($user['picture']) . '" alt="" referrerpolicy="no-referrer">'
        : '<span class="avatar">' . e(mb_strtoupper(mb_substr($user['name'] ?: $user['email'], 0, 1))) . '</span>';
    $body .= '<div class="user-card">' . $avatar . '<div><strong>' . e($user['name'] ?: $user['email']) . '</strong><span>' . e($user['email']) . '</span></div></div>';
    $body .= $user['status'] === 'pending'
        ? '<p class="notice warning">Your account is waiting for approval by an administrator. Downloading becomes available once it is approved; reload this page later.</p>'
        : '<p class="notice error">This account has been disabled by an administrator.</p>';
    $body .= '<form method="post" action="/login.php?action=logout"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">'
        . '<button type="submit" class="secondary">Sign out</button></form>';
    renderAuthPage($user['status'] === 'pending' ? 'Waiting for approval' : 'Account disabled', $body);
    exit;
}

$body .= '<p>Sign in with your Google account. New accounts can download after an administrator approves them.</p>'
    . googleSignInWidget(false, $next);
renderAuthPage('Sign in', $body);
