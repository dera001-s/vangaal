<?php
// Receives the contact form (sent as JSON) and saves it into the `contacts` table.

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Checks a Nigerian number: starts with +234, 234 or 0, then exactly 10 digits.
function validNigerianPhone(string $digits): bool
{
    if (str_starts_with($digits, '+234')) {
        $rest = substr($digits, 4);
    } elseif (str_starts_with($digits, '234')) {
        $rest = substr($digits, 3);
    } elseif (str_starts_with($digits, '0')) {
        $rest = substr($digits, 1);
    } else {
        return false;
    }
    return strlen($rest) === 10 && ctype_digit($rest);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Method not allowed.']);
}

require __DIR__ . '/db.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['error' => 'Invalid request.']);
}

// Hidden spam-trap field: only matters if the field exists in the form.
if (!empty($input['contact_extra'])) {
    respond(200, ['ok' => true]);
}

$name    = trim((string)($input['name'] ?? ''));
$email   = trim((string)($input['email'] ?? ''));
$phone   = trim((string)($input['phone'] ?? ''));
$message = trim((string)($input['message'] ?? ''));
$digits  = str_replace([' ', '-'], '', $phone);

$valid =
    $name !== '' && mb_strlen($name) <= 100 &&
    filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 150 &&
    validNigerianPhone($digits) &&
    $message !== '' && mb_strlen($message) <= 2000;

if (!$valid) {
    respond(400, ['error' => 'Please check your details and try again.']);
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO contacts (name, email, phone, message, createdAt, updatedAt)
         VALUES (?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([$name, $email, $phone, $message]);
    respond(201, ['ok' => true]);
} catch (PDOException $e) {
    error_log('Save contact failed: ' . $e->getMessage());
    respond(500, ['error' => 'Could not save your message. Please try again later.']);
}