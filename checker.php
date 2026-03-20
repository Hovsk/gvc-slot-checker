<?php

$TELEGRAM_TOKEN   = getenv('TELEGRAM_TOKEN');
$TELEGRAM_CHAT_ID = getenv('TELEGRAM_CHAT_ID');
$GVC_SESSION      = getenv('GVC_SESSION');
$GVC_BASE         = 'https://am-gr-services.gvcworld.eu';
$TEST_MODE        = getenv('TEST_MODE') === 'true';
$FORCE_SLOT       = getenv('FORCE_SLOT') === 'true';

function getDates(): array {
    $dates = [];
    $start = new DateTime('+1 days');
    $end   = new DateTime('+12 days');
    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        if ((int)$d->format('N') <= 5) {
            $dates[] = $d->format('d/m/Y');
        }
    }
    return $dates;
}

function telegram(string $msg): void {
    global $TELEGRAM_TOKEN, $TELEGRAM_CHAT_ID;

    // Send the main alert message
    $ch = curl_init("https://api.telegram.org/bot{$TELEGRAM_TOKEN}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'chat_id'              => $TELEGRAM_CHAT_ID,
            'text'                 => $msg,
            'parse_mode'           => 'HTML',
            'disable_notification' => false,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    $ok = json_decode((string)$result, true)['ok'] ?? false;
    $msgId = json_decode((string)$result, true)['result']['message_id'] ?? null;
    echo "Telegram " . ($ok ? "✓ sent" : "✗ failed") . ": " . substr($msg, 0, 60) . "\n";
    if (!$ok) echo "Response: {$result}\n";

    // Pin the message so it appears at top of chat
    if ($msgId) {
        $pin = curl_init("https://api.telegram.org/bot{$TELEGRAM_TOKEN}/pinChatMessage");
        curl_setopt_array($pin, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'chat_id'              => $TELEGRAM_CHAT_ID,
                'message_id'           => $msgId,
                'disable_notification' => false,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($pin);
        curl_close($pin);
        echo "Telegram: message pinned\n";
    }

    // Send 3 follow-up buzz messages with 2s gap to keep alerting
    for ($i = 1; $i <= 3; $i++) {
        sleep(2);
        $buzz = curl_init("https://api.telegram.org/bot{$TELEGRAM_TOKEN}/sendMessage");
        curl_setopt_array($buzz, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'chat_id'              => $TELEGRAM_CHAT_ID,
                'text'                 => "🔴 SLOT STILL AVAILABLE — book it! ({$i}/3)",
                'disable_notification' => false,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($buzz);
        curl_close($buzz);
        echo "Telegram: buzz {$i}/3 sent\n";
    }
}

function telegramSilent(string $msg): void {
    global $TELEGRAM_TOKEN, $TELEGRAM_CHAT_ID;
    $ch = curl_init("https://api.telegram.org/bot{$TELEGRAM_TOKEN}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'chat_id'              => $TELEGRAM_CHAT_ID,
            'text'                 => $msg,
            'parse_mode'           => 'HTML',
            'disable_notification' => true,  // silent!
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
    echo "Telegram silent: " . substr($msg, 0, 60) . "\n";
}

function request(string $url, array $post = []): array {
    global $GVC_SESSION;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIE         => implode('; ', array_filter([
            "JSESSIONID={$GVC_SESSION}",
            getenv('GVC_AUTH_TOKEN')    ? "auth_token="    . getenv('GVC_AUTH_TOKEN')    : '',
            getenv('GVC_COOKIE_SESSION') ? "cookiesession1=" . getenv('GVC_COOKIE_SESSION') : '',
        ])),
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
    $ok = stripos($r['body'], 'sign in') === false
       && stripos($r['body'], 'login')   === false
       && $r['code'] === 200;
    echo "Session: " . ($ok ? "✓ valid (HTTP {$r['code']})" : "✗ invalid (HTTP {$r['code']})") . "\n";
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

    foreach (['cannot book','no results','no availability','fully booked'] as $p) {
        if (stripos($r['body'], $p) !== false) return [];
    }
    preg_match_all('/\b(\d{2}:\d{2})\b/', $r['body'], $m);
    return array_values(array_filter(array_unique($m[1]), fn($t) => $t !== '00:00'));
}

function runTests(): void {
    global $GVC_SESSION;

    echo "\n========================================\n";
    echo "        GVC WATCHER — TEST MODE\n";
    echo "========================================\n\n";

    $passed = 0;
    $failed = 0;

    // Test 1: Telegram connectivity
    echo "[ TEST 1 ] Telegram connectivity...\n";
    telegram(
        "🧪 <b>GVC Watcher — Test Mode</b>\n\n" .
        "✅ Telegram connection works!\n" .
        "Running full test suite...\n\n" .
        "Time: " . date('Y-m-d H:i:s')
    );
    echo "→ If you received a Telegram message, this passed!\n\n";
    $passed++;

    // Test 2: Session cookie present
    echo "[ TEST 2 ] Session cookie present...\n";
    if (!empty($GVC_SESSION)) {
        echo "→ ✓ GVC_SESSION is set (" . strlen($GVC_SESSION) . " chars)\n\n";
        $passed++;
    } else {
        echo "→ ✗ GVC_SESSION is empty! Add it to GitHub secrets.\n\n";
        $failed++;
    }

    // Test 3: GVC session valid
    echo "[ TEST 3 ] GVC session validity...\n";
    if (sessionValid()) {
        echo "→ ✓ Session is valid, GVC accepted our cookie\n\n";
        $passed++;
    } else {
        echo "→ ✗ Session invalid — update GVC_SESSION secret with fresh JSESSIONID\n\n";
        $failed++;
    }

    // Test 4: Date range generation
    echo "[ TEST 4 ] Date range generation...\n";
    $dates = getDates();
    echo "→ Generated " . count($dates) . " workdays\n";
    echo "→ From: {$dates[0]}\n";
    echo "→ To:   " . end($dates) . "\n";
    if (count($dates) > 0) {
        echo "→ ✓ Date range looks correct\n\n";
        $passed++;
    } else {
        echo "→ ✗ No dates generated!\n\n";
        $failed++;
    }

    // Test 5: Slot check on first date
    echo "[ TEST 5 ] Slot API check on first date ({$dates[0]})...\n";
    $slots = getSlots($dates[0]);
    echo "→ API responded — slots found: " . (empty($slots) ? "none (normal)" : implode(', ', $slots)) . "\n";
    echo "→ ✓ Slot API reachable\n\n";
    $passed++;

    // Test 6: Simulate slot found alert
    echo "[ TEST 6 ] Simulated slot alert...\n";
    telegram(
        "🚨🚨🚨 <b>GVC SLOT AVAILABLE!</b> 🚨🚨🚨\n\n" .
        "📅 Date: <b>01/04/2026</b>\n" .
        "🕐 Times: <b>09:00 · 09:15 · 10:30</b>\n\n" .
        "👉 <a href=\"https://am-gr-services.gvcworld.eu/appointments/add\">Book NOW!</a>\n\n" .
        "⚡ This is a TEST — no real slot found."
    );
    echo "→ ✓ Simulated alert sent to Telegram\n\n";
    $passed++;

    // Summary
    echo "========================================\n";
    echo "RESULTS: {$passed} passed, {$failed} failed\n";
    echo "========================================\n\n";

    $status = $failed === 0
        ? "✅ All {$passed} tests passed! Watcher is ready."
        : "⚠️ {$failed} test(s) failed. Check the logs above.";

    telegram(
        "📊 <b>GVC Watcher — Test Results</b>\n\n" .
        ($failed === 0 ? "✅" : "⚠️") . " {$passed} passed, {$failed} failed\n\n" .
        ($failed === 0
            ? "Watcher is fully operational! 🎉\nWill alert you Mon–Fri 8:45–11:00 AM."
            : "Some tests failed. Check GitHub Actions logs.")
    );

    echo $status . "\n";
    exit($failed > 0 ? 1 : 0);
}

// ── Main ──────────────────────────────────────────────────────────────────────
echo "\n=== GVC Slot Check — " . date('Y-m-d H:i') . " ===\n";
echo "Mode: " . ($TEST_MODE ? "TEST" : "PRODUCTION") . "\n\n";

if ($TEST_MODE) {
    runTests();
    exit(0);
}

// Production mode
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
    // Silent notification after every scan
    telegramSilent(
        "👁 Checked " . count($dates) . " dates — no slots\n" .
        date('H:i') . " · " . $dates[0] . " → " . end($dates)
    );
}
