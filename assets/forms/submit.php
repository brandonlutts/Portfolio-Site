<?php
declare(strict_types=1);

/**
 * Unified form handler for:
 * - faq-question
 * - contact (index)
 * - contact-page (about)
 * - quote-request (services)
 *
 * Returns JSON only (no redirects). Works well with fetch/AJAX.
 */

header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

// ---- CONFIG
$OWNER_EMAIL    = 'luttsbn@gmail.com';
$SITE_NAME      = 'Brandon Lutts';
$SITE_URL       = 'https://brandonlutts.com';
$FROM_EMAIL     = 'no-reply@cherami.brandonlutts.com';
$REPLY_FALLBACK = $OWNER_EMAIL;

// ---- reCAPTCHA v3
$RECAPTCHA_SECRET    = '6LdokoksAAAAAMdhGKh5WbtdPscEvu84Exh0koz8';
$RECAPTCHA_MIN_SCORE = 0.55;
$RECAPTCHA_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

// ---- Anti-spam behavior
$MIN_SUBMIT_SECONDS = 4;
$MAX_SUBMIT_SECONDS = 60 * 60 * 12; // 12 hours
$QUARANTINE_DIR = __DIR__ . '/logs/form-quarantine';
$SECURITY_LOG   = __DIR__ . '/logs/form-security.log';

// ---------- Helpers ----------
function respond(int $status, array $payload): void {
  http_response_code($status);
  echo json_encode($payload);
  exit;
}

