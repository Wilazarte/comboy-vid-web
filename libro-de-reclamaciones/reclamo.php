<?php
/**
 * COMBOY VID - Libro de Reclamaciones virtual
 * (Ley N.° 29571 y Reglamento del Libro de Reclamaciones, D.S. N.° 011-2011-PCM,
 *  modificado por D.S. 006-2014-PCM, 058-2017-PCM y 101-2022-PCM)
 * ----------------------------------------------------------------
 * 1) Valida la Hoja de Reclamación.
 * 2) Le asigna un número correlativo por año (000001-2026, 000002-2026...).
 * 3) La guarda en una carpeta privada (fuera de public_html) + reclamaciones.csv (Excel).
 * 4) Envía la hoja por correo a la empresa y una copia (constancia) al consumidor.
 * Responde JSON: {ok:true, numero, fecha, plazo} o {ok:false, error}.
 */

// ================== CONFIGURACIÓN ==================
$CORREO_DESTINO = 'comboyvidaqp@gmail.com';        // ✏️ correo que recibe los reclamos
$CORREO_REMITE  = 'no-reply@comboyvid.com';         // del dominio, para no caer en spam
$MAX_POR_HORA   = 5;                                // límite de envíos por IP
$MAX_BYTES      = 5 * 1024 * 1024;                  // comprobante adjunto: máx. 5 MB
$PROVEEDOR = [
    'razon'     => 'INVERSIONES GLOBAL COMPANY ROUSVID S.A.C.',
    'ruc'       => '20613238566',
    'direccion' => 'Asoc. Viv. Taller El Nazareno Mz. A Lt. 25, Paucarpata, Arequipa',
];
// ===================================================

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function responder($ok, $datos = [], $code = 200) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    echo json_encode($ok ? array_merge(['ok' => true], $datos) : ['ok' => false, 'error' => $datos], JSON_UNESCAPED_UNICODE);
    exit;
}
set_exception_handler(function ($e) { error_log('COMBOY reclamo.php: ' . $e->getMessage()); responder(false, 'Error interno del servidor. Escríbanos a comboyvidaqp@gmail.com.', 500); });

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') responder(false, 'Método no permitido.', 405);
if (empty($_POST) && empty($_FILES)) responder(false, 'El archivo del comprobante es demasiado grande para el servidor (máx. ' . ini_get('post_max_size') . '). Envíe una foto más liviana.', 413);

function campo($n, $max = 200) {
    $v = isset($_POST[$n]) ? trim((string)$_POST[$n]) : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}
function largo($v) { return function_exists("mb_strlen") ? mb_strlen($v, "UTF-8") : strlen($v); }
function una_linea($v) { return trim(preg_replace('/[\r\n]+/', ' ', $v)); }
function puede_usar($ruta) {
    $ob = ini_get('open_basedir');
    if (!$ob) return true;
    foreach (explode(PATH_SEPARATOR, $ob) as $b) { $b = rtrim($b, '/'); if ($b !== '' && strpos($ruta . '/', $b . '/') === 0) return true; }
    return false;
}
function carpeta_base() {
    $base = dirname(__DIR__, 2) . '/reclamaciones-comboyvid';      // /home/USUARIO/reclamaciones-comboyvid (fuera de public_html)
    if (!puede_usar($base) || (!@is_dir($base) && !@mkdir($base, 0750, true))) {
        $base = __DIR__ . '/registros';                             // respaldo: carpeta protegida con .htaccess
        if (!@is_dir($base)) @mkdir($base, 0755, true);
    }
    if (!@is_dir($base) || !@is_writable($base)) return null;
    if (!file_exists($base . '/.htaccess')) @file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n");
    return $base;
}

// ---------- Bot (campo trampa) ----------
if (campo('empresa_web') !== '') responder(true, ['numero' => '000000-0000', 'fecha' => '', 'plazo' => '']);

$base = carpeta_base();
if (!$base) responder(false, 'No se pudo registrar la hoja. Escríbanos a comboyvidaqp@gmail.com.', 500);

