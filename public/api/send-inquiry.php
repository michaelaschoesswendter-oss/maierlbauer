<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false]);
}

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    respond(413, ['ok' => false]);
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    respond(400, ['ok' => false]);
}

// Simple honeypot: bots often fill hidden fields; silently discard those submissions.
if (trim((string)($input['website'] ?? '')) !== '') {
    respond(200, ['ok' => true]);
}

$clean = static function (string $key, int $max = 200) use ($input): string {
    $value = trim((string)($input[$key] ?? ''));
    $value = str_replace("\0", '', $value);
    $value = preg_replace('/[\r\n\t]+/u', ' ', $value) ?? '';
    return mb_substr($value, 0, $max, 'UTF-8');
};

$first = $clean('first', 100);
$last = $clean('last', 100);
$email = $clean('email', 254);
$arrival = $clean('arrival', 10);
$departure = $clean('departure', 10);
$adults = filter_var($input['adults'] ?? null, FILTER_VALIDATE_INT);
$children = filter_var($input['children'] ?? 0, FILTER_VALIDATE_INT);

$dateIsValid = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !$dateIsValid($arrival) || !$dateIsValid($departure) || $departure <= $arrival
    || $adults === false || $children === false || $adults < 1 || $children < 0 || $adults + $children > 2) {
    respond(422, ['ok' => false]);
}

try {
    $configPath = __DIR__ . '/mail-config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('Mail configuration is missing');
    }
    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('Mail configuration is invalid');
    }

    $host = (string)($config['host'] ?? 'smtp.cablelink.at');
    $port = (int)($config['port'] ?? 587);
    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');
    $from = (string)($config['from'] ?? '');
    $to = (string)($config['to'] ?? 'info@maierlbauer.at');
    if ($username === '' || $password === '' || $password === 'SET_ON_SERVER'
        || !filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Mail configuration is incomplete');
    }

    $salutation = $clean('salutation', 80);
    $degree = $clean('degree', 80);
    $street = $clean('street', 200);
    $postcodeCity = $clean('postcodeCity', 160);
    $country = $clean('country', 100);
    $phone = $clean('phone', 80);
    $message = trim((string)($input['message'] ?? ''));
    $message = str_replace("\0", '', $message);
    $message = mb_substr($message, 0, 5000, 'UTF-8');
    $fullName = trim(implode(' ', array_filter([$salutation, $degree, $first, $last])));
    $body = "Neue unverbindliche Anfrage über maierlbauer.at\n\n"
        . "Name: {$fullName}\nE-Mail: {$email}\nTelefon: {$phone}\n"
        . "Adresse: {$street}\nPLZ / Ort: {$postcodeCity}\nLand: {$country}\n\n"
        . "Anreise: {$arrival}\nAbreise: {$departure}\nErwachsene: {$adults}\nKinder: {$children}\n\n"
        . "Nachricht:\n{$message}\n";

    $socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if ($socket === false) {
        throw new RuntimeException('Could not connect to SMTP server');
    }
    stream_set_timeout($socket, 20);

    $readResponse = static function (array $expected) use ($socket): string {
        $response = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) {
                throw new RuntimeException('SMTP connection closed unexpectedly');
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP command failed with status ' . $code);
        }
        return $response;
    };
    $command = static function (string $line, array $expected) use ($socket, $readResponse): string {
        if (fwrite($socket, $line . "\r\n") === false) {
            throw new RuntimeException('Could not write SMTP command');
        }
        return $readResponse($expected);
    };

    try {
        $readResponse([220]);
        $command('EHLO maierlbauer.at', [250]);
        $command('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Could not establish TLS');
        }
        $command('EHLO maierlbauer.at', [250]);
        $command('AUTH LOGIN', [334]);
        $command(base64_encode($username), [334]);
        $command(base64_encode($password), [235]);
        $command('MAIL FROM:<' . $from . '>', [250]);
        $command('RCPT TO:<' . $to . '>', [250, 251]);
        $command('DATA', [354]);

        $subject = '=?UTF-8?B?' . base64_encode('Unverbindliche Anfrage – Maierlbauer Apartments') . '?=';
        $headers = [
            'From: Maierlbauer Apartments <' . $from . '>',
            'To: <' . $to . '>',
            'Reply-To: ' . $email,
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $encodedBody = rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
        $mailData = implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;
        $mailData = preg_replace('/(?m)^\./', '..', $mailData) ?? $mailData;
        if (fwrite($socket, $mailData . "\r\n.\r\n") === false) {
            throw new RuntimeException('Could not submit email');
        }
        $readResponse([250]);
        $command('QUIT', [221]);
    } finally {
        fclose($socket);
    }

    respond(200, ['ok' => true]);
} catch (Throwable $error) {
    error_log('Maierlbauer inquiry mail failed: ' . $error->getMessage());
    respond(503, ['ok' => false]);
}