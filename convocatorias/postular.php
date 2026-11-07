<?php
/**
 * COMBOY VID - Recepción de postulaciones (Trabaja con nosotros)
 * ----------------------------------------------------------------
 * 1) Valida los datos y el CV (PDF / DOC / DOCX, máx. 5 MB).
 * 2) Guarda el CV en una carpeta privada (fuera de public_html si se puede).
 * 3) Registra la postulación en postulaciones.csv (se abre con Excel).
 * 4) Envía el CV por correo a RR.HH.
 * Responde siempre JSON: {ok:true} o {ok:false, error:"..."}.
 * Seguridad (07/11/2026): funciones de administración protegidas con admin-clave.txt, sin exportación.
 */

// ================== CONFIGURACIÓN ==================
$CORREO_DESTINO = 'comboyvidaqp@gmail.com';        // RR.HH. (correo de la convocatoria)
$CORREO_REMITE  = 'no-reply@comboyvid.com';         // debe ser del dominio para no caer en spam
$MAX_BYTES      = 5 * 1024 * 1024;                  // 5 MB
$MAX_POR_HORA   = 6;                                // límite de envíos por IP
// Cierre de cada convocatoria (hora Perú). Después de esta hora ya no se aceptan postulaciones a ese puesto.
$CIERRES = [
    'Asistente Administrativo(a)' => '2026-11-04 12:00',
    'Mecánico de motos cargueras' => '2026-11-09 23:59',
    'Administrador(a) de tienda – Secocha' => '2026-11-09 23:59',
];
// ===================================================

// Nunca mostrar avisos de PHP: romperían la respuesta JSON
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function responder($ok, $msg = '', $code = 200, $extra = []) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    echo json_encode(array_merge($ok ? ['ok' => true, 'msg' => $msg] : ['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
// Cualquier error fatal también responde JSON
set_exception_handler(function ($e) { error_log('COMBOY postular.php: ' . $e->getMessage()); responder(false, 'Error interno del servidor (E1). Envíe su CV por WhatsApp.', 500); });
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('COMBOY postular.php fatal: ' . $e['message'] . ' L' . $e['line']);
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Error interno del servidor (E2). Envíe su CV por WhatsApp.'], JSON_UNESCAPED_UNICODE);
    }
});
function a_bytes($v) { $v = trim((string)$v); $n = (float)$v; switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; } return (int)$n; }
function puede_usar($ruta) {
    $ob = ini_get('open_basedir');
    if (!$ob) return true;
    foreach (explode(PATH_SEPARATOR, $ob) as $b) { $b = rtrim($b, '/'); if ($b !== '' && strpos($ruta . '/', $b . '/') === 0) return true; }
    return false;
}

