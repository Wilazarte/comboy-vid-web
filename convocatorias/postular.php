<?php
/**
 * COMBOY VID - Recepción de postulaciones (Trabaja con nosotros)
 * ----------------------------------------------------------------
 * 1) Valida los datos y el CV (PDF / DOC / DOCX, máx. 5 MB).
 * 2) Guarda el CV en una carpeta privada (fuera de public_html si se puede).
 * 3) Registra la postulación en postulaciones.csv (se abre con Excel).
 * 4) Envía el CV por correo a RR.HH.
 * Responde siempre JSON: {ok:true} o {ok:false, error:"..."}.
 */

// ================== CONFIGURACIÓN ==================
$CORREO_DESTINO = 'comboyvidaqp@gmail.com';        // RR.HH. (correo de la convocatoria)
$CORREO_REMITE  = 'no-reply@comboyvid.com';         // debe ser del dominio para no caer en spam
$MAX_BYTES      = 5 * 1024 * 1024;                  // 5 MB
$MAX_POR_HORA   = 6;                                // límite de envíos por IP
// ===================================================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function responder($ok, $msg = '', $code = 200) {
    http_response_code($code);
    echo json_encode($ok ? ['ok' => true, 'msg' => $msg] : ['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function campo($n, $max = 200) {
    $v = isset($_POST[$n]) ? trim((string)$_POST[$n]) : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    return mb_substr($v, 0, $max, 'UTF-8');
}
function una_linea($v) { return trim(preg_replace('/[\r\n]+/', ' ', $v)); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(false, 'Método no permitido.', 405);

// El formulario es grande: si PHP descartó el POST por tamaño, $_POST llega vacío
if (empty($_POST) && empty($_FILES)) responder(false, 'El archivo es demasiado grande. Máximo 5 MB.', 413);

// Anti-spam: campo trampa que una persona nunca llena
if (campo('empresa_web') !== '') responder(true, 'Recibido.');

// ---------- Carpeta privada ----------
$base = dirname(__DIR__, 2) . '/postulaciones-comboyvid';      // /home/USUARIO/postulaciones-comboyvid (fuera de public_html)
if (!is_dir($base) && !@mkdir($base, 0750, true)) {
    $base = __DIR__ . '/postulaciones';                         // respaldo: carpeta protegida con .htaccess
    if (!is_dir($base)) @mkdir($base, 0750, true);
}
if (!is_dir($base) || !is_writable($base)) responder(false, 'No se pudo guardar la postulación. Envíe su CV por WhatsApp.', 500);
if (!file_exists($base . '/.htaccess')) @file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n");

// ---------- Límite por IP ----------
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rl = $base . '/.limite-' . md5($ip);
$hits = array_filter(array_map('intval', @file($rl, FILE_IGNORE_NEW_LINES) ?: []), function ($t) { return $t > time() - 3600; });
if (count($hits) >= $MAX_POR_HORA) responder(false, 'Demasiados envíos desde su conexión. Intente en una hora o use WhatsApp.', 429);

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
if (mb_strlen($d['nombres']) < 5)                          $err[] = 'nombres y apellidos';
if (!preg_match('/^\d{8,12}$/', $d['dni']))                $err[] = 'DNI / CE válido';
if (strlen(preg_replace('/\D/', '', $d['celular'])) < 9)   $err[] = 'celular válido';
if ($d['correo'] !== '' && !filter_var($d['correo'], FILTER_VALIDATE_EMAIL)) $err[] = 'correo válido';
if (campo('acepta') !== 'si')                              $err[] = 'autorización de datos personales';
if ($err) responder(false, 'Complete: ' . implode(', ', $err) . '.', 422);

// ---------- CV ----------
if (!isset($_FILES['cv']) || $_FILES['cv']['error'] === UPLOAD_ERR_NO_FILE) responder(false, 'Adjunte su CV (PDF o Word).', 422);
$f = $_FILES['cv'];
if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > $MAX_BYTES) responder(false, 'El CV pesa más de 5 MB. Comprímalo o envíelo por WhatsApp.', 413);
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
$cabeza = file_get_contents($f['tmp_name'], false, null, 0, 8);
$firmaOk = ($ext === 'pdf'  && strncmp($cabeza, '%PDF', 4) === 0)
        || ($ext === 'docx' && strncmp($cabeza, "PK\x03\x04", 4) === 0)
        || ($ext === 'doc'  && $cabeza === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
if (!$firmaOk || !in_array($mime, $permitidos[$ext], true)) responder(false, 'El archivo no es un PDF/Word válido.', 415);

// ---------- Guardar ----------
$slug = function ($s) {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-') ?: 'postulante';
};
$fecha = (new DateTime('now', new DateTimeZone('America/Lima')));
$nombreArchivo = $fecha->format('Ymd-His') . '_' . substr($slug($d['puesto']), 0, 30) . '_' . substr($slug($d['nombres']), 0, 40) . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
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
    $fila = array_map(function ($v) { return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; }, $fila);
    fputcsv($fh, $fila, ';');
    flock($fh, LOCK_UN); fclose($fh);
}
$hits[] = time();
@file_put_contents($rl, implode("\n", $hits));

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
$adjNombre = 'CV_' . substr($slug($d['nombres']), 0, 50) . '.' . $ext;
$msg  = "--$limite\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($cuerpo)) . "\r\n";
$msg .= "--$limite\r\nContent-Type: $tipoAdj; name=\"$adjNombre\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$adjNombre\"\r\n\r\n" . chunk_split(base64_encode(file_get_contents($destino))) . "\r\n--$limite--";

$enviado = @mail($CORREO_DESTINO, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $msg, $cabeceras, '-f' . $CORREO_REMITE);
if (!$enviado) error_log('COMBOY postular.php: mail() falló para ' . $nombreArchivo);

// El CV ya quedó guardado aunque falle el correo
responder(true, 'Postulación recibida.');
