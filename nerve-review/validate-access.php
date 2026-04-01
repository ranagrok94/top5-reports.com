<?php
// ============================================
// 🔥 SISTEMA DE VALIDAÇÃO DE ACESSO v18.0
// tipsforhealth.site/maro-review
// Data: 2026-01-23 BRT
// ATUALIZAÇÃO: Bloqueio Total BR + Apenas Google Ads
// ============================================

// Configurações de erro
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php-errors-' . date('Y-m-d') . '.log');

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// ============================================
// 📍 CONFIGURAÇÕES GLOBAIS
// ============================================

$ADMIN_PASSWORD = 'ranagrok94';

$ALLOWED_COUNTRIES = ['US', 'CA', 'GB', 'AU', 'NZ', 'IE', 'ZA', 'DE', 'BR'];
$BLOCKED_COUNTRIES = ['CN', 'RU', 'IN', 'PK', 'BD', 'NG', 'VN'];

$RATE_LIMIT = 20;
$RATE_WINDOW = 60;

// Páginas do sistema
$PAGES = [
    'marobrain' => 'index.html',
];

$current_page = $_GET['page'] ?? 'unknown';

// ============================================
// 🤖 FUNÇÕES AUXILIARES (HELPERS)
// ============================================

function isGoogleIP($ip) {
    $google_ranges = [
        '66.249.64.0/19', '64.233.160.0/19', '66.102.0.0/20', '72.14.192.0/18',
        '74.125.0.0/16', '209.85.128.0/17', '216.58.192.0/19', '216.239.32.0/19',
        '172.217.0.0/16', '35.184.0.0/13', '35.192.0.0/14', '35.196.0.0/15',
        '35.198.0.0/16', '35.199.0.0/16', '35.200.0.0/13', '34.64.0.0/10',
        '34.128.0.0/10', '35.208.0.0/12', '35.224.0.0/12', '35.240.0.0/13'
    ];
    
    foreach ($google_ranges as $range) {
        if (ip_in_range($ip, $range)) return true;
    }
    return false;
}

function ip_in_range($ip, $range) {
    if (strpos($range, '/') === false) $range .= '/32';
    list($subnet, $bits) = explode('/', $range);
    $ip_long     = ip2long($ip);
    $subnet_long = ip2long($subnet);
    $mask        = -1 << (32 - $bits);
    $subnet_long &= $mask;
    return ($ip_long & $mask) === $subnet_long;
}

function checkAdminAccess() {
    global $ADMIN_PASSWORD;
    return (isset($_GET['admin_key']) && $_GET['admin_key'] === $ADMIN_PASSWORD);
}

function isGoogleBot($userAgent) {
    $google_bots = [
        'Googlebot', 'Googlebot-Image', 'Googlebot-News', 'Googlebot-Video',
        'AdsBot-Google', 'AdsBot-Google-Mobile', 'Mediapartners-Google',
        'APIs-Google', 'GoogleOther', 'Google-InspectionTool', 'Storebot-Google',
        'Google-Read-Aloud', 'DuplexWeb-Google', 'Google-Extended',
        'PageSpeedInsights', 'Chrome-Lighthouse', 'Lighthouse', 'psbot'
    ];
    
    foreach ($google_bots as $bot) {
        if (stripos($userAgent, $bot) !== false) return true;
    }
    return false;
}

function checkRateLimit($ip) {
    global $RATE_LIMIT, $RATE_WINDOW;
    
    $file = __DIR__ . '/data/request-times.json';
    $dir  = dirname($file);
    
    if (!file_exists($dir)) mkdir($dir, 0755, true);
    
    $requests = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $now      = time();
    
    $requests[$ip] = array_filter($requests[$ip] ?? [], function($time) use ($now, $RATE_WINDOW) {
        return ($now - $time) < $RATE_WINDOW;
    });
    
    if (count($requests[$ip]) >= $RATE_LIMIT) return false;
    
    $requests[$ip][] = $now;
    file_put_contents($file, json_encode($requests));
    return true;
}

function isDesktopDevice($userAgent) {
    $mobile_indicators = ['Mobile', 'Android', 'iPhone', 'iPad', 'Windows Phone'];
    foreach ($mobile_indicators as $indicator) {
        if (stripos($userAgent, $indicator) !== false) return false;
    }
    return true;
}