// ---------- Límite por IP ----------
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rl = $base . '/.limite-' . md5($ip);
$hits = array_filter(array_map('intval', @file($rl, FILE_IGNORE_NEW_LINES) ?: []), function ($t) { return $t > time() - 3600; });
if (count($hits) >= $MAX_POR_HORA) responder(false, 'Demasiados envíos desde su conexión. Intente en una hora o escriba a comboyvidaqp@gmail.com.', 429);

// ---------- Datos ----------
$d = [
    'nombres'     => una_linea(campo('nombres', 120)),
    'tipo_doc'    => campo('tipo_doc', 20),
    'documento'   => preg_replace('/[^0-9A-Za-z]/', '', campo('documento', 15)),
    'domicilio'   => una_linea(campo('domicilio', 200)),
    'telefono'    => preg_replace('/[^0-9+ ]/', '', campo('telefono', 20)),
    'correo'      => una_linea(campo('correo', 120)),
    'menor'       => campo('menor', 3) === 'si',
    'apoderado'   => una_linea(campo('apoderado', 120)),
    'bien'        => campo('bien', 20),
    'monto'       => campo('monto', 20),
    'descripcion' => campo('descripcion', 1000),
    'comp_tipo'   => campo('comp_tipo', 40),
    'comp_num'    => una_linea(campo('comp_num', 30)),
    'comp_fecha'  => campo('comp_fecha', 10),
    'tipo'        => campo('tipo', 20),
    'detalle'     => campo('detalle', 3000),
    'pedido'      => campo('pedido', 1500),
    'acepta'      => campo('acepta', 3) === 'si',
];
$err = [];
if (largo($d['nombres']) < 5) $err[] = 'nombres y apellidos';
if (!in_array($d['tipo_doc'], ['DNI', 'CE', 'Pasaporte', 'RUC'], true)) $err[] = 'tipo de documento';
if ($d['tipo_doc'] === 'DNI' && !preg_match('/^\d{8}$/', $d['documento'])) $err[] = 'DNI de 8 dígitos';
elseif ($d['tipo_doc'] === 'RUC' && !preg_match('/^\d{11}$/', $d['documento'])) $err[] = 'RUC de 11 dígitos';
elseif (strlen($d['documento']) < 6) $err[] = 'número de documento';
if (largo($d['domicilio']) < 5) $err[] = 'domicilio';
if (strlen(preg_replace('/\D/', '', $d['telefono'])) < 6) $err[] = 'teléfono';
if (!filter_var($d['correo'], FILTER_VALIDATE_EMAIL)) $err[] = 'correo electrónico válido';
if ($d['menor'] && largo($d['apoderado']) < 5) $err[] = 'nombre del padre, madre o apoderado';
if (!in_array($d['bien'], ['Producto', 'Servicio'], true)) $err[] = 'producto o servicio';
if ($d['monto'] !== '' && !preg_match('/^\d{1,9}([.,]\d{1,2})?$/', $d['monto'])) $err[] = 'monto (solo números)';
if (largo($d['descripcion']) < 3) $err[] = 'descripción del producto o servicio';
$TIPOS_COMP = ['Boleta de venta', 'Factura', 'Guía de remisión', 'Nota de venta', 'Otro', 'No tengo comprobante'];
if (!in_array($d['comp_tipo'], $TIPOS_COMP, true)) $err[] = 'tipo de comprobante';
elseif ($d['comp_tipo'] !== 'No tengo comprobante' && largo($d['comp_num']) < 3) $err[] = 'número del comprobante';
if ($d['comp_tipo'] === 'No tengo comprobante') $d['comp_num'] = '';
if ($d['comp_fecha'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['comp_fecha'])) $err[] = 'fecha de compra';
if (!in_array($d['tipo'], ['Reclamo', 'Queja'], true)) $err[] = 'reclamo o queja';
if (largo($d['detalle']) < 10) $err[] = 'detalle';
if (largo($d['pedido']) < 3) $err[] = 'pedido';
if (!$d['acepta']) $err[] = 'la declaración y la Política de Privacidad';
if ($err) responder(false, 'Por favor complete: ' . implode(', ', $err) . '.', 422);