// ---------- Funciones de administración (protegidas) ----------
// ?diag, ?probar_drive, ?configurar_drive y ?sincronizar SOLO funcionan si existe el archivo
// admin-clave.txt en la carpeta privada (/home/USUARIO/postulaciones-comboyvid/) y se agrega &clave=ESA_CLAVE.
// Sin ese archivo están desactivadas para todo el mundo. La clave NUNCA va en este repositorio (es público).
function admin_ok() {
    $b = carpeta_base();
    $k = $b ? trim((string)@file_get_contents("$b/admin-clave.txt")) : '';
    return strlen($k) >= 16 && hash_equals($k, (string)($_GET['clave'] ?? ''));
}
if (array_intersect(['diag', 'probar_drive', 'configurar_drive', 'sincronizar', 'exportar'], array_keys($_GET)) && !admin_ok()) {
    responder(false, 'No disponible.', 404);
}
// Diagnóstico sin datos sensibles: postular.php?diag=1
// Configurar Google Drive una sola vez: postular.php?configurar_drive=https://script.google.com/macros/s/XXXX/exec
if (isset($_GET['configurar_drive'])) {
    $u = trim((string)$_GET['configurar_drive']);
    if (!preg_match('#^https://script\.google\.com/macros/s/[A-Za-z0-9_-]{20,}/exec$#', $u)) responder(false, 'URL no válida. Debe ser https://script.google.com/macros/s/.../exec', 422);
    $base = carpeta_base();
    if (!$base) responder(false, 'Sin carpeta.', 500);
    if (drive_url($base) !== '') responder(false, 'Google Drive ya está configurado. Para cambiarlo, borre drive-config.json en la carpeta privada del cPanel.', 409);
    file_put_contents("$base/drive-config.json", json_encode(['url' => $u, 'fecha' => date('c')]));
    responder(true, 'Google Drive configurado. Ahora abra postular.php?sincronizar=1 para subir los CV anteriores.');
}
// Probar la conexión con el script de Drive (sin subir nada): postular.php?probar_drive=1
if (isset($_GET['probar_drive'])) {
    $base = carpeta_base();
    $url = $base ? drive_url($base) : '';
    if ($url === '') responder(false, 'Google Drive aún no está configurado.', 409);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8]);
    $r = curl_exec($ch);
    $info = ['http' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'curl_error' => curl_error($ch), 'segundos' => round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 1),
             'respuesta' => substr(trim(strip_tags((string)$r)), 0, 160)];
    curl_close($ch);
    $j = @json_decode((string)$r, true);
    responder(is_array($j) && !empty($j['ok']), is_array($j) && !empty($j['ok']) ? 'Conexión con Drive OK' : 'El script de Drive no respondió bien', 200, $info);
}
// Subir a Drive los CV que aún no se subieron (de 10 en 10): postular.php?sincronizar=1
if (isset($_GET['sincronizar'])) {
    $base = carpeta_base();
    if (!$base || drive_url($base) === '') responder(false, 'Google Drive aún no está configurado.', 409);
    @set_time_limit(300);
    $ya = drive_enviados($base); $subidos = 0; $fallas = 0; $pend = 0;
    foreach (leer_csv("$base/postulaciones.csv") as $r) {
        $a = basename($r['Archivo CV'] ?? '');
        if ($a === '' || isset($ya[$a]) || !is_file("$base/$a")) continue;
        if ($subidos + $fallas >= 1) { $pend++; continue; }   // 1 por llamada para no pasar el límite de tiempo
        $ext = strtolower(pathinfo($a, PATHINFO_EXTENSION));
        if (enviar_a_drive($base, $a, nombre_cv($r['Nombres'] ?? '', $r['Puesto'] ?? '', $ext), $r['Puesto'] ?? '', descripcion_cv($r))) $subidos++; else $fallas++;
    }
    responder(true, 'Sincronización', 200, ['subidos' => $subidos, 'fallas' => $fallas, 'pendientes' => $pend] + ($fallas ? ['detalle' => $GLOBALS['DRIVE_ULTIMO'] ?? null] : []));
}

if (isset($_GET['diag'])) {
    responder(true, 'diag', 200, ['php' => PHP_VERSION, 'sapi' => PHP_SAPI,
        'upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size'),
        'file_uploads' => ini_get('file_uploads'), 'open_basedir' => ini_get('open_basedir') ? 'si' : 'no',
        'mail' => function_exists('mail'), 'finfo' => function_exists('finfo_open'), 'iconv' => function_exists('iconv'), 'mbstring' => function_exists('mb_substr'),
        'carpeta_privada' => puede_usar(dirname(__DIR__, 2)) && is_writable(dirname(__DIR__, 2)), 'carpeta_respaldo' => is_writable(__DIR__ . '/postulaciones'), 'curl' => function_exists('curl_init'),
        'drive' => ($b = carpeta_base()) && drive_url($b) !== '']);
}
function campo($n, $max = 200) {
    $v = isset($_POST[$n]) ? trim((string)$_POST[$n]) : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}
function una_linea($v) { return trim(preg_replace('/[\r\n]+/', ' ', $v)); }

