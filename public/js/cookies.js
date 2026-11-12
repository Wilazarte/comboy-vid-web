/* =========================================================
   COMBOY VID · Banner de cookies
   - Se incluye en todas las páginas: <script src="public/js/cookies.js" defer></script>
     (desde una subcarpeta: ../public/js/cookies.js)
   - Guarda la elección en localStorage ("cv_cookies") por 6 meses.
   - "Aceptar": carga contenido de terceros (videos de TikTok y mapas de Google).
   - "Rechazar": solo funciona lo necesario; los iframes marcados con
     data-cv-src muestran un aviso con botón para cargarlos.
   - Para volver a elegir: cualquier elemento con data-cv-cookies (ej. el link
     "Configurar cookies" del footer) abre el banner otra vez.
   API: window.cvCookies.permitido() · .aceptar() · .rechazar() · .abrir() · .escanear(raiz)
        evento: document 'cv:cookies' (detail: 'aceptadas' | 'rechazadas')
   ========================================================= */
(function () {
  'use strict';
  if (window.cvCookies) return;

  var CLAVE = 'cv_cookies';
  var VERSION = 1;                         // súbalo si cambia la política: vuelve a preguntar
  var VIGENCIA_MS = 180 * 24 * 3600 * 1000; // 6 meses

  // Raíz del sitio a partir de la ubicación de este archivo (public/js/cookies.js)
  var actual = document.currentScript && document.currentScript.src;
  var RAIZ = actual ? new URL('../../', actual).href : '/';
  var URL_COOKIES = RAIZ + 'politica-de-cookies/';
  var URL_PRIVACIDAD = RAIZ + 'politica-de-privacidad/';

  function leer() {
    try {
      var v = JSON.parse(localStorage.getItem(CLAVE) || 'null');
      if (!v || v.v !== VERSION || (Date.now() - v.fecha) > VIGENCIA_MS) return null;
      return v.estado;
    } catch (e) { return null; }
  }
  function guardar(estado) {
    try { localStorage.setItem(CLAVE, JSON.stringify({ estado: estado, v: VERSION, fecha: Date.now() })); } catch (e) {}
  }

  var estado = leer();   // 'aceptadas' | 'rechazadas' | null (sin elegir)

  /* ---------- Estilos (paleta de la web: navy, plomo, azul) ---------- */
  var css = '' +
    '#cv-ck{position:fixed;left:16px;right:16px;bottom:16px;z-index:2147483000;max-width:760px;margin:0 auto;' +
      'background:#1E293B;color:#E2E8F0;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.35);' +
      'font:400 14px/1.6 "Libre Baskerville",Georgia,serif;padding:18px 20px;border-top:4px solid #2563EB}' +
    '#cv-ck[hidden]{display:none!important}' +
    '#cv-ck h2{margin:0 0 6px;font:700 16px/1.3 "Libre Baskerville",Georgia,serif;color:#fff}' +
    '#cv-ck p{margin:0}' +
    '#cv-ck a{color:#BFDBFE;text-decoration:underline}' +
    '#cv-ck .cv-ck-bt{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;justify-content:flex-end}' +
    '#cv-ck button,.cv-ck-ph button{font:700 14px/1.2 "Libre Baskerville",Georgia,serif;padding:11px 18px;border-radius:8px;cursor:pointer;border:2px solid #2563EB}' +
    '#cv-ck .cv-ck-si,.cv-ck-ph button{background:#2563EB;color:#fff}' +
    '#cv-ck .cv-ck-si:hover,.cv-ck-ph button:hover{background:#1D4ED8;border-color:#1D4ED8}' +
    '#cv-ck .cv-ck-no{background:transparent;color:#fff;border-color:#94A3B8}' +
    '#cv-ck .cv-ck-no:hover{border-color:#fff}' +
    '@media(max-width:560px){#cv-ck{left:10px;right:10px;bottom:10px;padding:16px;font-size:13px}#cv-ck .cv-ck-bt button{flex:1 1 0}}' +
    '.cv-ck-ph{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;text-align:center;' +
      'width:100%;height:100%;min-height:220px;padding:24px;background:#E2E8F0;color:#334155;' +
      'font:400 14px/1.6 "Libre Baskerville",Georgia,serif}' +
    '.cv-ck-ph strong{color:#0F172A}' +
    '.cv-ck-ph a{color:#2563EB}';
  var st = document.createElement('style');
  st.id = 'cv-ck-css';
  st.textContent = css;
  (document.head || document.documentElement).appendChild(st);

  /* ---------- Contenido de terceros bloqueado: iframe[data-cv-src] ---------- */
  function nombreTercero(src) {
    if (/tiktok/i.test(src)) return 'TikTok';
    if (/google\.[^/]+\/maps|maps\.google/i.test(src)) return 'Google Maps';
    if (/youtube/i.test(src)) return 'YouTube';
    return 'un servicio externo';
  }
  function escanear(raiz) {
    var lista = (raiz || document).querySelectorAll('iframe[data-cv-src]');
    for (var i = 0; i < lista.length; i++) {
      var f = lista[i];
      var ph = f.previousElementSibling && f.previousElementSibling.classList.contains('cv-ck-ph') ? f.previousElementSibling : null;
      if (estado === 'aceptadas') {
        if (f.getAttribute('src') !== f.dataset.cvSrc) f.setAttribute('src', f.dataset.cvSrc);
        f.style.display = '';
        if (ph) ph.remove();
      } else if (!ph) {
        var quien = nombreTercero(f.dataset.cvSrc);
        ph = document.createElement('div');
        ph.className = 'cv-ck-ph';
        ph.innerHTML = '<p><strong>Contenido de ' + quien + '</strong><br>' +
          'Este contenido puede usar cookies de ' + quien + '. Se muestra solo si usted lo acepta.</p>' +
          '<button type="button">Aceptar cookies y ver</button>' +
          '<a href="' + URL_COOKIES + '">Política de cookies</a>';
        ph.querySelector('button').addEventListener('click', aceptar);
        f.style.display = 'none';
        f.parentNode.insertBefore(ph, f);
      }
    }
  }

  /* ---------- Banner ---------- */
  var banner = null;
  function crearBanner() {
    if (banner) return banner;
    banner = document.createElement('div');
    banner.id = 'cv-ck';
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-live', 'polite');
    banner.setAttribute('aria-labelledby', 'cv-ck-t');
    banner.innerHTML =
      '<h2 id="cv-ck-t">Cookies en COMBOY VID</h2>' +
      '<p>Usamos almacenamiento local necesario para que la web funcione (por ejemplo, su lista de cotización). ' +
      'Si acepta, también cargaremos videos de TikTok y mapas de Google, que pueden instalar sus propias cookies. ' +
      'Más información en la <a href="' + URL_COOKIES + '">Política de cookies</a> y la ' +
      '<a href="' + URL_PRIVACIDAD + '">Política de privacidad</a>.</p>' +
      '<div class="cv-ck-bt">' +
        '<button type="button" class="cv-ck-no">Rechazar</button>' +
        '<button type="button" class="cv-ck-si">Aceptar</button>' +
      '</div>';
    banner.querySelector('.cv-ck-si').addEventListener('click', aceptar);
    banner.querySelector('.cv-ck-no').addEventListener('click', rechazar);
    document.body.appendChild(banner);
    return banner;
  }
  function abrir() { crearBanner().hidden = false; }
  function cerrar() { if (banner) banner.hidden = true; }

  function fijar(nuevo) {
    var antes = estado;
    estado = nuevo;
    guardar(nuevo);
    cerrar();
    if (nuevo === 'aceptadas') {
      escanear();
    } else if (antes === 'aceptadas') {
      // Retiró el consentimiento: recargar para descargar los iframes de terceros
      location.reload();
      return;
    }
    try { document.dispatchEvent(new CustomEvent('cv:cookies', { detail: nuevo })); } catch (e) {}
  }
  function aceptar() { fijar('aceptadas'); }
  function rechazar() { fijar('rechazadas'); }

  window.cvCookies = {
    permitido: function () { return estado === 'aceptadas'; },
    estado: function () { return estado; },
    aceptar: aceptar,
    rechazar: rechazar,
    abrir: abrir,
    escanear: escanear
  };

  // Links "Configurar cookies"
  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-cv-cookies]');
    if (t) { e.preventDefault(); abrir(); }
  });

  function iniciar() {
    escanear();
    if (!estado) abrir();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
