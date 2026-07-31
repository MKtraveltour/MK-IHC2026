<?php
/**
 * send-reservation.php
 * ---------------------------------------------------------------
 * Generic reservation-form mail handler for MK KYOTO.
 *
 * Receives a JSON POST body from any of the reservation forms
 * (kyoto-mk-reservation.html, airport-transfer-premium.html,
 * airport-transfer-standard.html) and sends:
 *   1. A notification email to MK staff (charter@mk-group.co.jp)
 *      containing every field the customer submitted.
 *   2. A confirmation email to the customer's own email address.
 *
 * This script uses PHP's built-in mail() function so it will run
 * on almost any standard PHP hosting environment with zero extra
 * libraries. If mail() is not reliably delivering on the eventual
 * production server (common on some hosts without a configured
 * MTA), swap the sendMail() function below for PHPMailer + SMTP —
 * everything else in this file can stay the same.
 * ---------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');

// ---- CORS: allow the reservation pages to call this endpoint ----
// Once the production domain is known, replace '*' with that exact
// origin (e.g. https://www.mk-group.co.jp) for better security.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ---- Config -------------------------------------------------------
const ADMIN_EMAIL   = 'charter@mk-group.co.jp';
const MAIL_FROM      = 'noreply@mk-group.co.jp'; // update to a real sending address once the domain/server is confirmed
const SITE_NAME       = 'MK KYOTO';

// ---- Read + decode the submitted JSON ------------------------------
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing JSON body']);
    exit;
}

$serviceLabel   = isset($payload['serviceLabel']) ? trim($payload['serviceLabel']) : 'Reservation';
$customerEmail  = isset($payload['customerEmail']) ? trim($payload['customerEmail']) : '';
$customerName   = isset($payload['customerName']) ? trim($payload['customerName']) : '';
$fields         = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : [];

if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'A valid customer email address is required']);
    exit;
}

// ---- Build a readable field list for the admin notification -------
$lines = [];
foreach ($fields as $key => $value) {
    if ($value === '' || $value === null) continue;
    if (is_array($value)) $value = implode(', ', $value);
    $lines[] = str_pad($key, 16) . ': ' . $value;
}
$fieldDump = implode("\n", $lines);

// ---- Compose admin notification email ------------------------------
$adminSubject = "[$serviceLabel] New reservation request — $customerName";
$adminBody =
    "A new reservation request was submitted on the $serviceLabel form.\n\n" .
    "Customer name : $customerName\n" .
    "Customer email: $customerEmail\n\n" .
    "----- All submitted fields -----\n" .
    $fieldDump . "\n" .
    "---------------------------------\n\n" .
    "This reservation is not yet confirmed. Please contact the customer directly to confirm details, availability, and payment.";

// ---- Compose customer confirmation email ---------------------------
$custSubject = "[$serviceLabel] We've received your reservation request — " . SITE_NAME;
$custBody =
    ($customerName !== '' ? "Dear $customerName,\n\n" : "Dear Guest,\n\n") .
    "Thank you for your reservation request with " . SITE_NAME . ".\n\n" .
    "We have received the following request:\n\n" .
    $fieldDump . "\n\n" .
    "This is an automatic acknowledgement only — your reservation is NOT yet confirmed. " .
    "Our staff will review your request and contact you shortly to confirm availability and details.\n\n" .
    "If you have any urgent questions, please contact us directly.\n\n" .
    "Thank you,\n" . SITE_NAME;

// ---- Send both emails ------------------------------------------------
$adminSent = sendMail(ADMIN_EMAIL, $adminSubject, $adminBody);
$custSent  = sendMail($customerEmail, $custSubject, $custBody);

if ($adminSent && $custSent) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'One or more emails failed to send',
        'adminSent' => $adminSent,
        'customerSent' => $custSent,
    ]);
}

/**
 * Thin wrapper around mail() so the sending method can be swapped
 * out later (e.g. for PHPMailer + SMTP) without touching the rest
 * of this file.
 */
function sendMail(string $to, string $subject, string $body): bool
{
    $headers = [];
    $headers[] = 'From: ' . SITE_NAME . ' <' . MAIL_FROM . '>';
    $headers[] = 'Reply-To: ' . ADMIN_EMAIL;
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    return mail($to, $encodedSubject, $body, implode("\r\n", $headers));
}