function carpeta_base() {
    $base = dirname(__DIR__, 2) . '/postulaciones-comboyvid';      // /home/USUARIO/postulaciones-comboyvid (fuera de public_html)
    if (!puede_usar($base) || (!@is_dir($base) && !@mkdir($base, 0750, true))) {
        $base = __DIR__ . '/postulaciones';                         // respaldo: carpeta protegida con .htaccess
        if (!@is_dir($base)) @mkdir($base, 0755, true);
    }
    if (!@is_dir($base) || !@is_writable($base)) return null;
    if (!file_exists($base . '/.htaccess')) @file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n");
    return $base;
}
function leer_csv($ruta) {
    $filas = [];
    if (!is_file($ruta) || !($fh = @fopen($ruta, 'r'))) return $filas;
    $cab = fgetcsv($fh, 0, ';');
    if ($cab) $cab[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cab[0]);
    while (($r = fgetcsv($fh, 0, ';')) !== false) if ($cab && count($r) === count($cab)) $filas[] = array_combine($cab, $r);
    fclose($fh);
    return $filas;
}
function agregar_csv($ruta, $cabecera, $fila) {
    $nuevo = !file_exists($ruta);
    if (!($fh = @fopen($ruta, 'a'))) return false;
    flock($fh, LOCK_EX);
    if ($nuevo) { fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh, $cabecera, ';'); }
    // Evita que Excel ejecute fórmulas escritas por el postulante
    fputcsv($fh, array_map(function ($v) { return preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : $v; }, $fila), ';');
    flock($fh, LOCK_UN); fclose($fh);
    return true;
}
// ---------- Nombre del archivo: "NOMBRE - PUESTO.ext" ----------
function nombre_cv($nombres, $puesto, $ext) {
    $limpia = function ($t, $max) {
        $t = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', (string)$t);
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        $t = function_exists('mb_strtoupper') ? mb_strtoupper($t, 'UTF-8') : strtoupper($t);
        return function_exists('mb_substr') ? mb_substr($t, 0, $max, 'UTF-8') : substr($t, 0, $max);
    };
    $p = stripos((string)$puesto, 'banco') === 0 ? 'BANCO DE EMPLEOS' : $limpia($puesto, 60);
    return ($limpia($nombres, 70) ?: 'POSTULANTE') . ' - ' . $p . '.' . strtolower($ext);
}
function tipo_mime($ext) {
    return $ext === 'pdf' ? 'application/pdf' : ($ext === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/msword');
}

// ---------- Google Drive (Apps Script de la cuenta de COMBOY VID) ----------
// La URL del script se guarda FUERA del repositorio, en la carpeta privada: drive-config.json
function drive_url($base) {
    $j = @json_decode((string)@file_get_contents("$base/drive-config.json"), true);
    return is_array($j) && !empty($j['url']) ? $j['url'] : '';
}
function drive_enviados($base) { return array_flip(array_filter(array_map('trim', @file("$base/drive-enviados.txt") ?: []))); }
function enviar_a_drive($base, $archivoLocal, $nombreDrive, $puesto, $descripcion) {
    $url = drive_url($base);
    if ($url === '' || !function_exists('curl_init') || !is_file("$base/$archivoLocal")) return false;
    $ext = strtolower(pathinfo($nombreDrive, PATHINFO_EXTENSION));
    $cuerpo = json_encode(['archivo' => $nombreDrive, 'puesto' => $puesto, 'mime' => tipo_mime($ext), 'descripcion' => $descripcion,
                           'data' => base64_encode(file_get_contents("$base/$archivoLocal"))], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8]);
    $r = curl_exec($ch);
    $GLOBALS['DRIVE_ULTIMO'] = ['http' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'curl_error' => curl_error($ch), 'segundos' => round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 1),
                                'respuesta' => substr(trim(strip_tags((string)$r)), 0, 160)];
    curl_close($ch);
    $j = @json_decode((string)$r, true);
    if (is_array($j) && !empty($j['ok'])) { @file_put_contents("$base/drive-enviados.txt", $archivoLocal . "\n", FILE_APPEND | LOCK_EX); return true; }
    error_log('COMBOY Drive: fallo al subir ' . $archivoLocal . ' -> ' . json_encode($GLOBALS['DRIVE_ULTIMO']));
    return false;
}
function descripcion_cv($r) {
    return "Postulación web comboyvid.com\nPuesto: " . ($r['Puesto'] ?? '') . "\nNombres: " . ($r['Nombres'] ?? '') . "\nDNI: " . ($r['DNI'] ?? '') .
           "\nCelular: " . ($r['Celular'] ?? '') . "\nCorreo: " . ($r['Correo'] ?? '') . "\nFecha: " . ($r['Fecha'] ?? '');
}
$BANCO_CAB = ['Fecha registro', 'DNI', 'Nombres', 'Celular', 'Puesto postulado', 'Archivo CV', 'Origen'];

// (La exportación ?exportar=1 se eliminó en la auditoría de seguridad del 07/11/2026.)

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(false, 'Método no permitido.', 405);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8 * 1024 * 1024) responder(false, 'El envío supera 8 MB. Comprima el CV o envíelo por WhatsApp.', 413);

