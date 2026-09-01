/**
 * Detalle de servicio (sección 12). Más simple que el de producto: sin
 * variantes ni stock, pero con la misma sección de compartir (sección 17).
 */
function serviceShareLinks(service) {
  const url = service.canonical_url || window.location.href;
  const text = encodeURIComponent(`Mira "${service.name}" en CASTAMOTO`);
  const encodedUrl = encodeURIComponent(url);

  return {
    whatsapp: `https://wa.me/?text=${text}%20${encodedUrl}`,
    facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`,
    x: `https://twitter.com/intent/tweet?text=${text}&url=${encodedUrl}`,
    telegram: `https://t.me/share/url?url=${encodedUrl}&text=${text}`,
    url,
  };
}

function serviceImageUrls(service) {
  return service.images && service.images.length > 0
    ? service.images.map((img) => helpers.mediaUrl('services', img.url))
    : [];
}

/** Enlace real a Google Maps — "sección cómo llegar". Si el servicio tiene
 * latitud/longitud cargadas (opcional, ver panel admin), apunta al punto
 * exacto; si no, cae a buscar la dirección de texto tal como la escribió
 * el vendedor. Nunca se geocodifica ni se inventan coordenadas. */
function directionsLink(service) {
  if (service.latitude != null && service.longitude != null) {
    return `https://www.google.com/maps/search/?api=1&query=${service.latitude},${service.longitude}`;
  }
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(service.location)}`;
}

/**
 * No todos los servicios se agendan para una fecha/hora puntual (sección
 * nueva, /admin → Servicios → "Requiere agendar fecha y hora") — cuando no,
 * la ficha se salta el selector de fecha/hora y agrega directo al carrito,
 * como un producto cualquiera (ver AddCartItemUseCase del lado del backend,
 * que es quien realmente decide si exige scheduled_at o no).
 */
function serviceRequiresScheduling(service) {
  return Number(service.requires_scheduling ?? 1) !== 0;
}

/**
 * "Es solo informativo" (/admin → Servicios) — para algo como "Parqueadero",
 * que no se reserva ni se paga online: la ficha no ofrece comprar/agendar
 * nada, solo un botón grande de "Cómo llegar" (mismo link que ya arma
 * directionsLink() para la línea de ubicación de más arriba).
 */
function purchaseBoxMarkup(service) {
  if (service.is_informational) {
    if (!service.location) return ''; // sin ubicación cargada no hay a dónde llevar el botón
    return `
      <div class="purchase-box mt-16">
        <a class="btn btn-primary btn-block" href="${directionsLink(service)}" target="_blank" rel="noopener">📍 Cómo llegar</a>
      </div>
    `;
  }

  if (!serviceRequiresScheduling(service)) {
    return `
      <div class="purchase-box mt-16">
        <button class="btn btn-primary btn-block" id="add-service-to-cart-btn">Agregar al carrito</button>
      </div>
    `;
  }

  // Antes se pedía la sesión recién al hacer clic en "Agendar servicio",
  // después de elegir fecha y hora — el usuario reportó que quiere que
  // sea "lo primero que se pida". Ahora, si no hay sesión iniciada, ni
  // siquiera se muestra el formulario de fecha/hora: se pide loguearse
  // primero (mismo modal de siempre) y recién con sesión aparece el form
  // real (redirectAfterLogin() en layout.js recarga la página al loguearse).
  if (!authService.isAuthenticated()) {
    return `
      <div class="purchase-box mt-16">
        <p style="font-weight:700;margin:0 0 10px;">📅 Reservar este servicio</p>
        <p style="color:var(--gris-texto);margin:0 0 12px;">Necesitás iniciar sesión antes de poder elegir fecha y hora.</p>
        <button class="btn btn-primary btn-block" id="service-reservation-login-btn">Iniciar sesión para agendar</button>
      </div>
    `;
  }

  return `
    <div class="purchase-box mt-16">
      <p style="font-weight:700;margin:0 0 10px;">📅 Reservar este servicio</p>
      <div class="form-group">
        <label for="reservation-date">Fecha</label>
        <input type="date" class="form-control" id="reservation-date" style="max-width:220px;">
      </div>
      <div class="form-group">
        <label>Hora</label>
        <div id="reservation-time-slots" class="wash-time-slots">
          <p class="loading-state">Elegí una fecha para ver los horarios disponibles.</p>
        </div>
      </div>
      <button class="btn btn-primary btn-block" id="add-service-to-cart-btn" disabled>Agendar servicio</button>
    </div>
  `;
}

// Horario elegido en la grilla de abajo — no un <input type="time"> nativo
// a propósito: ese control depende del idioma/SO del navegador y en varios
// no deja claro si la hora es AM o PM (justo el problema reportado). Con
// botones en formato 24h ("14:00") no hay ambigüedad posible, mismo
// mecanismo que ya usa el wizard de lavado (lavado.js).
let selectedReservationTime = '';

/** Igual que loadWashTimeSlots() en lavado.js — grilla del horario de
 * atención configurable, marcando como no disponibles los horarios ya
 * ocupados de este servicio en la fecha elegida. */
async function loadServiceTimeSlots(service, date) {
  const mount = document.getElementById('reservation-time-slots');
  mount.innerHTML = '<p class="loading-state">Cargando horarios…</p>';
  selectedReservationTime = '';
  document.getElementById('add-service-to-cart-btn').disabled = true;

  try {
    const [booked, settings] = await Promise.all([
      catalogService.serviceBookedTimes(service.id, date),
      settingsService.get(),
    ]);
    const hours = helpers.resolveScheduleHours(service, settings);
    const businessHours = helpers.generateHourlySlots(hours.start, hours.end);
    const isToday = date === new Date().toISOString().slice(0, 10);
    const nowHour = new Date().getHours();

    mount.innerHTML = `
      <div class="wash-time-grid">
        ${businessHours.map((time) => {
          const isBooked = booked.includes(time);
          const isPast = isToday && Number(time.slice(0, 2)) <= nowHour;
          const disabled = isBooked || isPast;
          return `
            <button type="button" class="wash-time-slot ${disabled ? 'is-disabled' : ''}"
                    data-time="${time}" ${disabled ? 'disabled' : ''} title="${isBooked ? 'Ya reservado' : isPast ? 'Hora pasada' : ''}">
              ${time}
            </button>
          `;
        }).join('')}
      </div>
      ${booked.length === businessHours.length ? '<p class="error-state mt-16">No quedan horarios libres este día — probá con otra fecha.</p>' : ''}
    `;

    mount.querySelectorAll('.wash-time-slot:not(.is-disabled)').forEach((btn) => {
      btn.addEventListener('click', () => {
        selectedReservationTime = btn.dataset.time;
        mount.querySelectorAll('.wash-time-slot').forEach((el) => el.classList.remove('is-selected'));
        btn.classList.add('is-selected');
        document.getElementById('add-service-to-cart-btn').disabled = false;
      });
    });
  } catch (error) {
    mount.innerHTML = '<p class="error-state">No fue posible cargar los horarios disponibles.</p>';
  }
}

function renderServiceDetail(service) {
  const links = serviceShareLinks(service);
  const breadcrumbCategory = service.category_slug
    ? `<a href="${helpers.categoryHref({ slug: service.category_slug })}">${helpers.escapeHtml(service.category_name)}</a>`
    : helpers.escapeHtml(service.category_name || '');

  document.getElementById('service-detail-mount').innerHTML = `
    <nav class="breadcrumbs" aria-label="Ruta de navegación">
      <a href=".">Inicio</a> › <a href="servicios">Servicios</a>
      ${breadcrumbCategory ? ` › ${breadcrumbCategory}` : ''} › <span aria-current="page">${helpers.escapeHtml(service.name)}</span>
    </nav>
    <div class="detail-grid mt-16">
      ${gallery360Markup(serviceImageUrls(service), service.name, washPlaceholderMarkup(service.slug))}
      <div>
        <h1>${helpers.escapeHtml(service.name)}</h1>
        ${service.category_name ? `<p style="color:var(--gris-texto);">${helpers.escapeHtml(service.category_name)}</p>` : ''}
        <p style="font-size:1.8rem;font-weight:800;color:var(--amarillo);margin:12px 0 4px;">${helpers.formatCurrency(service.price)}</p>
        ${service.duration_minutes ? `<p style="color:var(--gris-texto);">Duración estimada: ${service.duration_minutes} min</p>` : ''}
        ${service.warranty ? `<p style="color:var(--gris-texto);font-size:0.85rem;">🛡️ Garantía: ${helpers.escapeHtml(service.warranty)}</p>` : ''}
        ${service.facebook_url ? `<p style="color:var(--gris-texto);"><a href="${helpers.escapeHtml(service.facebook_url)}" target="_blank" rel="noopener" style="color:var(--amarillo);">📘 Ver en Facebook</a></p>` : ''}
        ${service.location ? `
          <p style="color:var(--gris-texto);">
            📍 ${helpers.escapeHtml(service.location)}
            ${service.is_informational ? '' : `— <a href="${directionsLink(service)}" target="_blank" rel="noopener" style="color:var(--amarillo);">Cómo llegar</a>`}
          </p>
        ` : ''}

        ${purchaseBoxMarkup(service)}

        <button class="btn btn-secondary mt-16" id="service-favorite-btn">
          ${service.is_favorite ? '♥ En favoritos' : '♡ Agregar a favoritos'}
        </button>

        <div class="share-row">
          <a class="share-btn" href="${links.whatsapp}" target="_blank" rel="noopener">WhatsApp</a>
          <a class="share-btn" href="${links.facebook}" target="_blank" rel="noopener">Facebook</a>
          <a class="share-btn" href="${links.x}" target="_blank" rel="noopener">X</a>
          <a class="share-btn" href="${links.telegram}" target="_blank" rel="noopener">Telegram</a>
          <button class="share-btn" id="service-copy-link-btn">Copiar enlace</button>
        </div>

        ${service.description ? `<div class="mt-16" style="color:var(--gris-texto);white-space:pre-line;">${helpers.escapeHtml(service.description)}</div>` : ''}
        ${service.cancellation_policy ? `<p class="mt-16" style="font-size:0.8rem;color:var(--gris-texto);"><strong>Política de cancelación:</strong> ${helpers.escapeHtml(service.cancellation_policy)}</p>` : ''}
      </div>
    </div>
  `;

  initGallery360(serviceImageUrls(service));

  // Informativo (ej. "Parqueadero"): el box de compra/reserva ni existe acá
  // (purchaseBoxMarkup() devolvió solo el botón "Cómo llegar", un <a> común,
  // sin nada que cablear) — no hay #reservation-date ni #add-service-to-cart-btn.
  if (!service.is_informational) {
    if (serviceRequiresScheduling(service)) {
      if (!authService.isAuthenticated()) {
        // purchaseBoxMarkup() ya reemplazó el form entero por este botón —
        // ni #reservation-date ni #add-service-to-cart-btn existen acá.
        document.getElementById('service-reservation-login-btn').addEventListener('click', () => openAuthModal('login'));
      } else {
        // La fecha mínima seleccionable es hoy — no tiene sentido agendar en el pasado.
        const dateInput = document.getElementById('reservation-date');
        dateInput.min = new Date().toISOString().slice(0, 10);
        dateInput.addEventListener('change', () => {
          if (dateInput.value) loadServiceTimeSlots(service, dateInput.value);
        });

        document.getElementById('add-service-to-cart-btn').addEventListener('click', async () => {
          const date = dateInput.value;

          if (!date || !selectedReservationTime) {
            helpers.toast('Elige una fecha y una hora para agendar el servicio.', 'error');
            return;
          }

          try {
            await cartService.addItem({ service_id: service.id, quantity: 1, scheduled_at: `${date} ${selectedReservationTime}:00` });
            helpers.toast('Servicio agendado y agregado al carrito.', 'success');
            refreshCartBadge();
          } catch (error) {
            helpers.toast(helpers.flattenErrors(error.fields) || error.message, 'error');
          }
        });
      }
    } else {
      document.getElementById('add-service-to-cart-btn').addEventListener('click', async () => {
        if (!authService.isAuthenticated()) {
          helpers.toast('Inicia sesión para agregar este servicio al carrito.', 'error');
          openAuthModal('login');
          return;
        }

        try {
          // Igual que en producto.js: si ya está en el carrito, se avisa en
          // vez de sumarlo en silencio (el backend lo suma a la misma fila,
          // nunca lo duplica — ver AddCartItemUseCase).
          const cart = await cartService.get();
          const existing = cart.items.find((item) => item.type === 'service' && item.reference_id === service.id);
          if (existing && !window.confirm(`Ya tenés ${existing.quantity} de "${service.name}" en tu carrito. ¿Agregar 1 más?`)) {
            return;
          }

          await cartService.addItem({ service_id: service.id, quantity: 1 });
          helpers.toast('Servicio agregado al carrito.', 'success');
          refreshCartBadge();
        } catch (error) {
          helpers.toast(helpers.flattenErrors(error.fields) || error.message, 'error');
        }
      });
    }
  }

  document.getElementById('service-favorite-btn').addEventListener('click', async () => {
    if (!authService.isAuthenticated()) {
      helpers.toast('Inicia sesión para guardar favoritos.', 'error');
      return;
    }
    try {
      if (service.is_favorite) {
        await catalogService.removeFavorite('service', service.id);
        service.is_favorite = false;
      } else {
        await catalogService.addFavorite('service', service.id);
        service.is_favorite = true;
      }
      renderServiceDetail(service);
    } catch (error) {
      helpers.toast(error.message, 'error');
    }
  });

  document.getElementById('service-copy-link-btn').addEventListener('click', async () => {
    await navigator.clipboard.writeText(links.url);
    helpers.toast('Enlace copiado.', 'success');
  });
}

async function initServiceDetailPage() {
  const slug = helpers.routeParam('slug', 'servicio');
  const mount = document.getElementById('service-detail-mount');

  if (!slug) {
    mount.innerHTML = '<p class="error-state">Servicio no especificado.</p>';
    return;
  }

  try {
    const service = await catalogService.service(slug);
    document.title = `${service.name} — CASTAMOTO`;
    renderServiceDetail(service);
  } catch (error) {
    mount.innerHTML = `<p class="error-state">${helpers.escapeHtml(error.message)}</p>`;
  }
}

document.addEventListener('DOMContentLoaded', initServiceDetailPage);