function getGeolocation($ip) {
    $cache_file = __DIR__ . '/cache/geo-cache.json';
    $cache_dir  = dirname($cache_file);
    if (!file_exists($cache_dir)) mkdir($cache_dir, 0755, true);
    
    $cache = file_exists($cache_file) ? json_decode(file_get_contents($cache_file), true) : [];
    
    if (isset($cache[$ip]) && (time() - $cache[$ip]['timestamp']) < 86400) {
        return $cache[$ip]['data'];
    }
    
    $ctx = stream_context_create(['http' => ['timeout' => 2]]);
    $json = @file_get_contents("http://ip-api.com/json/{$ip}", false, $ctx);
    
    if ($json) {
        $data = json_decode($json, true);
        if ($data && ($data['status'] ?? '') === 'success') {
            $cache[$ip] = ['data' => $data, 'timestamp' => time()];
            file_put_contents($cache_file, json_encode($cache));
            return $data;
        }
    }
    
    return [];
}

function isVPNorProxy($ip) {
    if (isGoogleIP($ip)) return false;

    $proxy_headers = [
        'HTTP_VIA', 'HTTP_X_FORWARDED_FOR', 'HTTP_FORWARDED', 
        'HTTP_X_FORWARDED', 'HTTP_CLIENT_IP', 'HTTP_PROXY_CONNECTION'
    ];
    
    foreach ($proxy_headers as $header) {
        if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) return true;
    }
    return false;
}

function hasValidGoogleAdsParameters() {
    return isset($_GET['gclid']) && $_GET['gclid'] !== '';
}

function hasValidGoogleAdsReferrer() {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    return (stripos($referer, 'google') !== false || stripos($referer, 'doubleclick') !== false);
}

// ============================================
// 🎯 SISTEMA DE LOGS & CSV
// ============================================

function saveLog($type, $ip, $details) {
    $log_file = __DIR__ . '/logs/access-detailed-' . date('Y-m-d') . '.log';
    $dir      = dirname($log_file);
    if (!file_exists($dir)) mkdir($dir, 0755, true);
    
    $geo     = getGeolocation($ip);
    $country = $geo['countryCode'] ?? 'UNK';
    $city    = $geo['city']        ?? 'UNK';
    $ref     = $_SERVER['HTTP_REFERER'] ?? 'NONE';

    $log = sprintf(
        "[%s] %s | IP: %s | Geo: %s/%s | Ref: %s | Info: %s\n", 
        date('Y-m-d H:i:s'), 
        $type, 
        $ip, 
        $country, 
        $city, 
        $ref, 
        $details
    );
        
    file_put_contents($log_file, $log, FILE_APPEND);
}

function saveConversionCSV($ip, $userAgent, $referer) {
    $csv_file = __DIR__ . '/data/conversions-' . date('Y-m') . '.csv';
    $dir      = dirname($csv_file);
    if (!file_exists($dir)) mkdir($dir, 0755, true);
    
    $is_new = !file_exists($csv_file);
    $handle = fopen($csv_file, 'a');
    
    if ($is_new) {
        fputcsv($handle, ['Timestamp', 'IP', 'Country', 'City', 'GCLID', 'Page', 'Device']);
    }
    
    $geo     = getGeolocation($ip);
    $country = $geo['countryCode'] ?? 'UNK';
    $city    = $geo['city']        ?? 'UNK';
    $device  = isDesktopDevice($userAgent) ? 'Desktop' : 'Mobile';
    
    fputcsv($handle, [
        date('Y-m-d H:i:s'),
        $ip,
        $country,
        $city,
        $_GET['gclid'] ?? '',
        $GLOBALS['current_page'],
        $device
    ]);
    fclose($handle);
}

// ============================================
// 🛡️ SCRIPT ANTI-CÓPIA EXTREMO
// ============================================

function getAntiCopyScript() {
    return <<<'EOD'
<script>
(function() {
    'use strict';
    document.addEventListener('contextmenu', e => e.preventDefault());
    document.addEventListener('keydown', function(e) {
        if (e.keyCode === 123 || 
            (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74)) || 
            (e.ctrlKey && (e.keyCode === 85 || e.keyCode === 83 || e.keyCode === 65 || e.keyCode === 67 || e.keyCode === 80))) {
            e.preventDefault();
            return false;
        }
    });
    setInterval(function() {
        if (window.outerWidth - window.innerWidth > 200 || window.outerHeight - window.innerHeight > 200) {
            document.body.innerHTML = '<h1 style="text-align:center;margin-top:50px;">Developer tools detected. Access denied.</h1>';
        }
    }, 1000);
    document.addEventListener('selectstart', e => e.preventDefault());
    document.addEventListener('dragstart', e => e.preventDefault());
    document.addEventListener('copy', e => e.preventDefault());
    document.addEventListener('cut', e => e.preventDefault());
    var style = document.createElement('style');
    style.textContent = '* { user-select: none !important; -webkit-user-select: none !important; }';
    document.head.appendChild(style);
})();
</script>
EOD;
}

