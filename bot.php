<?php
// bot.php – IMAP → OpenAI → SMTP → zapis do Sent
// Minimalna wersja działająca na zwykłym hostingu (bez dodatkowych bibliotek)

declare(strict_types=1);

date_default_timezone_set('UTC');

$config = require __DIR__ . '/config.php';
$prompt = require __DIR__ . '/prompt.php';

const LOG_FILE = __DIR__ . '/bot.log';
const PROCESSED_FILE = __DIR__ . '/processed.json';

function log_line(string $msg): void
{
    file_put_contents(LOG_FILE, '[' . date('c') . "] $msg\n", FILE_APPEND);
}

function load_processed(): array
{
    if (!file_exists(PROCESSED_FILE)) {
        return [];
    }

    $decoded = json_decode(file_get_contents(PROCESSED_FILE) ?: '[]', true);
    return is_array($decoded) ? $decoded : [];
}

function save_processed(array $processed): void
{
    file_put_contents(
        PROCESSED_FILE,
        json_encode(array_values(array_unique($processed)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

function decode_header(string $value): string
{
    $parts = imap_mime_header_decode($value);
    $decoded = '';
    foreach ($parts as $part) {
        $decoded .= $part->text;
    }
    return trim($decoded);
}

function open_imap(array $config)
{
    $mailbox = $config['imap']['mailbox'];
    $username = $config['imap']['username'];
    $password = $config['imap']['password'];

    $connection = @imap_open($mailbox, $username, $password);
    if (!$connection) {
        throw new RuntimeException('IMAP error: ' . imap_last_error());
    }

    return $connection;
}

function fetch_unseen_uids($imap): array
{
    return imap_search($imap, 'UNSEEN', SE_UID) ?: [];
}

function read_email($imap, int $uid): array
{
    $overviewList = imap_fetch_overview($imap, (string)$uid, FT_UID);
    $overview = $overviewList[0] ?? null;

    $fromMailbox = $overview->from ?? '';
    $subject = $overview->subject ?? '(bez tematu)';

    $structure = imap_fetchstructure($imap, $uid, FT_UID);
    $body = '';

    if ($structure && property_exists($structure, 'parts') && is_array($structure->parts)) {
        foreach ($structure->parts as $index => $part) {
            if ($part->type === 0) { // text
                $segment = imap_fetchbody($imap, $uid, (string)($index + 1), FT_UID | FT_PEEK);
                $body = decode_part($segment, $part);
                break;
            }
        }
    }

    if ($body === '') {
        $raw = imap_body($imap, $uid, FT_UID | FT_PEEK);
        $body = decode_part($raw, $structure);
    }

    return [
        'from' => decode_header($fromMailbox),
        'subject' => decode_header($subject),
        'body' => trim($body),
    ];
}

function decode_part(string $text, $part = null): string
{
    if ($part && isset($part->encoding)) {
        switch ((int)$part->encoding) {
            case ENCBASE64:
                $text = base64_decode($text) ?: $text;
                break;
            case ENCQUOTEDPRINTABLE:
                $text = quoted_printable_decode($text);
                break;
        }
    }

    $charset = null;
    if ($part && isset($part->parameters)) {
        foreach ($part->parameters as $param) {
            if (strtolower($param->attribute) === 'charset') {
                $charset = $param->value;
                break;
            }
        }
    }

    if ($charset && strtolower($charset) !== 'utf-8') {
        $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
        if ($converted !== false) {
            $text = $converted;
        }
    }

    return trim($text);
}

function call_openai(array $config, string $prompt, string $emailText): ?string
{
    $payload = [
        'model' => $config['openai']['model'],
        'messages' => [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => $emailText],
        ],
        'temperature' => 0.2,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['openai']['api_key'],
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 20,
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
        log_line('cURL error: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }

    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code >= 300) {
        log_line('OpenAI HTTP ' . $code . ': ' . $resp);
        return null;
    }

    $json = json_decode($resp, true);
    return $json['choices'][0]['message']['content'] ?? null;
}

function smtp_send(array $smtp, string $to, string $subject, string $body): bool
{
    $fp = @stream_socket_client('tcp://' . $smtp['host'] . ':' . $smtp['port'], $errno, $errstr, 15);
    if (!$fp) {
        log_line("SMTP connect failed: $errstr");
        return false;
    }

    $read = fn() => fgets($fp, 515) ?: '';
    $expect = function (int $code) use ($read) {
        $line = $read();
        if (!str_starts_with($line, (string)$code)) {
            throw new RuntimeException("SMTP unexpected: $line");
        }
        return $line;
    };
    $send = function (string $cmd) use ($fp, $read) {
        fwrite($fp, $cmd . "\r\n");
        return $read();
    };

    $expect(220);
    $send('EHLO ' . (gethostname() ?: 'localhost'));

    if (!empty($smtp['username'])) {
        $expect(250);
        $send('AUTH LOGIN');
        $expect(334);
        $send(base64_encode($smtp['username']));
        $expect(334);
        $send(base64_encode($smtp['password']));
        $expect(235);
    }

    $fromAddr = preg_replace('/.*<(.*)>.*/', '$1', $smtp['from']);
    $send('MAIL FROM: <' . $fromAddr . '>');
    $expect(250);
    $send('RCPT TO: <' . $to . '>');
    $expect(250);
    $send('DATA');
    $expect(354);

    $headers = [
        'From: ' . $smtp['from'],
        'To: ' . $to,
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Date: ' . date('r'),
        'Message-ID: <' . uniqid('', true) . '@' . ($smtp['host'] ?? 'localhost') . '>',
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    $send($message);
    $expect(250);
    $send('QUIT');
    fclose($fp);
    return true;
}

function append_to_sent($imap, array $config, string $rawMessage): void
{
    @imap_append($imap, $config['imap']['sentMailbox'], $rawMessage . "\r\n");
}

try {
    $imap = open_imap($config);
} catch (Throwable $e) {
    log_line($e->getMessage());
    exit(1);
}

$processed = load_processed();
$uids = fetch_unseen_uids($imap);

foreach ($uids as $uid) {
    if (in_array($uid, $processed, true)) {
        continue;
    }

    $email = read_email($imap, (int)$uid);
    if ($email['from'] === '') {
        log_line('Pomijam UID ' . $uid . ' – brak nadawcy');
        continue;
    }

    $emailText = "Nadawca: {$email['from']}\nTemat: {$email['subject']}\nTreść:\n{$email['body']}";
    $reply = call_openai($config, $prompt, $emailText);
    if ($reply === null) {
        log_line('Brak odpowiedzi od OpenAI dla UID ' . $uid);
        continue;
    }

    $replyBody = $reply . "\n\n--\nZespół Supportu";
    $sent = smtp_send($config['smtp'], $email['from'], 'Re: ' . $email['subject'], $replyBody);
    if (!$sent) {
        log_line('Nie udało się wysłać odpowiedzi do ' . $email['from']);
        continue;
    }

    $rawMessage = "From: {$config['smtp']['from']}\r\n" .
        "To: {$email['from']}\r\n" .
        "Subject: Re: {$email['subject']}\r\n" .
        "Date: " . date('r') . "\r\n" .
        "MIME-Version: 1.0\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n\r\n" .
        $replyBody;

    append_to_sent($imap, $config, $rawMessage);

    imap_setflag_full($imap, (string)$uid, "\\Seen", ST_UID);

    $processed[] = $uid;
    save_processed($processed);
    log_line('Obsłużono UID ' . $uid);
}

imap_close($imap);