// El formulario es grande: si PHP descartó el POST por tamaño, $_POST llega vacío
if (empty($_POST) && empty($_FILES)) responder(false, 'El archivo es demasiado grande para el servidor (máx. ' . ini_get('post_max_size') . '). Comprímalo o envíelo por WhatsApp.', 413);

// Anti-spam: campo trampa que una persona nunca llena
if (campo('empresa_web') !== '') responder(true, 'Recibido.');

// ---------- Carpeta privada ----------
$base = carpeta_base();
if (!$base) responder(false, 'No se pudo guardar la postulación. Envíe su CV por WhatsApp.', 500);

// ---------- Límite por IP ----------
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rl = $base . '/.limite-' . md5($ip);
$hits = array_filter(array_map('intval', @file($rl, FILE_IGNORE_NEW_LINES) ?: []), function ($t) { return $t > time() - 3600; });
if (count($hits) >= $MAX_POR_HORA) responder(false, 'Demasiados envíos desde su conexión. Intente en una hora o use WhatsApp.', 429);

$ahora = new DateTime('now', new DateTimeZone('America/Lima'));

// ---------- Registro en el BANCO DE EMPLEOS (postulantes que ya enviaron su CV) ----------
if (campo('accion') === 'banco') {
    $dni = preg_replace('/\D/', '', campo('dni', 20));
    $cel = preg_replace('/\D/', '', campo('celular', 20));
    if (!preg_match('/^\d{8,12}$/', $dni) || strlen($cel) < 9) responder(false, 'Ingresa tu DNI / CE y el celular con el que postulaste.', 422);
    if (campo('acepta') !== 'si') responder(false, 'Marca la autorización para conservar tu CV en el banco de empleos.', 422);
    $hits[] = time(); @file_put_contents($rl, implode("\n", $hits));
    $mias = array_values(array_filter(leer_csv("$base/postulaciones.csv"), function ($r) use ($dni) { return preg_replace('/\D/', '', $r['DNI'] ?? '') === $dni; }));
    $ult = $mias ? end($mias) : null;
    // Mismo mensaje si el DNI no existe o si el celular no coincide: así nadie puede averiguar quién postuló
    if (!$ult || substr(preg_replace('/\D/', '', $ult['Celular'] ?? ''), -9) !== substr($cel, -9))
        responder(false, 'No encontramos una postulación con ese DNI y celular. Si aún no enviaste tu CV, usa el formulario de abajo y elige "Banco de empleos", o escríbenos por WhatsApp.', 404);
    foreach (leer_csv("$base/banco.csv") as $r) if (preg_replace('/\D/', '', $r['DNI'] ?? '') === $dni) responder(true, 'Ya estabas registrado(a) en el banco de empleos.', 200, ['nombre' => $ult['Nombres']]);
    agregar_csv("$base/banco.csv", $BANCO_CAB, [$ahora->format('Y-m-d H:i'), $dni, $ult['Nombres'], $ult['Celular'], $ult['Puesto'], $ult['Archivo CV'], 'Registro web']);
    @mail($CORREO_DESTINO, '=?UTF-8?B?' . base64_encode('Banco de empleos: ' . $ult['Nombres']) . '?=',
        "Se registró en el banco de empleos desde comboyvid.com/convocatorias\n\nNombres: {$ult['Nombres']}\nDNI: $dni\nCelular: {$ult['Celular']}\nPostuló a: {$ult['Puesto']}\nCV: {$ult['Archivo CV']}\nFecha: " . $ahora->format('d/m/Y H:i') . "\n",
        "From: COMBOY VID Convocatorias <$CORREO_REMITE>\r\nContent-Type: text/plain; charset=UTF-8\r\n", '-f' . $CORREO_REMITE);
    responder(true, 'Registro en el banco de empleos confirmado.', 200, ['nombre' => $ult['Nombres']]);
}