// ---------- Comprobante adjunto (opcional: foto o PDF) ----------
$adj = null;
if (isset($_FILES['comp_archivo']) && $_FILES['comp_archivo']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['comp_archivo'];
    if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > $MAX_BYTES) responder(false, 'El comprobante pesa más de 5 MB. Envíe una foto más liviana.', 413);
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) responder(false, 'No se pudo subir el comprobante. Intente de nuevo.', 400);
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    $tipos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $cab8 = (string)@file_get_contents($f['tmp_name'], false, null, 0, 12);
    $firmaOk = ($ext === 'pdf' && strncmp($cab8, '%PDF', 4) === 0)
            || ($ext === 'jpg' && strncmp($cab8, "\xFF\xD8\xFF", 3) === 0)
            || ($ext === 'png' && strncmp($cab8, "\x89PNG", 4) === 0)
            || ($ext === 'webp' && strncmp($cab8, 'RIFF', 4) === 0 && substr($cab8, 8, 4) === 'WEBP');
    if (!isset($tipos[$ext]) || !$firmaOk) responder(false, 'El comprobante debe ser una foto (JPG, PNG, WEBP) o un PDF.', 415);
    $adj = ['tmp' => $f['tmp_name'], 'ext' => $ext, 'mime' => $tipos[$ext]];
}

// ---------- Número correlativo (por año) ----------
$ahora = new DateTime('now', new DateTimeZone('America/Lima'));
$anio = $ahora->format('Y');
$fh = fopen("$base/correlativo-$anio.txt", 'c+');
if (!$fh) responder(false, 'No se pudo registrar la hoja. Escríbanos a comboyvidaqp@gmail.com.', 500);
flock($fh, LOCK_EX);
$n = (int)stream_get_contents($fh) + 1;
ftruncate($fh, 0); rewind($fh); fwrite($fh, (string)$n); fflush($fh);
flock($fh, LOCK_UN); fclose($fh);
$numero = str_pad((string)$n, 6, '0', STR_PAD_LEFT) . '-' . $anio;

// Plazo de respuesta: 15 días hábiles improrrogables (D.S. 101-2022-PCM).
// Cuenta de lunes a viernes y descuenta los feriados nacionales del Perú.
// ✏️ Cada año agregar los feriados del año siguiente (Jueves/Viernes Santo cambian) y los días no laborables que decrete el Gobierno.
$FERIADOS = [
    '2026-01-01','2026-04-02','2026-04-03','2026-05-01','2026-06-07','2026-06-29','2026-07-23','2026-07-28','2026-07-29',
    '2026-08-06','2026-08-30','2026-10-08','2026-11-01','2026-12-08','2026-12-09','2026-12-25',
    '2027-01-01','2027-03-25','2027-03-26','2027-05-01','2027-06-07','2027-06-29','2027-07-23','2027-07-28','2027-07-29',
    '2027-08-06','2027-08-30','2027-10-08','2027-11-01','2027-12-08','2027-12-09','2027-12-25',
];
$plazo = clone $ahora; $habiles = 0;
while ($habiles < 15) {
    $plazo->modify('+1 day');
    if ((int)$plazo->format('N') <= 5 && !in_array($plazo->format('Y-m-d'), $FERIADOS, true)) $habiles++;
}

$hits[] = time();
@file_put_contents($rl, implode("\n", $hits));

// Guardar el comprobante en la carpeta privada: comprobantes/000001-2026-comprobante.jpg
$archivoComp = '';
if ($adj) {
    if (!@is_dir("$base/comprobantes")) @mkdir("$base/comprobantes", 0750, true);
    $archivoComp = "$numero-comprobante.{$adj['ext']}";
    if (@move_uploaded_file($adj['tmp'], "$base/comprobantes/$archivoComp")) @chmod("$base/comprobantes/$archivoComp", 0640);
    else { error_log("COMBOY reclamo.php: no se pudo guardar el comprobante de $numero"); $archivoComp = ''; }
}
$compTxt = $d['comp_tipo'] . ($d['comp_num'] !== '' ? ' N.° ' . $d['comp_num'] : '')
         . ($d['comp_fecha'] !== '' ? ' · Fecha de compra: ' . DateTime::createFromFormat('Y-m-d', $d['comp_fecha'])->format('d/m/Y') : '');

