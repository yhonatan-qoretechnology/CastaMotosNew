/**
 * Utilidades compartidas por todas las páginas: formato de moneda, lectura
 * de query params y notificaciones tipo "toast" (sección 42: feedback visual).
 */
const helpers = {
  formatCurrency(value) {
    const number = Number(value) || 0;
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(number);
  },

  queryParam(name) {
    return new URLSearchParams(window.location.search).get(name);
  },

  /**
   * Lee un parámetro de una URL amigable tipo /producto/{slug} (reescrita en
   * el .htaccess de la raíz hacia producto.html?slug={slug}). La reescritura
   * ocurre en el servidor: el navegador NUNCA ve la URL con querystring, la
   * barra de direcciones sigue mostrando /producto/{slug} — por eso
   * queryParam() por sí solo no encuentra nada al entrar por la ruta amigable.
   * Este método primero intenta leer el segmento final de la ruta real que sí
   * ve el navegador, y solo si no aplica cae a queryParam() (útil si alguna
   * vez se abre el .html directo con ?param=valor, ej. en pruebas).
   */
  routeParam(paramName, pathMarker) {
    const match = window.location.pathname.match(new RegExp(`${pathMarker}/([^/]+)/?$`));
    if (match) return decodeURIComponent(match[1]);
    return helpers.queryParam(paramName);
  },

  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
  },

  /**
   * Selector de idioma (sección nueva): contenido de negocio (nombre/
   * descripción de productos, servicios, categorías) tiene su propia columna
   * *_en, escrita a mano por el admin — a diferencia del texto fijo de la
   * interfaz (ver i18n.js). Vacío/no escrita todavía = fallback al español,
   * nunca un hueco en blanco.
   */
  localized(item, field) {
    if (!item) return '';
    if (typeof i18nService !== 'undefined' && i18nService.current() === 'en') {
      return item[`${field}_en`] || item[field] || '';
    }
    return item[field] || '';
  },

  mediaUrl(type, filename) {
    if (!filename) return null;
    return `api/media/${type}/${filename}`; // relativo: se resuelve contra <base>, ver apiService.js
  },

  /**
   * A dónde lleva el link de una categoría en los menús/chips del sitio
   * (dropdown de categorías, carrusel de la portada). "categoria/{slug}"
   * (.htaccess → productos.html?category=...) solo busca PRODUCTOS — la
   * categoría "Servicios" (y cualquier cosa que algún día viva ahí) nunca
   * tiene productos propios, así que ese link mostraba siempre "No se
   * encontraron productos con estos filtros" aunque sí hubiera servicios
   * cargados. Esa categoría puntual va a /servicios en cambio (que sí sabe
   * filtrar por categoría — ver servicios.js).
   */
  categoryHref(category) {
    if (category.slug === 'servicios') {
      return `servicios?category=${encodeURIComponent(category.slug)}`;
    }
    return `categoria/${encodeURIComponent(category.slug)}`;
  },

  toast(message, variant = 'info') {
    let stack = document.querySelector('.toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'toast-stack';
      document.body.appendChild(stack);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${variant}`;
    toast.textContent = message;
    stack.appendChild(toast);

    setTimeout(() => toast.remove(), 4000);
  },

  /** Traduce el objeto "errors" del backend (campo -> [mensajes]) a un solo texto. */
  flattenErrors(errors) {
    if (!errors || typeof errors !== 'object') return '';
    return Object.values(errors).flat().join(' ');
  },

  /**
   * Estrellas ★★★★☆ a partir del promedio (0-5). Sin reseñas todavía
   * (rating_count = 0, sección 26 llega en una fase futura) no se muestra
   * nada, en vez de simular "0 estrellas" como si hubiera sido calificado.
   */
  renderStars(average, count) {
    const total = Number(count) || 0;
    if (total === 0) return '';

    const rounded = Math.round(Number(average) || 0);
    const stars = '★'.repeat(rounded) + '☆'.repeat(5 - rounded);
    return `<span class="stars">${stars}</span> <span>(${total})</span>`;
  },

  /** Etiqueta en español del estado de un pedido (sección 22) — compartida
   * entre la confirmación del cliente (pedido.js) y el panel admin (admin.js). */
  orderStatusLabel(status) {
    const labels = {
      PENDIENTE: 'Pendiente', CONFIRMADO: 'Confirmado', PAGO_PENDIENTE: 'Pago pendiente',
      PAGO_CONFIRMADO: 'Pago confirmado', PREPARANDO: 'Preparando', EN_CAMINO: 'En camino',
      ENTREGADO: 'Entregado', CANCELADO: 'Cancelado', DEVUELTO: 'Devuelto',
    };
    return labels[status] || status;
  },

  /** Clase de badge (reutiliza los 3 colores ya definidos en main.css) según qué tan "bien" va el estado. */
  orderStatusBadgeClass(status) {
    if (status === 'ENTREGADO') return 'badge-disponible';
    if (status === 'CANCELADO' || status === 'DEVUELTO') return 'badge-agotado';
    return 'badge-ultimas_unidades';
  },

  /** Etiqueta de ACCIÓN (verbo, "qué hacer") para avanzar un pedido al estado
   * indicado — la usa el panel admin para mostrar botones tipo "Confirmar
   * pago" en vez de un selector con el nombre crudo del estado. */
  orderActionLabel(status) {
    const labels = {
      CONFIRMADO: 'Confirmar pedido', PAGO_PENDIENTE: 'Registrar pago pendiente',
      PAGO_CONFIRMADO: 'Confirmar pago', PREPARANDO: 'Marcar en preparación',
      EN_CAMINO: 'Marcar en camino', ENTREGADO: 'Marcar entregado',
      CANCELADO: 'Cancelar pedido', DEVUELTO: 'Marcar devuelto',
    };
    return labels[status] || `Cambiar a ${status}`;
  },

  /** Fecha/hora de una reserva de servicio (sección 12) en formato legible. */
  formatDateTime(value) {
    if (!value) return '';
    const date = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString('es-CO', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  },

  /** Aplana el árbol de categorías (padre + hijos) en una sola lista, para <select>/checkboxes. */
  flattenCategories(tree) {
    return tree.reduce((flat, node) => flat.concat([node], helpers.flattenCategories(node.children || [])), []);
  },

  // Íconos de "ojito" como SVG en línea (trazo = currentColor, hereda el
  // color del botón en cualquier tema) — no emoji: la fuente de emoji varía
  // de un sistema a otro y en algunos Windows se ve como un glifo minúsculo
  // apenas visible, además de no calzar con el resto de la UI.
  EYE_ICON_OPEN: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
  EYE_ICON_OFF: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>',

  /**
   * Activa el botón "ojito" de mostrar/ocultar contraseña sobre cualquier
   * `.password-toggle` dentro de `scope` (todo el documento por defecto).
   * Requiere el markup: <div class="password-field"><input id="x">
   * <button class="password-toggle" type="button" data-target="x"></button></div>
   * — el ícono inicial lo pone esta misma función, no hace falta escribirlo
   * a mano en cada formulario. Se llama después de insertar el HTML del
   * formulario (innerHTML no conserva listeners, así que esto se
   * re-ejecuta cada vez que se redibuja).
   */
  initPasswordToggles(scope = document) {
    scope.querySelectorAll('.password-toggle').forEach((btn) => {
      btn.innerHTML = helpers.EYE_ICON_OPEN;
      btn.addEventListener('click', () => {
        const input = document.getElementById(btn.dataset.target);
        if (!input) return;
        const showing = input.type === 'password';
        input.type = showing ? 'text' : 'password';
        btn.innerHTML = showing ? helpers.EYE_ICON_OFF : helpers.EYE_ICON_OPEN;
        btn.setAttribute('aria-label', showing ? 'Ocultar contraseña' : 'Mostrar contraseña');
      });
    });
  },

  /** Ícono por categoría (mismo criterio que los placeholders de DemoDataSeeder). */
  categoryIcon(slug) {
    const icons = {
      cascos: '🪖', guantes: '🧤', chaquetas: '🧥', llantas: '🛞',
      lubricantes: '🛢️', herramientas: '🔧', electronica: '📡',
      accesorios: '🎒', repuestos: '⚙️', servicios: '🔧', motocicletas: '🏍️',
    };
    return icons[slug] || '🏍️';
  },
};