// ---------- Datos ----------
$d = [
    'puesto'      => una_linea(campo('puesto', 120)),
    'nombres'     => una_linea(campo('nombres', 120)),
    'dni'         => preg_replace('/\D/', '', campo('dni', 20)),
    'celular'     => preg_replace('/[^\d+ ]/', '', campo('celular', 20)),
    'correo'      => una_linea(campo('correo', 120)),
    'ciudad'      => una_linea(campo('ciudad', 80)),
    'formacion'   => una_linea(campo('formacion', 120)),
    'experiencia' => una_linea(campo('experiencia', 40)),
    'disponibilidad' => una_linea(campo('disponibilidad', 60)),
    'mensaje'     => campo('mensaje', 1500),
];
$err = [];
if ($d['puesto'] === '')                                   $err[] = 'puesto';
if (strlen($d['nombres']) < 5)                          $err[] = 'nombres y apellidos';
if (!preg_match('/^\d{8,12}$/', $d['dni']))                $err[] = 'DNI / CE válido';
if (strlen(preg_replace('/\D/', '', $d['celular'])) < 9)   $err[] = 'celular válido';
if ($d['correo'] !== '' && !filter_var($d['correo'], FILTER_VALIDATE_EMAIL)) $err[] = 'correo válido';
if (campo('acepta') !== 'si')                              $err[] = 'autorización de datos personales';
if ($err) responder(false, 'Complete: ' . implode(', ', $err) . '.', 422);
if (isset($CIERRES[$d['puesto']]) && $ahora > new DateTime($CIERRES[$d['puesto']], new DateTimeZone('America/Lima')))
    responder(false, 'La convocatoria para ' . $d['puesto'] . ' cerró el ' . (new DateTime($CIERRES[$d['puesto']]))->format('d/m/Y \a \l\a\s g:i a') . '. Puedes dejar tu CV en el banco de empleos.', 410);

// ---------- CV ----------
if (!isset($_FILES['cv']) || $_FILES['cv']['error'] === UPLOAD_ERR_NO_FILE) responder(false, 'Adjunte su CV (PDF o Word).', 422);
$f = $_FILES['cv'];
if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) responder(false, 'El CV supera el límite del servidor (' . ini_get('upload_max_filesize') . '). Comprímalo o envíelo por WhatsApp.', 413);
if ($f['size'] > $MAX_BYTES) responder(false, 'El CV pesa más de 5 MB. Comprímalo o envíelo por WhatsApp.', 413);
if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) responder(false, 'No se pudo subir el archivo. Intente de nuevo.', 400);
if ($f['size'] < 1024) responder(false, 'El archivo del CV parece vacío.', 422);

$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
$permitidos = [
    'pdf'  => ['application/pdf', 'application/x-pdf'],
    'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream', 'application/CDFV2'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
];
if (!isset($permitidos[$ext])) responder(false, 'Formato no permitido. Solo PDF, DOC o DOCX.', 415);
$mime = 'application/octet-stream';
if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($fi, $f['tmp_name']) ?: $mime; finfo_close($fi); }
$cabeza = (string)@file_get_contents($f['tmp_name'], false, null, 0, 8);
$firmaOk = ($ext === 'pdf'  && strncmp($cabeza, '%PDF', 4) === 0)
        || ($ext === 'docx' && strncmp($cabeza, "PK\x03\x04", 4) === 0)
        || ($ext === 'doc'  && $cabeza === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
if (!$firmaOk || !in_array($mime, $permitidos[$ext], true)) responder(false, 'El archivo no es un PDF/Word válido.', 415);

// ---------- Guardar ----------
$slug = function ($s) {
    $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : false;
    $s = $t !== false ? $t : $s;
    return trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-') ?: 'postulante';
};
$fecha = (new DateTime('now', new DateTimeZone('America/Lima')));
// Se guarda como "NOMBRE - PUESTO.ext"; si ya existe (otra postulación), se agrega (2), (3)...
$nombreBonito = nombre_cv($d['nombres'], $d['puesto'], $ext);
$nombreArchivo = $nombreBonito;
for ($n = 2; file_exists("$base/$nombreArchivo"); $n++) $nombreArchivo = substr($nombreBonito, 0, -strlen($ext) - 1) . " ($n).$ext";
$destino = $base . '/' . $nombreArchivo;
if (!move_uploaded_file($f['tmp_name'], $destino)) responder(false, 'No se pudo guardar el CV. Envíelo por WhatsApp.', 500);
@chmod($destino, 0640);

$csv = $base . '/postulaciones.csv';
$nuevo = !file_exists($csv);
if ($fh = @fopen($csv, 'a')) {
    flock($fh, LOCK_EX);
    if ($nuevo) { fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh, ['Fecha', 'Puesto', 'Nombres', 'DNI', 'Celular', 'Correo', 'Ciudad', 'Formación', 'Experiencia', 'Disponibilidad', 'Mensaje', 'Archivo CV', 'IP'], ';'); }
    $fila = [$fecha->format('Y-m-d H:i'), $d['puesto'], $d['nombres'], $d['dni'], $d['celular'], $d['correo'], $d['ciudad'], $d['formacion'], $d['experiencia'], $d['disponibilidad'], una_linea($d['mensaje']), $nombreArchivo, $ip];
    // Evita que Excel ejecute fórmulas escritas por el postulante
    $fila = array_map(function ($v) { return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; }, $fila);
    fputcsv($fh, $fila, ';');
    flock($fh, LOCK_UN); fclose($fh);
}
$hits[] = time();
@file_put_contents($rl, implode("\n", $hits));
// Quien postula al banco de empleos queda registrado ahí directamente
if (stripos($d['puesto'], 'banco') === 0) agregar_csv("$base/banco.csv", $BANCO_CAB, [$fecha->format('Y-m-d H:i'), $d['dni'], $d['nombres'], $d['celular'], $d['puesto'], $nombreArchivo, 'Formulario web']);

