<?php

// ── Config from environment variables ────────────────────────────────────────
$TELEGRAM_TOKEN   = getenv('TELEGRAM_TOKEN');
$TELEGRAM_CHAT_ID = getenv('TELEGRAM_CHAT_ID');
$GVC_SESSION      = getenv('GVC_SESSION'); // JSESSIONID cookie value

$GVC_BASE         = 'https://am-gr-services.gvcworld.eu';

// ── Date range: 1 week from now → 3 weeks from now (workdays only) ───────────
function getDatesToCheck(): array {
    $dates = [];
    $start = new DateTime('+8 days');
    $end   = new DateTime('+22 days');

    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        if ((int)$d->format('N') <= 5) { // Mon–Fri
            $dates[] = $d->format('d/m/Y');
        }
    }
    return $dates;
}

// ── Telegram ──────────────────────────────────────────────────────────────────
function sendTelegram(string $message): void {
    global $TELEGRAM_TOKEN, $TELEGRAM_CHAT_ID;

    $ch = curl_init("https://api.telegram.org/bot{$TELEGRAM_TOKEN}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'chat_id'              => $TELEGRAM_CHAT_ID,
            'text'                 => $message,
            'parse_mode'           => 'HTML',
            'disable_notification' => false,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
    echo "Telegram sent: " . substr($message, 0, 60) . "...\n";
}

function sendAlert(string $date, array $times): void {
    $timesStr = implode('  ·  ', $times);
    sendTelegram(
        "🚨🚨🚨 <b>GVC SLOT AVAILABLE!</b> 🚨🚨🚨\n\n" .
        "📅 Date: <b>{$date}</b>\n" .
        "🕐 Times: <b>{$timesStr}</b>\n\n" .
        "👉 <a href=\"https://am-gr-services.gvcworld.eu/appointments/add\">Book NOW!</a>\n\n" .
        "⚡ Act fast — slots disappear in minutes!"
    );
}

// ── HTTP request using session cookie ────────────────────────────────────────
function makeRequest(string $url, array $postData = []): string {
    global $GVC_SESSION;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIE         => "JSESSIONID={$GVC_SESSION}",
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept-Language: en-US,en;q=0.9',
            'Accept: application/json, text/html, */*',
            'X-Requested-With: XMLHttpRequest',
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if (!empty($postData)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Session expired?
    if ($httpCode === 401 || $httpCode === 302 || stripos((string)$response, 'sign in') !== false) {
        sendTelegram(
            "⚠️ <b>GVC Session expired!</b>\n\n" .
            "Please update the GVC_SESSION secret with a fresh JSESSIONID cookie.\n\n" .
            "1. Log in to GVC in Chrome\n" .
            "2. F12 → Application → Cookies\n" .
            "3. Copy JSESSIONID value\n" .
            "4. Update secret in GitHub → Settings → Secrets"
        );
        exit(1);
    }

    return (string)$response;
}

// ── Verify session is valid ───────────────────────────────────────────────────
function verifySession(): bool {
    global $GVC_BASE;
    echo "Verifying session...\n";

    $response = makeRequest("{$GVC_BASE}/appointments/add");

    if (stripos($response, 'sign in') !== false || stripos($response, 'login') !== false) {
        echo "Session invalid!\n";
        return false;
    }

    echo "Session valid!\n";
    return true;
}

// ── Check slots for one date ──────────────────────────────────────────────────
function checkDate(string $date): array {
    global $GVC_BASE;

    $response = makeRequest("{$GVC_BASE}/appointments/slots", [
        'datefrom'          => $date,
        'bookingfor'        => '1',  // Group
        'members'           => '2',  // 2 people
        'type'              => '0',  // Schengen Type C
        'appointmentmethod' => '1',  // Same time
        'vac'               => '1',  // Yerevan VAC
    ]);

    if (empty($response)) return [];

    // Try JSON first
    $data = json_decode($response, true);
    if ($data !== null) {
        return parseJsonSlots($data);
    }

    return parseHtmlSlots($response);
}

function parseJsonSlots(mixed $data): array {
    $available = [];
    $slots = $data['slots'] ?? $data['periods'] ?? $data['data'] ?? $data;
    if (!is_array($slots)) return [];

    foreach ($slots as $slot) {
        if (!is_array($slot)) continue;
        $time   = $slot['time'] ?? $slot['period'] ?? $slot['label'] ?? '';
        $booked = $slot['booked'] ?? !($slot['available'] ?? true);
        if ($time && !$booked) {
            $available[] = substr((string)$time, 0, 5);
        }
    }
    return array_unique($available);
}

function parseHtmlSlots(string $html): array {
    $noSlotPhrases = ['cannot book', 'no results', 'no availability', 'fully booked'];
    foreach ($noSlotPhrases as $phrase) {
        if (stripos($html, $phrase) !== false) return [];
    }

    preg_match_all('/\b(\d{2}:\d{2})\b/', $html, $matches);
    $times = array_unique($matches[1]);
    return array_values(array_filter($times, fn($t) => $t !== '00:00'));
}

// ── Main ──────────────────────────────────────────────────────────────────────
function main(): void {
    $now = new DateTime();
    echo "\n=== GVC Slot Check — " . $now->format('Y-m-d H:i') . " ===\n";

    if (!verifySession()) {
        exit(1);
    }

    $dates = getDatesToCheck();
    echo "Checking " . count($dates) . " dates from {$dates[0]} to " . end($dates) . "\n";

    $foundAny = false;

    foreach ($dates as $date) {
        echo "  Checking {$date}... ";
        $slots = checkDate($date);

        if (!empty($slots)) {
            echo "SLOTS FOUND: " . implode(', ', $slots) . "\n";
            sendAlert($date, $slots);
            $foundAny = true;
        } else {
            echo "no slots\n";
        }

        usleep(1500000); // 1.5s delay between requests
    }

    if (!$foundAny) {
        echo "No slots found.\n";

        // Send daily start message at ~8:45 AM Yerevan (04:45 UTC)
        $utcHour   = (int)(new DateTime('now', new DateTimeZone('UTC')))->format('H');
        $utcMinute = (int)(new DateTime('now', new DateTimeZone('UTC')))->format('i');

        if ($utcHour === 4 && $utcMinute < 50) {
            sendTelegram(
                "👁️ <b>GVC Watcher started</b>\n" .
                "Checking " . count($dates) . " dates every 5 min\n" .
                "Range: {$dates[0]} → " . end($dates)
            );
        }
    }
}

main();
