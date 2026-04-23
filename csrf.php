<?php
// csrf.php — tiny, session-based CSRF helpers

// Ensure session exists (safe if already started)
if (session_status() !== PHP_SESSION_ACTIVE) {
  @ini_set('session.use_strict_mode', 1);
  if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
      'lifetime' => 0,
      'path'     => '/',
      'secure'   => isset($_SERVER['HTTPS']),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
  session_start();
}

/** Internal: name of the form field/header carrying the token */
function csrf_token_name(): string { return '_token'; }

/** Get (and create if missing) the CSRF token tied to the current session */
function csrf_get_token(): string {
  if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32)); // 64 hex chars
  }
  return $_SESSION['_csrf_token'];
}

/** Optional: rotate to a fresh token (usually not required between multiple forms) */
function csrf_regenerate(): void {
  $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

/** Emit a hidden input for HTML forms */
function csrf_field(): string {
  $t = htmlspecialchars(csrf_get_token(), ENT_QUOTES, 'UTF-8');
  $n = htmlspecialchars(csrf_token_name(), ENT_QUOTES, 'UTF-8');
  return '<input type="hidden" name="'.$n.'" value="'.$t.'">';
}

/**
 * Validate the CSRF token.
 * - By default checks POST body first, then X-CSRF-Token header (for fetch/AJAX).
 * - If you want to allow GET forms, pass $method='GET'.
 */
function csrf_validate(string $method = 'POST'): bool {
  $expected = $_SESSION['_csrf_token'] ?? '';
  if (!is_string($expected) || $expected === '') return false;

  $name = csrf_token_name();
  $header = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : '';

  $candidate = '';
  if (strtoupper($method) === 'POST') {
    $candidate = isset($_POST[$name]) ? (string)$_POST[$name] : $header;
  } elseif (strtoupper($method) === 'GET') {
    $candidate = isset($_GET[$name]) ? (string)$_GET[$name] : $header;
  } else {
    // Fallback: try both
    $candidate = $_POST[$name] ?? $_GET[$name] ?? $header;
    $candidate = (string)$candidate;
  }

  if ($candidate === '' || !is_string($candidate)) return false;

  // Timing-safe compare
  return hash_equals($expected, $candidate);
}