// ============================================
// 🚀 VALIDAÇÃO PRINCIPAL (GEO-BLOCKING PRIMEIRO)
// ============================================

function validateAccess() {
    global $ALLOWED_COUNTRIES, $BLOCKED_COUNTRIES;
    
    $ip        = $_SERVER['REMOTE_ADDR'];
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $referer   = $_SERVER['HTTP_REFERER'] ?? ''; 
    
    // 1. Admin (Prioridade Máxima)
    if (checkAdminAccess()) {
        saveLog('ADMIN_ACCESS', $ip, 'Acesso administrativo');
        return ['allowed' => true];
    }
    
    // 2. Whitelist Google (IP e Bots)
    if (isGoogleIP($ip)) {
        saveLog('GOOGLE_IP', $ip, 'IP Oficial Google (Crawler/Bot/Cloud)');
        return ['allowed' => true];
    }
    if (isGoogleBot($userAgent)) {
        saveLog('GOOGLE_BOT', $ip, 'User-Agent Google Bot');
        return ['allowed' => true];
    }
    
    // 3. Rate Limit (Segurança Básica)
    if (!checkRateLimit($ip)) {
        saveLog('BLOCKED_RATE', $ip, 'Muitas requisições (DoS protection)');
        return ['allowed' => false, 'redirect' => true];
    }
    
    // 4. GEO-BLOCKING (PRIORIDADE - ANTES DO GOOGLE ADS)
    $geo = getGeolocation($ip);
    if (!empty($geo) && isset($geo['countryCode'])) {
        $country = strtoupper((string) $geo['countryCode']);
        
        // Blacklist tem prioridade absoluta
        if (in_array($country, $BLOCKED_COUNTRIES, true)) {
            saveLog('BLOCKED_GEO_BLACK', $ip, "País Bloqueado (Blacklist): {$country}");
            return ['allowed' => false, 'redirect' => true];
        }
        
        // Whitelist: se não estiver, bloqueia
        if (!in_array($country, $ALLOWED_COUNTRIES, true)) {
            saveLog('BLOCKED_GEO_WHITE', $ip, "País não permitido (Whitelist): {$country}");
            return ['allowed' => false, 'redirect' => true];
        }
    }
    
    // 5. VALIDAÇÃO GOOGLE ADS (SÓ CHEGA AQUI SE PASSOU NO GEO-BLOCKING)
    if (hasValidGoogleAdsParameters() && hasValidGoogleAdsReferrer()) {
        saveLog('ALLOWED_ADS', $ip, 'Google Ads Confirmado - GCLID: ' . $_GET['gclid']);
        saveConversionCSV($ip, $userAgent, $referer);
        return ['allowed' => true];
    }
    
    // 6. VPN/Proxy (Anti-Cloaking)
    if (isVPNorProxy($ip)) {
        saveLog('BLOCKED_VPN', $ip, 'Proxy/VPN Detectado');
        return ['allowed' => false, 'redirect' => true];
    }
    
    // 7. Bloqueio Total: Sem GCLID ou Orgânico
    $reason = '';
    if (!hasValidGoogleAdsParameters()) {
        $reason = "Sem GCLID (Orgânico/Direto BLOQUEADO) | Ref: {$referer}";
    } else {
        $reason = "GCLID sem referer Google válido | Ref: {$referer}";
    }
    
    saveLog('BLOCKED_NO_ADS', $ip, $reason);
    return ['allowed' => false, 'redirect' => true];
}

// ============================================
// 🏁 EXECUÇÃO COM REDIRECIONAMENTO
// ============================================

$validation = validateAccess();

if (!$validation['allowed']) {
    // Redireciona para Google
    if (isset($validation['redirect']) && $validation['redirect']) {
        header('Location: https://google.com///', true, 302);
        exit;
    }
    
    // Fallback: Erro 403
    http_response_code(403);
    if (file_exists(__DIR__ . '/error-403.html')) {
        readfile(__DIR__ . '/error-403.html');
    } else {
        echo "Access Denied";
    }
    exit;
}

if (isset($PAGES[$current_page])) {
    $file = __DIR__ . '/' . $PAGES[$current_page];
    if (file_exists($file)) {
        
        // LÊ O CONTEÚDO HTML
        $html = file_get_contents($file);
        
        // INJETA O SCRIPT ANTI-CÓPIA ANTES DO </body>
        $html = str_ireplace('</body>', getAntiCopyScript() . '</body>', $html);
        
        // ENVIA O HTML PROTEGIDO
        echo $html;
        exit;
    }
}

http_response_code(404);
echo "Page not found.";
?>
