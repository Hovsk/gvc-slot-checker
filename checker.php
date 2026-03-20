<?php

$TELEGRAM_TOKEN   = getenv('TELEGRAM_TOKEN');
$TELEGRAM_CHAT_ID = getenv('TELEGRAM_CHAT_ID');
$GVC_SESSION      = getenv('GVC_SESSION');
$GVC_BASE         = 'https://am-gr-services.gvcworld.eu';

function getDates(): array {
    $dates = [];
    $start = new DateTime('+8 days');
    $end   = new DateTime('+22 days');
    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        if ((int)$d->format('N') <= 5) {
            $dates[] = $d->format('d/m/Y');
        }
    }
    return $dates;
}

function telegram(string $msg): void {
    global $TELEGRAM_TOKEN, $TELEGRAM_CHAT_ID;
    $ch = curl_init("https://api.telegram.org/bot{$TELEGRAM_TOKEN}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'chat_id'    => $TELEGRAM_CHAT_ID,
            'text'       => $msg,
            'parse_mode' => 'HTML',
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
    echo "Telegram: " . substr($msg, 0, 80) . "\n";
}

function request(string $url, array $post = []): array {
    global $GVC_SESSION;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIE         => "JSESSIONID={$GVC_SESSION}",
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: application/json, text/html, */*',
            'X-Requested-With: XMLHttpRequest',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);
    if (!empty($post)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'code' => $code];
}

function sessionValid(): bool {
    global $GVC_BASE;
    $r = request("{$GVC_BASE}/appointments/add");
    $ok = stripos($r['body'], 'sign in') === false && stripos($r['body'], 'login') === false;
    echo $ok ? "Session valid!\n" : "Session invalid!\n";
    return $ok;
}

function getSlots(string $date): array {
    global $GVC_BASE;
    $r = request("{$GVC_BASE}/appointments/slots", [
        'datefrom'          => $date,
        'bookingfor'        => '1',
        'members'           => '2',
        'type'              => '0',
        'appointmentmethod' => '1',
        'vac'               => '1',
    ]);
    if (empty($r['body'])) return [];

    // Try JSON
    $json = json_decode($r['body'], true);
    if ($json !== null) {
        $slots = $json['slots'] ?? $json['periods'] ?? $json['data'] ?? $json;
        if (!is_array($slots)) return [];
        $times = [];
        foreach ($slots as $s) {
            if (!is_array($s)) continue;
            $t = $s['time'] ?? $s['period'] ?? $s['label'] ?? '';
            $booked = $s['booked'] ?? false;
            if ($t && !$booked) $times[] = substr((string)$t, 0, 5);
        }
        return array_unique($times);
    }

    // Try HTML
    foreach (['cannot book','no results','no availability','fully booked'] as $p) {
        if (stripos($r['body'], $p) !== false) return [];
    }
    preg_match_all('/\b(\d{2}:\d{2})\b/', $r['body'], $m);
    return array_values(array_filter(array_unique($m[1]), fn($t) => $t !== '00:00'));
}

// ── Main ──────────────────────────────────────────────────────────────────────
echo "\n=== GVC Slot Check — " . date('Y-m-d H:i') . " ===\n";

if (!sessionValid()) {
    telegram(
        "⚠️ <b>GVC Session expired!</b>\n\n" .
        "Log in to GVC → F12 → Application → Cookies → copy JSESSIONID\n" .
        "Then update GVC_SESSION secret in GitHub repo settings."
    );
    exit(1);
}

$dates = getDates();
echo "Checking " . count($dates) . " dates: {$dates[0]} → " . end($dates) . "\n";

$found = false;
foreach ($dates as $date) {
    echo "  {$date}... ";
    $slots = getSlots($date);
    if (!empty($slots)) {
        $times = implode(' · ', $slots);
        echo "FOUND: {$times}\n";
        telegram(
            "🚨🚨🚨 <b>GVC SLOT AVAILABLE!</b> 🚨🚨🚨\n\n" .
            "📅 Date: <b>{$date}</b>\n" .
            "🕐 Times: <b>{$times}</b>\n\n" .
            "👉 <a href=\"https://am-gr-services.gvcworld.eu/appointments/add\">Book NOW!</a>\n\n" .
            "⚡ Act fast — slots disappear in minutes!"
        );
        $found = true;
    } else {
        echo "no slots\n";
    }
    usleep(1500000);
}

if (!$found) {
    echo "No slots found.\n";
    $utcHour = (int)gmdate('H');
    $utcMin  = (int)gmdate('i');
    if ($utcHour === 4 && $utcMin < 50) {
        telegram(
            "👁️ <b>GVC Watcher started</b>\n" .
            "Checking " . count($dates) . " dates every 5 min\n" .
            "Range: {$dates[0]} → " . end($dates)
        );
    }
}