// ---------- Guardar ----------
$fila = [$numero, $ahora->format('Y-m-d H:i'), $d['tipo'], $d['nombres'], $d['tipo_doc'], $d['documento'], $d['domicilio'], $d['telefono'], $d['correo'],
         $d['menor'] ? $d['apoderado'] : '', $d['bien'], $d['monto'], $d['descripcion'], $d['detalle'], $d['pedido'], $plazo->format('Y-m-d'), 'Pendiente', '', '', $compTxt, $archivoComp];
$cab = ['Numero', 'Fecha', 'Tipo', 'Nombres', 'TipoDoc', 'Documento', 'Domicilio', 'Telefono', 'Correo', 'Apoderado', 'Bien', 'Monto', 'Descripcion', 'Detalle', 'Pedido', 'Responder hasta', 'Estado', 'Respuesta', 'Fecha respuesta', 'Comprobante', 'Archivo comprobante'];
$csv = "$base/reclamaciones.csv";
$nuevo = !file_exists($csv);
if ($c = @fopen($csv, 'a')) {
    flock($c, LOCK_EX);
    if ($nuevo) { fwrite($c, "\xEF\xBB\xBF"); fputcsv($c, $cab, ';'); }
    else {
        // CSV creado antes de agregar el comprobante: actualizar la fila de títulos
        $txt = (string)@file_get_contents($csv);
        $fin = strpos($txt, "\n");
        if ($fin !== false && strpos(substr($txt, 0, $fin), 'Comprobante') === false) {
            $tmp = fopen('php://temp', 'r+'); fputcsv($tmp, $cab, ';'); rewind($tmp);
            @file_put_contents($csv, "\xEF\xBB\xBF" . stream_get_contents($tmp) . substr($txt, $fin + 1)); fclose($tmp);
        }
    }
    fputcsv($c, array_map(function ($v) { return preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v; }, $fila), ';');
    flock($c, LOCK_UN); fclose($c);
}

// ---------- Hoja en texto (correo) ----------
$fechaTxt = $ahora->format('d/m/Y H:i');
$hoja  = "LIBRO DE RECLAMACIONES - HOJA DE RECLAMACIÓN N.° $numero\n";
$hoja .= "Fecha: $fechaTxt (hora Perú)\n";
$hoja .= "Proveedor: {$PROVEEDOR['razon']} (COMBOY VID) - RUC {$PROVEEDOR['ruc']}\n{$PROVEEDOR['direccion']}\n\n";
$hoja .= "1. IDENTIFICACIÓN DEL CONSUMIDOR RECLAMANTE\n";
$hoja .= "Nombre: {$d['nombres']}\n{$d['tipo_doc']}: {$d['documento']}\nDomicilio: {$d['domicilio']}\nTeléfono: {$d['telefono']}\nCorreo: {$d['correo']}\n";
if ($d['menor']) $hoja .= "Menor de edad. Padre, madre o apoderado: {$d['apoderado']}\n";
$hoja .= "\n2. IDENTIFICACIÓN DEL BIEN CONTRATADO\n{$d['bien']}\nMonto reclamado: " . ($d['monto'] !== '' ? 'S/ ' . $d['monto'] : '-') . "\nComprobante: $compTxt\n"
       . "Comprobante adjunto: " . ($archivoComp !== '' ? 'Sí' : 'No') . "\nDescripción: {$d['descripcion']}\n";
$hoja .= "\n3. DETALLE DE LA RECLAMACIÓN Y PEDIDO DEL CONSUMIDOR\nTipo: " . strtoupper($d['tipo']) . "\nDetalle: {$d['detalle']}\nPedido: {$d['pedido']}\n";
$hoja .= "\n4. OBSERVACIONES Y ACCIONES ADOPTADAS POR EL PROVEEDOR\n(Pendiente. Plazo máximo de respuesta: " . $plazo->format('d/m/Y') . ", 15 días hábiles.)\n";
$hoja .= "Fecha de comunicación de la respuesta: (pendiente)\n";
$hoja .= "Medio de respuesta autorizado por el consumidor: correo electrónico {$d['correo']}\n";
$hoja .= "\n* RECLAMO: disconformidad relacionada con los productos o servicios.\n* QUEJA: disconformidad no relacionada con los productos o servicios, o malestar o descontento respecto a la atención al público.\n";
$hoja .= "La formulación del reclamo no impide acudir a otras vías de solución de controversias ni es requisito previo para interponer una denuncia ante el INDECOPI.\n";
$hoja .= "El proveedor deberá dar respuesta al reclamo o queja en un plazo no mayor a quince (15) días hábiles improrrogables.\n";