function post(string $key, string $default = ''): string {
  return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function clean_text(string $s, int $maxLen = 4000): string {
  $s = preg_replace("/[\r\n]+/", "\n", $s ?? '') ?? '';
  $s = trim($s);
  if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
  return $s;
}

function clean_header_value(string $s): string {
  return str_replace(["\r", "\n"], '', trim($s ?? ''));
}

function is_valid_email(string $email): bool {
  return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function ensure_dir(string $dir): void {
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
}

function client_ip(): string {
  return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function user_agent(): string {
  return clean_text($_SERVER['HTTP_USER_AGENT'] ?? '', 500);
}

function request_host(): string {
  return strtolower(clean_text($_SERVER['HTTP_HOST'] ?? '', 255));
}

function log_security(string $type, array $context = []): void {
  $logFile = $GLOBALS['SECURITY_LOG'];
  ensure_dir(dirname($logFile));

  $entry = [
    'time' => date('c'),
    'type' => $type,
    'ip' => client_ip(),
    'ua' => user_agent(),
    'host' => request_host(),
    'context' => $context,
  ];

  @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function quarantine_submission(string $reason, array $payload): void {
  $dir = $GLOBALS['QUARANTINE_DIR'];
  ensure_dir($dir);

  $record = [
    'time' => date('c'),
    'reason' => $reason,
    'ip' => client_ip(),
    'ua' => user_agent(),
    'host' => request_host(),
    'payload' => $payload,
  ];

  $file = $dir . '/q_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
  @file_put_contents($file, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function verify_recaptcha_v3(string $token, string $expectedAction): array {
  if ($token === '' || $expectedAction === '') {
    return [
      'ok' => false,
      'reason' => 'missing_token_or_action',
      'score' => 0.0,
      'action' => '',
      'hostname' => '',
      'raw' => null,
    ];
  }

  $postData = http_build_query([
    'secret' => $GLOBALS['RECAPTCHA_SECRET'],
    'response' => $token,
    'remoteip' => client_ip(),
  ]);

  $responseBody = null;

  if (function_exists('curl_init')) {
    $ch = curl_init($GLOBALS['RECAPTCHA_VERIFY_URL']);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $postData,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $responseBody = curl_exec($ch);
    curl_close($ch);
  } else {
    $context = stream_context_create([
      'http' => [
        'method' => 'POST',
        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
        'content' => $postData,
        'timeout' => 10,
      ]
    ]);
    $responseBody = @file_get_contents($GLOBALS['RECAPTCHA_VERIFY_URL'], false, $context);
  }

  if (!is_string($responseBody) || $responseBody === '') {
    return [
      'ok' => false,
      'reason' => 'verification_request_failed',
      'score' => 0.0,
      'action' => '',
      'hostname' => '',
      'raw' => null,
    ];
  }

  $json = json_decode($responseBody, true);
  if (!is_array($json)) {
    return [
      'ok' => false,
      'reason' => 'invalid_verification_response',
      'score' => 0.0,
      'action' => '',
      'hostname' => '',
      'raw' => $responseBody,
    ];
  }

  $success  = (bool)($json['success'] ?? false);
  $score    = (float)($json['score'] ?? 0.0);
  $action   = (string)($json['action'] ?? '');
  $hostname = strtolower((string)($json['hostname'] ?? ''));
  $errors   = $json['error-codes'] ?? [];

  if (!$success) {
    return [
      'ok' => false,
      'reason' => 'recaptcha_unsuccessful',
      'score' => $score,
      'action' => $action,
      'hostname' => $hostname,
      'errors' => $errors,
      'raw' => $json,
    ];
  }

  if ($action !== $expectedAction) {
    return [
      'ok' => false,
      'reason' => 'action_mismatch',
      'score' => $score,
      'action' => $action,
      'hostname' => $hostname,
      'raw' => $json,
    ];
  }

  $requestHost = request_host();
  if ($hostname !== '' && $requestHost !== '' && $hostname !== $requestHost) {
    return [
      'ok' => false,
      'reason' => 'hostname_mismatch',
      'score' => $score,
      'action' => $action,
      'hostname' => $hostname,
      'raw' => $json,
    ];
  }

  if ($score < (float)$GLOBALS['RECAPTCHA_MIN_SCORE']) {
    return [
      'ok' => false,
      'reason' => 'low_score',
      'score' => $score,
      'action' => $action,
      'hostname' => $hostname,
      'raw' => $json,
    ];
  }

  return [
    'ok' => true,
    'reason' => 'passed',
    'score' => $score,
    'action' => $action,
    'hostname' => $hostname,
    'raw' => $json,
  ];
}

/**
 * Basic rate limit: max 6 submissions per 10 minutes per IP.
 */
function rate_limit_or_die(): void {
  $ip  = client_ip();
  $dir = sys_get_temp_dir() . '/bl_forms_rate';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);

  $file = $dir . '/rl_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $ip) . '.json';
  $now  = time();

  $data = ['hits' => []];
  if (is_file($file)) {
    $raw  = @file_get_contents($file);
    $json = json_decode((string)$raw, true);
    if (is_array($json)) $data = $json;
  }

  $window = 10 * 60;
  $hits = array_values(array_filter($data['hits'] ?? [], function ($t) use ($now, $window) {
    return is_int($t) && (($now - $t) < $window);
  }));
  $hits[] = $now;

  @file_put_contents($file, json_encode(['hits' => $hits]), LOCK_EX);

  if (count($hits) > 6) {
    log_security('rate_limit_blocked', ['ip' => $ip, 'hits' => count($hits)]);
    respond(429, ['ok' => false, 'message' => 'Too many requests. Please try again in a few minutes.']);
  }
}

function build_multipart_body(string $text, string $html, string $boundary): string {
  $out  = "--{$boundary}\r\n";
  $out .= "Content-Type: text/plain; charset=UTF-8\r\n";
  $out .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
  $out .= $text . "\r\n\r\n";
  $out .= "--{$boundary}\r\n";
  $out .= "Content-Type: text/html; charset=UTF-8\r\n";
  $out .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
  $out .= $html . "\r\n\r\n";
  $out .= "--{$boundary}--\r\n";
  return $out;
}

function send_multipart_mail(string $to, string $fromEmail, string $replyTo, string $subject, string $text, string $html): bool {
  $boundary  = 'bndry_' . bin2hex(random_bytes(8));

  $to        = clean_header_value($to);
  $fromEmail = clean_header_value($fromEmail);
  $replyTo   = clean_header_value($replyTo);
  $subject   = clean_header_value($subject);

  $headers = [];
  $headers[] = 'From: ' . clean_header_value($GLOBALS['SITE_NAME']) . ' <' . $fromEmail . '>';
  $headers[] = 'Reply-To: ' . $replyTo;
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

  $body = build_multipart_body($text, $html, $boundary);

  return @mail($to, $subject, $body, implode("\r\n", $headers));
}

function hud_email_html(string $title, string $intro, array $rows, string $footerNote = ''): string {
  $accent = '#FFD028';
  $ink    = '#E9EEF6';
  $muted  = '#A8B3C7';
  $panel  = '#12151A';
  $line   = 'rgba(255,255,255,.08)';

  $rowHtml = '';
  foreach ($rows as $label => $value) {
    $labelEsc = htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8');
    $valEsc   = nl2br(htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'));
    $rowHtml .= "
      <tr>
        <td style=\"padding:10px 12px;color:{$muted};font-family:ui-monospace, Menlo, monospace;font-size:12px;letter-spacing:.08em;text-transform:uppercase;vertical-align:top;border-top:1px solid {$line};width:160px;\">
          {$labelEsc}
        </td>
        <td style=\"padding:10px 12px;color:{$ink};font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;line-height:1.6;border-top:1px solid {$line};\">
          {$valEsc}
        </td>
      </tr>
    ";
  }

  $footerNoteEsc = htmlspecialchars($footerNote, ENT_QUOTES, 'UTF-8');

  return "
  <div style=\"background:#0B0D10;padding:22px;\">
    <div style=\"max-width:640px;margin:0 auto;background:{$panel};border:1px solid {$line};border-radius:16px;overflow:hidden;\">
      <div style=\"padding:18px 18px 12px;border-bottom:1px solid {$line};\">
        <div style=\"font-family:ui-monospace, Menlo, monospace;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:{$muted};\">
          {$GLOBALS['SITE_NAME']}
        </div>
        <div style=\"margin-top:8px;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:20px;font-weight:800;color:{$ink};\">
          {$title}
        </div>
        <div style=\"margin-top:8px;color:{$muted};font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;line-height:1.6;\">
          {$intro}
        </div>
        <div style=\"margin-top:12px;height:3px;background:rgba(255,255,255,.06);border-radius:999px;overflow:hidden;\">
          <div style=\"height:100%;width:46%;background:{$accent};\"></div>
        </div>
      </div>
      <div style=\"padding:4px 0 6px;\">
        <table role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" style=\"border-collapse:collapse;\">
          {$rowHtml}
        </table>
      </div>
      <div style=\"padding:14px 18px;border-top:1px solid {$line};color:{$muted};font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:12px;line-height:1.6;\">
        {$footerNoteEsc}
      </div>
    </div>
  </div>
  ";
}

function too_many_urls(string $text): bool {
  preg_match_all('~https?://|www\.|[a-z0-9.-]+\.[a-z]{2,}~iu', $text, $m);
  return count($m[0] ?? []) >= 2;
}

function suspicious_keywords_score(string $text): int {
  $score = 0;
  $patterns = [
    'seo',
    'guest post',
    'backlink',
    'crypto',
    'telegram',
    'whatsapp',
    'casino',
    'viagra',
    'loan',
    'forex',
    'traffic',
    'ranking',
    'bitcoin',
    'nude',
    'escort',
    'porn',
    'gambling',
    'cheap website',
    'google ads',
  ];

  $lower = mb_strtolower($text, 'UTF-8');
  foreach ($patterns as $pattern) {
    if (mb_strpos($lower, $pattern) !== false) {
      $score += 12;
    }
  }

  return $score;
}

function gibberish_score(string $text): int {
  $text = trim($text);
  if ($text === '') return 0;

  $score = 0;
  $lettersOnly = preg_replace('/[^a-z]/i', '', $text) ?? '';
  $alphaLen = strlen($lettersOnly);

  if ($alphaLen >= 12) {
    preg_match_all('/[bcdfghjklmnpqrstvwxyz]{6,}/i', $lettersOnly, $m1);
    if (!empty($m1[0])) $score += 18;

    $vowels = preg_match_all('/[aeiouy]/i', $lettersOnly);
    $vowelRatio = $alphaLen > 0 ? ($vowels / $alphaLen) : 0;
    if ($vowelRatio < 0.20) $score += 16;
  }

  preg_match_all('/(.)\1{4,}/u', $text, $m2);
  if (!empty($m2[0])) $score += 14;

  preg_match_all('/\b([a-z]{2,})\1{2,}\b/i', $text, $m3);
  if (!empty($m3[0])) $score += 16;

  $symbolHeavy = preg_replace('/[a-z0-9\s.,?!@:_\-\/]/iu', '', $text) ?? '';
  $symbolRatio = strlen($text) > 0 ? (strlen($symbolHeavy) / strlen($text)) : 0;
  if (strlen($text) > 20 && $symbolRatio > 0.20) $score += 10;

  $wordCount = preg_match_all('/\b[\p{L}\p{N}\'-]+\b/u', $text);
  if (strlen($text) > 25 && $wordCount <= 3) $score += 12;

  return $score;
}

function repeated_fingerprint_score(string $fingerprint): int {
  if ($fingerprint === '') return 0;

  $dir = sys_get_temp_dir() . '/bl_forms_fp';
  ensure_dir($dir);

  $file = $dir . '/' . $fingerprint . '.json';
  $now = time();
  $data = ['hits' => []];

  if (is_file($file)) {
    $raw = @file_get_contents($file);
    $json = json_decode((string)$raw, true);
    if (is_array($json)) $data = $json;
  }

  $window = 7 * 24 * 60 * 60;
  $hits = array_values(array_filter($data['hits'] ?? [], function ($t) use ($now, $window) {
    return is_int($t) && (($now - $t) < $window);
  }));

  $hits[] = $now;
  @file_put_contents($file, json_encode(['hits' => $hits]), LOCK_EX);

  $count = count($hits);
  if ($count >= 5) return 35;
  if ($count >= 3) return 20;
  return 0;
}

function analyze_spam(array $fields): array {
  $score = 0;
  $reasons = [];

  $message = clean_text((string)($fields['message'] ?? ''), 5000);
  $question = clean_text((string)($fields['question'] ?? ''), 5000);
  $name = clean_text((string)($fields['name'] ?? ''), 200);
  $email = clean_text((string)($fields['email'] ?? ''), 300);
  $company = clean_text((string)($fields['company'] ?? ''), 200);
  $website = clean_text((string)($fields['website'] ?? ''), 400);

  $combined = trim(implode("\n", array_filter([$name, $email, $company, $website, $message, $question])));
  $messageLike = trim($message . "\n" . $question);

  if ($messageLike !== '') {
    if (too_many_urls($messageLike)) {
      $score += 20;
      $reasons[] = 'multiple_urls';
    }

    $kw = suspicious_keywords_score($messageLike);
    if ($kw > 0) {
      $score += $kw;
      $reasons[] = 'spam_keywords';
    }

    $gib = gibberish_score($messageLike);
    if ($gib > 0) {
      $score += $gib;
      $reasons[] = 'gibberish_patterns';
    }

    if (strlen($messageLike) <= 12 && preg_match('~https?://|www\.|[a-z0-9.-]+\.[a-z]{2,}~iu', $messageLike)) {
      $score += 18;
      $reasons[] = 'tiny_message_with_url';
    }
  }

  if ($name !== '' && preg_match('~https?://|www\.|[a-z0-9.-]+\.[a-z]{2,}~iu', $name)) {
    $score += 20;
    $reasons[] = 'url_in_name';
  }

  if ($company !== '' && gibberish_score($company) >= 16) {
    $score += 12;
    $reasons[] = 'weird_company_name';
  }

  $fingerprintBase = mb_strtolower(trim($messageLike !== '' ? $messageLike : $combined), 'UTF-8');
  if ($fingerprintBase !== '') {
    $fingerprint = sha1($fingerprintBase);
    $fpScore = repeated_fingerprint_score($fingerprint);
    if ($fpScore > 0) {
      $score += $fpScore;
      $reasons[] = 'repeated_message_fingerprint';
    }
  }

  return [
    'score' => $score,
    'reasons' => array_values(array_unique($reasons)),
  ];
}

function timing_check_or_quarantine(array $payload): void {
  $started = (int)post('form_started', '0');
  $now = time();

  if ($started <= 0) {
    quarantine_submission('missing_form_started', $payload);
    log_security('missing_form_started', ['form-name' => post('form-name')]);
    respond(400, ['ok' => false, 'message' => 'Security check failed. Please refresh and try again.']);
  }

  $delta = $now - $started;
  if ($delta < (int)$GLOBALS['MIN_SUBMIT_SECONDS']) {
    quarantine_submission('submitted_too_fast', $payload + ['delta_seconds' => $delta]);
    log_security('submitted_too_fast', ['delta_seconds' => $delta, 'form-name' => post('form-name')]);
    respond(200, ['ok' => true, 'message' => 'Thanks!']);
  }

  if ($delta > (int)$GLOBALS['MAX_SUBMIT_SECONDS']) {
    quarantine_submission('stale_form_submission', $payload + ['delta_seconds' => $delta]);
    log_security('stale_form_submission', ['delta_seconds' => $delta, 'form-name' => post('form-name')]);
    respond(400, ['ok' => false, 'message' => 'This form expired. Please refresh and try again.']);
  }
}

// ---------- Start ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond(405, ['ok' => false, 'message' => 'Method not allowed. Use POST.']);
}

//rate_limit_or_die();

// Honeypots
$hp1 = post('website');          // hidden on index/about/faq, real field on services
$hp2 = post('company_site_hp');  // hidden on services

$formName = post('form-name');
if ($formName === '') {
  respond(400, ['ok' => false, 'message' => 'Missing form name.']);
}

if ($hp2 !== '' || ($hp1 !== '' && $formName !== 'quote-request')) {
  quarantine_submission('honeypot_filled', [
    'form-name' => $formName,
    'website' => $hp1,
    'company_site_hp' => $hp2,
    'email' => post('email'),
    'name' => post('name'),
  ]);
  log_security('honeypot_filled', ['form-name' => $formName]);
  respond(200, ['ok' => true, 'message' => 'Thanks!']);
}

timing_check_or_quarantine([
  'form-name' => $formName,
  'email' => post('email'),
  'name' => post('name'),
]);

// reCAPTCHA check
$recaptchaToken  = post('recaptcha_token');
$recaptchaAction = post('recaptcha_action');

$recaptcha = verify_recaptcha_v3($recaptchaToken, $recaptchaAction);
if (!$recaptcha['ok']) {
  quarantine_submission('recaptcha_failed', [
    'form-name' => $formName,
    'email' => post('email'),
    'name' => post('name'),
    'reason' => $recaptcha['reason'],
    'score' => $recaptcha['score'] ?? 0,
    'action' => $recaptcha['action'] ?? '',
    'hostname' => $recaptcha['hostname'] ?? '',
  ]);
  log_security('recaptcha_failed', [
    'form-name' => $formName,
    'reason' => $recaptcha['reason'],
    'score' => $recaptcha['score'] ?? 0,
    'action' => $recaptcha['action'] ?? '',
    'hostname' => $recaptcha['hostname'] ?? '',
  ]);

  respond(400, ['ok' => false, 'message' => 'Security verification failed. Please try again.']);
}

// Common fields
$name  = clean_text(post('name'), 200);
$email = clean_header_value(post('email'));

if ($email !== '' && !is_valid_email($email)) {
  respond(400, ['ok' => false, 'message' => 'Please enter a valid email address.']);
}

// Per-form routing
$subjectOwner = '';
$subjectUser  = '';
$rowsOwner = [];
$rowsUser  = [];
$userIntro = '';
$ownerIntro = '';

$spamFields = [
  'name' => $name,
  'email' => $email,
];

switch ($formName) {
  case 'faq-question': {
    $question = clean_text(post('question'), 4000);

    if ($email === '' || !is_valid_email($email)) {
      respond(400, ['ok' => false, 'message' => 'Email is required so I can reply.']);
    }
    if ($question === '') {
      respond(400, ['ok' => false, 'message' => 'Please enter your question.']);
    }

    $spamFields['question'] = $question;

    $subjectOwner = "FAQ Question - " . ($name !== '' ? $name : $email);
    $subjectUser  = "Got it - I received your FAQ question";

    $ownerIntro = "New FAQ question submission.";
    $rowsOwner = [
      'Form' => 'faq-question',
      'Name' => $name !== '' ? $name : '(not provided)',
      'Email' => $email,
      'Question' => $question,
      'reCAPTCHA score' => (string)$recaptcha['score'],
    ];

    $userIntro = "Thanks - I got your question. I'll reply to <b>" . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "</b> as soon as I can.";
    $rowsUser = [
      'What you sent' => $question,
    ];
    break;
  }

  case 'contact': {
    $role    = clean_text(post('role'), 80);
    $message = clean_text(post('message'), 4000);
    if ($message === '') $message = '(no message provided)';

    if ($name === '') {
      respond(400, ['ok' => false, 'message' => 'Name is required.']);
    }
    if ($email === '' || !is_valid_email($email)) {
      respond(400, ['ok' => false, 'message' => 'Valid email is required.']);
    }

    $spamFields['message'] = $message;

    $subjectOwner = "New Contact (Index) - {$name}";
    $subjectUser  = "Thanks - I got your message";

    $ownerIntro = "New message from the Index contact form.";
    $rowsOwner = [
      'Form' => 'contact',
      'Name' => $name,
      'Email' => $email,
      'Role' => $role !== '' ? $role : '(not selected)',
      'Message' => $message,
      'reCAPTCHA score' => (string)$recaptcha['score'],
    ];

    $userIntro = "Thanks for reaching out. I got your message and will reply to <b>" . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "</b>.";
    $rowsUser = [
      'Role' => $role !== '' ? $role : '(not selected)',
      'What you sent' => $message,
    ];
    break;
  }

  case 'contact-page': {
    $role     = clean_text(post('role'), 80);
    $callType = clean_text(post('contact-call-type'), 40);
    $message  = clean_text(post('message'), 4000);
    if ($message === '') $message = '(no message provided)';

    if ($name === '' || $email === '' || !is_valid_email($email)) {
      respond(400, ['ok' => false, 'message' => 'Name and valid email are required.']);
    }

    $spamFields['message'] = $message;

    $subjectOwner = "New Contact (About) - {$name}";
    $subjectUser  = "Thanks - I got your message";

    $ownerIntro = "New message from the About page contact form.";
    $rowsOwner = [
      'Form' => 'contact-page',
      'Name' => $name,
      'Email' => $email,
      'Role' => $role !== '' ? $role : '(not selected)',
      'Preferred' => $callType !== '' ? $callType : '(not selected)',
      'Message' => $message,
      'reCAPTCHA score' => (string)$recaptcha['score'],
    ];

    $userIntro = "Thanks for reaching out. I got your note and will reply to <b>" . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "</b>.";
    $rowsUser = [
      'Role' => $role !== '' ? $role : '(not selected)',
      'Preferred' => $callType !== '' ? $callType : '(not selected)',
      'What you sent' => $message,
    ];
    break;
  }

  case 'quote-request': {
    $company  = clean_text(post('company'), 200);
    $website  = clean_text(post('website'), 400);
    $callType = clean_text(post('call-type'), 40);
    $message  = clean_text(post('message'), 4000);
    if ($message === '') $message = '(no message provided)';
    $selected = clean_text(post('selected-services'), 4000);

    if ($name === '' || $email === '' || !is_valid_email($email)) {
      respond(400, ['ok' => false, 'message' => 'Name and valid email are required.']);
    }

    $spamFields['company'] = $company;
    $spamFields['website'] = $website;
    $spamFields['message'] = $message;

    $subjectOwner = "Quote Request - {$name}" . ($company !== '' ? " ({$company})" : '');
    $subjectUser  = "Quote request received - next steps";

    $ownerIntro = "New quote request from Services.";
    $rowsOwner = [
      'Form' => 'quote-request',
      'Name' => $name,
      'Email' => $email,
      'Company' => $company !== '' ? $company : '(not provided)',
      'Current site' => $website !== '' ? $website : '(not provided)',
      'Preferred' => $callType !== '' ? $callType : '(not selected)',
      'Selected services' => $selected !== '' ? $selected : '(none)',
      'Message' => $message,
      'reCAPTCHA score' => (string)$recaptcha['score'],
    ];

    $userIntro = "Thanks - I got your quote request. I'll follow up at <b>" . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "</b> with questions / timing.";
    $rowsUser = [
      'Preferred' => $callType !== '' ? $callType : '(not selected)',
      'Selected services' => $selected !== '' ? $selected : '(none)',
      'Notes' => $message,
    ];
    break;
  }

  default:
    respond(400, ['ok' => false, 'message' => 'Unknown form.']);
}

// server-side spam scoring
$spam = analyze_spam($spamFields);

$totalSpamScore = $spam['score'];
if (($recaptcha['score'] ?? 0) < 0.70) $totalSpamScore += 10;
if (($recaptcha['score'] ?? 0) < 0.60) $totalSpamScore += 10;

if ($totalSpamScore >= 45) {
  quarantine_submission('spam_score_blocked', [
    'form-name' => $formName,
    'email' => $email,
    'name' => $name,
    'recaptcha_score' => $recaptcha['score'] ?? 0,
    'spam_score' => $totalSpamScore,
    'spam_reasons' => $spam['reasons'],
    'post' => $_POST,
  ]);

  log_security('spam_score_blocked', [
    'form-name' => $formName,
    'email' => $email,
    'name' => $name,
    'recaptcha_score' => $recaptcha['score'] ?? 0,
    'spam_score' => $totalSpamScore,
    'spam_reasons' => $spam['reasons'],
  ]);

  respond(200, ['ok' => true, 'message' => 'Thanks! Your message has been received.']);
}

// Build emails
$ownerHtml = hud_email_html(
  "New submission",
  $ownerIntro,
  $rowsOwner + [
    'Spam score' => (string)$totalSpamScore,
    'Spam flags' => !empty($spam['reasons']) ? implode(', ', $spam['reasons']) : 'none',
  ],
  "Reply directly to this email to respond (Reply-To is set to the sender)."
);

$ownerText = $ownerIntro . "\n\n";
foreach (($rowsOwner + [
  'Spam score' => (string)$totalSpamScore,
  'Spam flags' => !empty($spam['reasons']) ? implode(', ', $spam['reasons']) : 'none',
]) as $k => $v) {
  $ownerText .= "{$k}: " . (is_string($v) ? $v : '') . "\n";
}

$userHtml = hud_email_html(
  "Message received",
  $userIntro,
  $rowsUser,
  "If you didn't send this, you can ignore it."
);

$userText = "Message received.\n\n";
foreach ($rowsUser as $k => $v) {
  $userText .= "{$k}: " . (is_string($v) ? $v : '') . "\n";
}

// Send owner email
$replyTo = ($email !== '' && is_valid_email($email)) ? $email : $REPLY_FALLBACK;
$okOwner = send_multipart_mail($OWNER_EMAIL, $FROM_EMAIL, $replyTo, $subjectOwner, $ownerText, $ownerHtml);

// Send user confirmation
$okUser = true;
if ($email !== '' && is_valid_email($email)) {
  $okUser = send_multipart_mail($email, $FROM_EMAIL, $OWNER_EMAIL, $subjectUser, $userText, $userHtml);
}

if (!$okOwner) {
  respond(500, ['ok' => false, 'message' => 'Mail server error. Please try again, or email directly.']);
}

respond(200, [
  'ok' => true,
  'message' => 'Sent! Check your inbox for a confirmation email.',
  'owner' => $okOwner,
  'user' => $okUser
]);