// ---------- Correo con el CV adjunto ----------
$asunto = 'Postulación: ' . $d['puesto'] . ' - ' . $d['nombres'];
$cuerpo  = "Nueva postulación recibida desde comboyvid.com/convocatorias\n\n";
$cuerpo .= "Puesto: {$d['puesto']}\nNombres y apellidos: {$d['nombres']}\nDNI/CE: {$d['dni']}\nCelular / WhatsApp: {$d['celular']}\n";
$cuerpo .= "Correo: " . ($d['correo'] ?: '-') . "\nCiudad: " . ($d['ciudad'] ?: '-') . "\nFormación: " . ($d['formacion'] ?: '-') . "\n";
$cuerpo .= "Experiencia: " . ($d['experiencia'] ?: '-') . "\nDisponibilidad: " . ($d['disponibilidad'] ?: '-') . "\n\nMensaje:\n" . ($d['mensaje'] ?: '-') . "\n\n";
$cuerpo .= "WhatsApp directo: https://wa.me/" . (strlen(preg_replace('/\D/', '', $d['celular'])) === 9 ? '51' : '') . preg_replace('/\D/', '', $d['celular']) . "\n";
$cuerpo .= "Fecha: " . $fecha->format('d/m/Y H:i') . " (hora Perú)\nCopia del CV guardada como: $nombreArchivo\n";

$limite = 'cv_' . bin2hex(random_bytes(8));
$cabeceras  = "From: COMBOY VID Convocatorias <$CORREO_REMITE>\r\n";
if ($d['correo'] !== '') $cabeceras .= "Reply-To: " . str_replace(["\r", "\n", '<', '>', '"'], '', $d['nombres']) . " <{$d['correo']}>\r\n";
$cabeceras .= "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$limite\"\r\n";
$tipoAdj = $ext === 'pdf' ? 'application/pdf' : ($ext === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/msword');
$adjNombre = $nombreBonito;
$msg  = "--$limite\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($cuerpo)) . "\r\n";
$msg .= "--$limite\r\nContent-Type: $tipoAdj; name=\"=?UTF-8?B?" . base64_encode($adjNombre) . "?=\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename*=UTF-8''" . rawurlencode($adjNombre) . "\r\n\r\n" . chunk_split(base64_encode(file_get_contents($destino))) . "\r\n--$limite--";

$enviado = @mail($CORREO_DESTINO, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $msg, $cabeceras, '-f' . $CORREO_REMITE);
if (!$enviado) error_log('COMBOY postular.php: mail() falló para ' . $nombreArchivo);

// Copia a Google Drive (si está configurado). Si falla, se reintenta con ?sincronizar=1
enviar_a_drive($base, $nombreArchivo, $nombreBonito, $d['puesto'], descripcion_cv(['Puesto' => $d['puesto'], 'Nombres' => $d['nombres'], 'DNI' => $d['dni'], 'Celular' => $d['celular'], 'Correo' => $d['correo'], 'Fecha' => $fecha->format('Y-m-d H:i')]));

// El CV ya quedó guardado aunque falle el correo
responder(true, 'Postulación recibida.');