// Copia individual de cada hoja (evidencia; conservar mínimo 2 años)
if (!@is_dir("$base/hojas")) @mkdir("$base/hojas", 0750, true);
@file_put_contents("$base/hojas/hoja-$numero.txt", $hoja);

function enviar($para, $asunto, $texto, $remite, $replyTo = '', $adjunto = null) {
    $cab  = "From: COMBOY VID Libro de Reclamaciones <$remite>\r\n";
    if ($replyTo !== '') $cab .= "Reply-To: $replyTo\r\n";
    $cab .= "MIME-Version: 1.0\r\n";
    if (!$adjunto) {
        $cab .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        $cuerpo = chunk_split(base64_encode($texto));
    } else {
        $lim = 'lr_' . bin2hex(random_bytes(8));
        $cab .= "Content-Type: multipart/mixed; boundary=\"$lim\"\r\n";
        $cuerpo  = "--$lim\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto)) . "\r\n";
        $cuerpo .= "--$lim\r\nContent-Type: {$adjunto['mime']}; name=\"{$adjunto['nombre']}\"\r\nContent-Transfer-Encoding: base64\r\n"
                .  "Content-Disposition: attachment; filename=\"{$adjunto['nombre']}\"\r\n\r\n" . chunk_split(base64_encode($adjunto['datos'])) . "\r\n--$lim--";
    }
    return @mail($para, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, $cab, '-f' . $remite);
}
$adjEmpresa = ($archivoComp !== '') ? ['mime' => $adj['mime'], 'nombre' => $archivoComp, 'datos' => (string)@file_get_contents("$base/comprobantes/$archivoComp")] : null;
$correoLimpio = str_replace(["\r", "\n", '<', '>', '"', ','], '', $d['correo']);
$aviso = "Nueva " . strtoupper($d['tipo']) . " registrada en el Libro de Reclamaciones virtual de comboyvid.com.\n"
       . "Debe responder al consumidor, por escrito a su correo, a más tardar el " . $plazo->format('d/m/Y') . " (15 días hábiles).\n"
       . "Responda con \"Responder\" a este correo (va directo al consumidor) indicando el N.° $numero, y anote la fecha y la respuesta\n"
       . "en reclamaciones.csv (carpeta privada reclamaciones-comboyvid del cPanel). Conserve la hoja y la respuesta mínimo 2 años.\n\n";
$ok1 = enviar($CORREO_DESTINO, "Libro de Reclamaciones N.° $numero - {$d['tipo']} - {$d['nombres']}", $aviso . $hoja, $CORREO_REMITE, $correoLimpio, $adjEmpresa);
$ok2 = enviar($correoLimpio, "Constancia de su " . strtolower($d['tipo']) . " N.° $numero - COMBOY VID",
    "Estimado(a) {$d['nombres']}:\n\nHemos recibido su " . strtolower($d['tipo']) . ". Esta es la copia de su Hoja de Reclamación. "
    . "Le responderemos a este correo en un plazo máximo de 15 días hábiles.\n\n" . $hoja
    . "\nCOMBOY VID · {$PROVEEDOR['razon']} · RUC {$PROVEEDOR['ruc']}\n", $CORREO_REMITE, $CORREO_DESTINO);
if (!$ok1 || !$ok2) error_log("COMBOY reclamo.php: mail() falló para $numero (empresa=" . (int)$ok1 . ', consumidor=' . (int)$ok2 . ')');

responder(true, ['numero' => $numero, 'fecha' => $fechaTxt, 'plazo' => $plazo->format('d/m/Y'), 'correo_enviado' => (bool)$ok2, 'comprobante_adjunto' => $archivoComp !== '']);
