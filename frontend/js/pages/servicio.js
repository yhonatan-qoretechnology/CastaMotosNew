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

function purchaseBoxMarkup(service) {
  if (!serviceRequiresScheduling(service)) {
    return `
      <div class="purchase-box mt-16">
        <button class="btn btn-primary btn-block" id="add-service-to-cart-btn">Agregar al carrito</button>
      </div>
    `;
  }

  return `
    <div class="purchase-box mt-16">
      <p style="font-weight:700;margin:0 0 10px;">📅 Reservar este servicio</p>
      <div class="form-row">
        <div class="form-group">
          <label for="reservation-date">Fecha</label>
          <input type="date" class="form-control" id="reservation-date">
        </div>
        <div class="form-group">
          <label for="reservation-time">Hora</label>
          <input type="time" class="form-control" id="reservation-time">
        </div>
      </div>
      <button class="btn btn-primary btn-block" id="add-service-to-cart-btn">Agendar servicio</button>
    </div>
  `;
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
            — <a href="${directionsLink(service)}" target="_blank" rel="noopener" style="color:var(--amarillo);">Cómo llegar</a>
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

        ${service.description ? `<div class="mt-16" style="color:var(--gris-texto);">${helpers.escapeHtml(service.description)}</div>` : ''}
        ${service.cancellation_policy ? `<p class="mt-16" style="font-size:0.8rem;color:var(--gris-texto);"><strong>Política de cancelación:</strong> ${helpers.escapeHtml(service.cancellation_policy)}</p>` : ''}
      </div>
    </div>
  `;

  initGallery360(serviceImageUrls(service));

  if (serviceRequiresScheduling(service)) {
    // La fecha mínima seleccionable es hoy — no tiene sentido agendar en el pasado.
    document.getElementById('reservation-date').min = new Date().toISOString().slice(0, 10);

    document.getElementById('add-service-to-cart-btn').addEventListener('click', async () => {
      const date = document.getElementById('reservation-date').value;
      const time = document.getElementById('reservation-time').value;

      if (!date || !time) {
        helpers.toast('Elige una fecha y una hora para agendar el servicio.', 'error');
        return;
      }

      try {
        await cartService.addItem({ service_id: service.id, quantity: 1, scheduled_at: `${date} ${time}:00` });
        helpers.toast('Servicio agendado y agregado al carrito.', 'success');
        refreshCartBadge();
      } catch (error) {
        helpers.toast(helpers.flattenErrors(error.fields) || error.message, 'error');
      }
    });
  } else {
    document.getElementById('add-service-to-cart-btn').addEventListener('click', async () => {
      try {
        await cartService.addItem({ service_id: service.id, quantity: 1 });
        helpers.toast('Servicio agregado al carrito.', 'success');
        refreshCartBadge();
      } catch (error) {
        helpers.toast(helpers.flattenErrors(error.fields) || error.message, 'error');
      }
    });
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
