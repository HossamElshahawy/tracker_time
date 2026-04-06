<?php
date_default_timezone_set('Africa/Cairo');

$CONFIG = [
    'EMAIL'       => 'hossamelshahawy13@gmail.com',
    'PASSWORD'    => '01013677499',
    'WA_APPKEY'   => '65dffdb4-3fd6-4858-9efe-b03060997923',
    'WA_AUTHKEY'  => 'VRi37ABpmoNC26Zmu0VPclonet9LP1SGfYBNQegZKoM1TiwHcD',
    'WA_NUMBER'   => '201013677499',
    'TARGET_MIN'  => 510, // 8.5 ساعات
];

$COOKIE_FILE = __DIR__ . '/cookie.txt';
$STATE_FILE  = __DIR__ . '/state.json';

// --- الدوال المساعدة ---
function todayDate() { return date('Y-m-d'); }

function convertToMinutes($timeStr) {
    if (!strpos($timeStr, ':')) return 0;
    $parts = explode(':', $timeStr);
    return ((int)$parts[0] * 60) + (int)$parts[1];
}

function loadState($file) {
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function saveState($file, $state) {
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function curlRequest($url, $postData = null, $cookieFile = null) {
    $ch = curl_init($url);
    $headers = [
        'X-Requested-With: XMLHttpRequest',
        'Accept: application/json, text/javascript, */*; q=0.01',
        'User-Agent: Mozilla/5.0'
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($postData) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function login($config, $cookieFile) {
    curlRequest('https://erp.designal.cc/login?action=initial', [
        'email' => $config['EMAIL'], 
        'password' => $config['PASSWORD'], 
        'remember_me' => 'on'
    ], $cookieFile);
}
function startTimer($cookieFile) {
    curlRequest('https://erp.designal.cc/tasks/timer/3376/start?source=card', [], $cookieFile);
}

function stopTimer($cookieFile) {
    curlRequest('https://erp.designal.cc/tasks/timer/3376/stop?source=card', [], $cookieFile);
}


// جلب الساعات المسجلة (بعد عمل Stop)
function getRecordedMinutes($cookieFile) {
    $url = "https://erp.designal.cc/timesheets/search?" . http_build_query([
        'ref' => 'list', 'filter_grouping' => 'user',
        'filter_date_created_start' => todayDate(),
        'filter_date_created_end' => todayDate(),
        'action' => 'search'
    ]);
    $res = curlRequest($url, null, $cookieFile);
    $json = json_decode($res, true);
    if (!isset($json['dom_html'])) return 0;
    foreach ($json['dom_html'] as $item) {
        if ($item['selector'] === '#list-pages-stats-widget') {
            if (preg_match('/id="stats-widget-value-1">\s*(\d{1,2}).*?(\d{2})\s*<\/h3>/s', $item['value'], $matches)) {
                return convertToMinutes($matches[1] . ':' . $matches[2]);
            }
        }
    }
    return 0;
}

// جلب وقت التايمر الشغال حالياً (Polling)
function getActiveTimerMinutes($cookieFile) {
    $res = curlRequest('https://erp.designal.cc/polling/timer', null, $cookieFile);
    $json = json_decode($res, true);
    if (isset($json['dom_html'])) {
        foreach ($json['dom_html'] as $item) {
            if ($item['selector'] === '#my-timer-time-topnav') {
                $cleanTime = strip_tags($item['value']); // تحويل '03:35' من HTML
                return convertToMinutes(trim($cleanTime));
            }
        }
    }
    return 0;
}

function sendWhatsApp($msg, $config) {
    curlRequest('https://waapi.octopusteam.net/api/create-message', [
        'appkey' => $config['WA_APPKEY'],
        'authkey' => $config['WA_AUTHKEY'],
        'to' => $config['WA_NUMBER'],
        'message' => $msg,
    ]);
}

// --- التنفيذ ---
try {
    login($CONFIG, $COOKIE_FILE);
    $day = (int)date('w'); // 0=الأحد ... 6=السبت

// اشتغل بس من الأحد (0) للخميس (4)
if ($day < 0 || $day > 4) {
    echo "خارج أيام العمل\n";
    exit;
}else {
    echo "داخل أيام العمل\n";
}
    $currentTime = date('H:i');
    echo "الوقت الحالي: $currentTime\n";

$currentHour = (int)date('H');

$recordedMin = getRecordedMinutes($COOKIE_FILE);
$activeMin   = getActiveTimerMinutes($COOKIE_FILE);
$totalMin    = $recordedMin + $activeMin;

// =======================
// 🟢 تشغيل التايمر
// =======================
if ($currentHour >= 8 && $currentHour < 16) {
    if ($activeMin == 0) {
        startTimer($COOKIE_FILE);
        echo "تم تشغيل التايمر تلقائيًا\n";

        sendWhatsApp("🟢 تم تشغيل التايمر تلقائيًا الساعه $currentHour لأننا داخل وقت العمل", $CONFIG);
    }
}

// =======================
// 🔴 إيقاف التايمر
// =======================
if ($currentHour >= 16) {
    if ($activeMin > 0 && $totalMin >= $CONFIG['TARGET_MIN']) {
        stopTimer($COOKIE_FILE);
        echo "تم إيقاف التايمر بعد تحقيق الهدف\n";

        sendWhatsApp("🔴 تم إيقاف التايمر الساعه $currentHour بعد إنهاء التارجت ومجموع الساعات: $totalMin 🎯", $CONFIG);
    }
}
    $state = loadState($STATE_FILE);

    // حساب الإجمالي
    $recordedMin = getRecordedMinutes($COOKIE_FILE);
    $activeMin   = getActiveTimerMinutes($COOKIE_FILE);
    $totalMin    = $recordedMin + $activeMin;

    // جلب آخر إجمالي دقائق تم تسجيله اليوم
    $lastTotalMin = (($state['date'] ?? '') === todayDate()) ? ($state['lastTotalMin'] ?? -1) : -1;

    // التحقق: إذا كان الوقت لم يتغير (التايمر متوقف)
    if ($totalMin === $lastTotalMin) {
        echo "التايمر متوقف والوقت ($totalMin دقائق) لم يتغير منذ آخر فحص. لن يتم إرسال رسالة.\n";
        exit;
    }

    // تحويل للغة البشر
    $h = intdiv($totalMin, 60);
    $m = $totalMin % 60;
    $timeStr = sprintf('%02d:%02d', $h, $m);

    echo "Total: $timeStr (Recorded: $recordedMin, Active: $activeMin)\n";

    // 1. رسالة التحديث
    $statusMsg = "🕒 تحديث الوقت الحقيقي:\n";
    $statusMsg .= "📅 اليوم: " . todayDate() . "\n";
    $statusMsg .= "⏱️ إجمالي الشغل: $timeStr\n";
    if ($activeMin > 0) $statusMsg .= "⚡ (التايمر نشط الآن)";
    
    sendWhatsApp($statusMsg, $CONFIG);

    // تحديث الحالة الأساسية في المصفوفة
    $state['date'] = todayDate();
    $state['lastTotalMin'] = $totalMin;

    // 2. فحص التنبيهات الخاصة (مرة واحدة)
    if (($state['finalSent'] ?? '') !== todayDate()) {
        // تنبيه الوصول للهدف 8.5 ساعة
        if ($totalMin >= $CONFIG['TARGET_MIN']) {
            sendWhatsApp("✅ عاش! خلصت التارجت النهارده: $timeStr 🎯", $CONFIG);
            $state['finalSent'] = todayDate();
        } else {
            // تنبيه كل ساعة كاملة
            $currentHour = intdiv($totalMin, 60);
            $lastHourSent = ($state['lastHourSent'] ?? 0);

            if ($currentHour >= 1 && $currentHour > $lastHourSent) {
                sendWhatsApp("⏱️ برافو! أكملت {$currentHour} ساعة شغل.. الإجمالي الآن: $timeStr", $CONFIG);
                $state['lastHourSent'] = $currentHour;
            }
        }
    }

    // حفظ التغييرات في ملف JSON
    saveState($STATE_FILE, $state);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
