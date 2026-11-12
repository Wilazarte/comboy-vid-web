<?php
/**
 * COMBOY VID - Libro de Reclamaciones virtual
 * (Ley N.° 29571 y Reglamento del Libro de Reclamaciones, D.S. N.° 011-2011-PCM y modificatorias)
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
if (!in_array($d['tipo'], ['Reclamo', 'Queja'], true)) $err[] = 'reclamo o queja';
if (largo($d['detalle']) < 10) $err[] = 'detalle';
if (largo($d['pedido']) < 3) $err[] = 'pedido';
if (!$d['acepta']) $err[] = 'la declaración y la Política de Privacidad';
if ($err) responder(false, 'Por favor complete: ' . implode(', ', $err) . '.', 422);

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

// Plazo de respuesta: 15 días hábiles (lunes a viernes; no descuenta feriados)
$plazo = clone $ahora; $habiles = 0;
while ($habiles < 15) { $plazo->modify('+1 day'); if ((int)$plazo->format('N') <= 5) $habiles++; }

$hits[] = time();
@file_put_contents($rl, implode("\n", $hits));

// ---------- Guardar ----------
$fila = [$numero, $ahora->format('Y-m-d H:i'), $d['tipo'], $d['nombres'], $d['tipo_doc'], $d['documento'], $d['domicilio'], $d['telefono'], $d['correo'],
         $d['menor'] ? $d['apoderado'] : '', $d['bien'], $d['monto'], $d['descripcion'], $d['detalle'], $d['pedido'], $plazo->format('Y-m-d'), 'Pendiente', ''];
$cab = ['Numero', 'Fecha', 'Tipo', 'Nombres', 'TipoDoc', 'Documento', 'Domicilio', 'Telefono', 'Correo', 'Apoderado', 'Bien', 'Monto', 'Descripcion', 'Detalle', 'Pedido', 'Responder hasta', 'Estado', 'Respuesta'];
$csv = "$base/reclamaciones.csv";
$nuevo = !file_exists($csv);
if ($c = @fopen($csv, 'a')) {
    flock($c, LOCK_EX);
    if ($nuevo) { fwrite($c, "\xEF\xBB\xBF"); fputcsv($c, $cab, ';'); }
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
$hoja .= "\n2. IDENTIFICACIÓN DEL BIEN CONTRATADO\n{$d['bien']}\nMonto reclamado: " . ($d['monto'] !== '' ? 'S/ ' . $d['monto'] : '-') . "\nDescripción: {$d['descripcion']}\n";
$hoja .= "\n3. DETALLE DE LA RECLAMACIÓN Y PEDIDO DEL CONSUMIDOR\nTipo: " . strtoupper($d['tipo']) . "\nDetalle: {$d['detalle']}\nPedido: {$d['pedido']}\n";
$hoja .= "\n4. OBSERVACIONES Y ACCIONES ADOPTADAS POR EL PROVEEDOR\n(Pendiente. Plazo máximo de respuesta: " . $plazo->format('d/m/Y') . ", 15 días hábiles.)\n";
$hoja .= "\n* RECLAMO: disconformidad relacionada con los productos o servicios.\n* QUEJA: disconformidad no relacionada con los productos o servicios, o malestar o descontento respecto a la atención al público.\n";
$hoja .= "La formulación del reclamo no impide acudir a otras vías de solución de controversias ni es requisito previo para interponer una denuncia ante el INDECOPI.\n";
$hoja .= "El proveedor deberá dar respuesta al reclamo o queja en un plazo no mayor a quince (15) días hábiles improrrogables.\n";

function enviar($para, $asunto, $texto, $remite, $replyTo = '') {
    $cab  = "From: COMBOY VID Libro de Reclamaciones <$remite>\r\n";
    if ($replyTo !== '') $cab .= "Reply-To: $replyTo\r\n";
    $cab .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
    return @mail($para, '=?UTF-8?B?' . base64_encode($asunto) . '?=', chunk_split(base64_encode($texto)), $cab, '-f' . $remite);
}
$correoLimpio = str_replace(["\r", "\n", '<', '>', '"', ','], '', $d['correo']);
$aviso = "Nueva " . strtoupper($d['tipo']) . " registrada en el Libro de Reclamaciones virtual de comboyvid.com.\n"
       . "Debe responder al consumidor, por escrito a su correo, a más tardar el " . $plazo->format('d/m/Y') . " (15 días hábiles).\n"
       . "Registre la respuesta en reclamaciones.csv (carpeta privada reclamaciones-comboyvid del cPanel).\n\n";
$ok1 = enviar($CORREO_DESTINO, "Libro de Reclamaciones N.° $numero - {$d['tipo']} - {$d['nombres']}", $aviso . $hoja, $CORREO_REMITE, $correoLimpio);
$ok2 = enviar($correoLimpio, "Constancia de su " . strtolower($d['tipo']) . " N.° $numero - COMBOY VID",
    "Estimado(a) {$d['nombres']}:\n\nHemos recibido su " . strtolower($d['tipo']) . ". Esta es la copia de su Hoja de Reclamación. "
    . "Le responderemos a este correo en un plazo máximo de 15 días hábiles.\n\n" . $hoja
    . "\nCOMBOY VID · {$PROVEEDOR['razon']} · RUC {$PROVEEDOR['ruc']}\n", $CORREO_REMITE, $CORREO_DESTINO);
if (!$ok1 || !$ok2) error_log("COMBOY reclamo.php: mail() falló para $numero (empresa=" . (int)$ok1 . ', consumidor=' . (int)$ok2 . ')');

responder(true, ['numero' => $numero, 'fecha' => $fechaTxt, 'plazo' => $plazo->format('d/m/Y'), 'correo_enviado' => (bool)$ok2]);
