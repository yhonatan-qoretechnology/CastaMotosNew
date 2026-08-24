/**
 * Reserva de "Lavado de Motos y Cascos" — wizard paso a paso sobre la MISMA
 * base de reservas de servicios que ya existe (sección 12: scheduled_at,
 * carrito, checkout, panel admin de Reservas). No se reinventa nada nuevo
 * del lado del backend, y el paso 1 NO depende de una lista fija de slugs:
 * muestra TODOS los servicios activos de la categoría "Lavado" (creada por
 * el seeder 011, ver backend/database/seeders). Así, cualquier variante
 * nueva que se cree desde /admin → Servicios y se le asigne esa categoría
 * (una tercera opción, una cuarta, lo que haga falta con el tiempo) aparece
 * sola acá, sin tocar este archivo cada vez — precio y nombre siempre salen
 * del servicio real del catálogo.
 */
const WASH_CATEGORY_SLUG = 'lavado';
const WASH_ICON_BY_KEYWORD = [
  { keyword: 'casco', icon: '🪖' },
  { keyword: 'moto', icon: '🏍️' },
];
const WASH_FALLBACK_ICON = '🧼';

/** Igual que flattenCategoriesList() en servicios.js — el árbol de categorías
 * viene anidado (children[]) y hace falta buscar "lavado" en cualquier nivel. */
function flattenCategories(tree) {
  return tree.reduce((flat, node) => flat.concat([node], flattenCategories(node.children || [])), []);
}

/**
 * Ícono por defecto mientras el servicio no tenga foto propia subida desde
 * /admin → Servicios (se adivina moto/casco por el nombre, y si no matchea
 * ninguno cae a un ícono genérico — no hay forma de saber de antemano cómo
 * se va a llamar cada variante nueva que se cree a futuro). En cuanto exista
 * al menos una imagen real (service.images, mismo campo que usa el resto
 * del catálogo — ver cards.js/servicio.js), se muestra esa foto acá también,
 * sin tocar nada del lado del backend.
 */
function washOptionMedia(service) {
  // El listado por categoría (paginate()) solo trae "primary_image" — no el
  // array "images" completo que sí trae la ficha individual del servicio
  // (findBySlug/find). Cualquiera de los dos que venga sirve acá.
  const image = (service.images && service.images.length > 0 ? service.images[0].url : null) || service.primary_image || null;
  if (image) {
    return `<img class="wash-option__img" src="${helpers.mediaUrl('services', image)}" alt="${helpers.escapeHtml(service.name)}">`;
  }
  const nameLower = (service.name || '').toLowerCase();
  const match = WASH_ICON_BY_KEYWORD.find((entry) => nameLower.includes(entry.keyword));
  return `<span class="wash-option__icon">${match ? match.icon : WASH_FALLBACK_ICON}</span>`;
}

let washState = {
  step: 1,
  service: null, // el servicio real cargado (precio, id, etc.)
  date: '',
  time: '',
  name: '',
  phone: '',
  plate: '', // placa de la moto — obligatoria
  itemNote: '', // observaciones — texto libre, opcional
  primaryAddress: null, // se carga una vez al inicio (ver loadPrimaryAddress) — respaldo de teléfono si el perfil no lo tiene
};

/**
 * El teléfono puede estar cargado en el PERFIL (Mi perfil) o en una
 * DIRECCIÓN guardada (cada una tiene el suyo, sección 9) — son datos
 * distintos que antes el wizard solo miraba por separado. Se trae la
 * dirección principal una sola vez al abrir el wizard para poder usarla
 * como respaldo del teléfono (y a futuro, de la dirección de entrega) sin
 * obligar a cargar todo de nuevo si ya existe en algún lado.
 */
async function loadPrimaryAddress() {
  if (!authService.isAuthenticated()) return;
  try {
    const addresses = await cartService.addresses();
    washState.primaryAddress = addresses.find((a) => a.is_primary) || addresses[0] || null;
  } catch (error) {
    // Sin direcciones todavía, o falló la carga: el wizard sigue con los
    // datos del perfil nomás, no es bloqueante.
  }
}

function setStep(step) {
  washState.step = step;
  document.querySelectorAll('.wash-wizard__step').forEach((el) => {
    el.classList.toggle('is-active', Number(el.dataset.step) === step);
    el.classList.toggle('is-done', Number(el.dataset.step) < step);
  });
  renderStep();
}

async function renderStep() {
  const body = document.getElementById('wash-wizard-body');

  if (washState.step === 1) {
    body.innerHTML = `
      <p class="mt-16" style="color:var(--gris-texto);">¿Qué querés lavar?</p>
      <div class="wash-options" id="wash-options">
        <p class="loading-state">Cargando precios…</p>
      </div>
    `;

    try {
      // La categoría "Lavado" (slug fijo, ver seeder 011) es el único slug
      // que este archivo conoce de antemano — de ahí para abajo, todo sale
      // del catálogo: cuántos servicios hay y cuáles son se resuelve en
      // tiempo real, no a partir de una lista de opciones escrita acá.
      const categories = await catalogService.categories();
      const washCategory = flattenCategories(categories).find((cat) => cat.slug === WASH_CATEGORY_SLUG);
      const list = washCategory
        ? await catalogService.services({ category_id: washCategory.id, per_page: 50 })
        : { data: [] };
      const options = (list.data || []).filter((service) => service.status === 'active');

      if (options.length === 0) {
        document.getElementById('wash-options').innerHTML = `<p class="error-state">No fue posible cargar los servicios de lavado — todavía no están creados en el catálogo.</p>`;
        return;
      }

      document.getElementById('wash-options').innerHTML = options.map((service) => `
        <button type="button" class="wash-option" data-id="${service.id}">
          ${washOptionMedia(service)}
          <span class="wash-option__name">${helpers.escapeHtml(service.name)}</span>
          <span class="wash-option__price">${helpers.formatCurrency(service.price)}</span>
        </button>
      `).join('');

      document.querySelectorAll('.wash-option').forEach((btn) => {
        btn.addEventListener('click', () => {
          washState.service = options.find((s) => String(s.id) === btn.dataset.id);
          setStep(2);
        });
      });
    } catch (error) {
      document.getElementById('wash-options').innerHTML = `<p class="error-state">No fue posible cargar los servicios de lavado — todavía no están creados en el catálogo.</p>`;
    }
    return;
  }

  if (washState.step === 2) {
    body.innerHTML = `
      <p class="mt-16" style="color:var(--gris-texto);">Elegí fecha y hora para "${helpers.escapeHtml(washState.service.name)}":</p>
      <div class="form-group mt-16">
        <label for="wash-date">Fecha</label>
        <input type="date" class="form-control" id="wash-date" value="${washState.date}" style="max-width:220px;">
      </div>
      <div class="form-group">
        <label>Hora</label>
        <div id="wash-time-slots" class="wash-time-slots">
          <p class="loading-state">Elegí una fecha para ver los horarios disponibles.</p>
        </div>
      </div>
      <div class="wash-wizard__nav">
        <button class="btn btn-secondary" id="wash-back-btn">Atrás</button>
        <button class="btn btn-primary" id="wash-next-btn" disabled>Siguiente</button>
      </div>
    `;
    document.getElementById('wash-date').min = new Date().toISOString().slice(0, 10);
    document.getElementById('wash-back-btn').addEventListener('click', () => setStep(1));
    document.getElementById('wash-next-btn').addEventListener('click', () => {
      if (!washState.date || !washState.time) {
        helpers.toast('Elegí una hora disponible para continuar.', 'error');
        return;
      }
      setStep(3);
    });

    document.getElementById('wash-date').addEventListener('change', (event) => {
      washState.date = event.target.value;
      washState.time = ''; // cambiar de fecha invalida la hora ya elegida (los horarios ocupados son por día)
      document.getElementById('wash-next-btn').disabled = true;
      loadWashTimeSlots();
    });

    if (washState.date) loadWashTimeSlots();
    return;
  }

  if (washState.step === 3) {
    const user = authService.currentUser();

    body.innerHTML = `
      <p class="mt-16" style="color:var(--gris-texto);">Tus datos:</p>
      <div class="form-row mt-16">
        <div class="form-group"><label for="wash-name">Nombre</label><input class="form-control" id="wash-name" value="${helpers.escapeHtml(washState.name || (user ? user.name + ' ' + user.last_name : ''))}"></div>
        <div class="form-group"><label for="wash-phone">Celular / WhatsApp</label><input class="form-control" id="wash-phone" value="${helpers.escapeHtml(washState.phone || user?.phone || washState.primaryAddress?.phone || '')}"></div>
      </div>
      <div class="form-group">
        <label for="wash-plate">Placa de la moto</label>
        <input class="form-control" id="wash-plate" value="${helpers.escapeHtml(washState.plate)}" placeholder="Ej: ABC12D">
      </div>
      <div class="form-group">
        <label for="wash-note">Observaciones (opcional)</label>
        <input class="form-control" id="wash-note" value="${helpers.escapeHtml(washState.itemNote)}">
      </div>
      <div class="wash-wizard__nav">
        <button class="btn btn-secondary" id="wash-back-btn">Atrás</button>
        <button class="btn btn-primary" id="wash-next-btn">Siguiente</button>
      </div>
    `;
    document.getElementById('wash-back-btn').addEventListener('click', () => setStep(2));
    document.getElementById('wash-next-btn').addEventListener('click', () => {
      const name = document.getElementById('wash-name').value.trim();
      const phone = document.getElementById('wash-phone').value.trim();
      const plate = document.getElementById('wash-plate').value.trim();
      if (!name || !phone || !plate) {
        helpers.toast('Completá tu nombre, celular/WhatsApp y la placa de la moto para continuar.', 'error');
        return;
      }
      washState.name = name;
      washState.phone = phone;
      washState.plate = plate.toUpperCase();
      washState.itemNote = document.getElementById('wash-note').value.trim();

      // Si el perfil todavía no tenía teléfono, se completa con este —
      // así la próxima vez (acá o en cualquier otro lado del sitio) ya
      // está guardado, sin pedirlo de nuevo. Nunca pisa un teléfono que
      // ya existía ahí (podría ser distinto a propósito).
      if (user && !user.phone) {
        authService.updateProfile({ name: user.name, last_name: user.last_name, phone }).catch(() => {});
      }

      setStep(4);
    });
    return;
  }

  if (washState.step === 4) {
    const scheduledLabel = helpers.formatDateTime(`${washState.date} ${washState.time}:00`);

    body.innerHTML = `
      <p class="mt-16" style="color:var(--gris-texto);">Revisá y confirmá tu reserva:</p>
      <div class="summary-box mt-16">
        <div class="summary-row"><span>${washState.service.name}</span><span>${helpers.formatCurrency(washState.service.price)}</span></div>
        <div class="summary-row"><span>Fecha</span><span>${scheduledLabel}</span></div>
        <div class="summary-row"><span>Nombre</span><span>${helpers.escapeHtml(washState.name)}</span></div>
        <div class="summary-row"><span>Celular / WhatsApp</span><span>${helpers.escapeHtml(washState.phone)}</span></div>
        <div class="summary-row"><span>Placa de la moto</span><span>${helpers.escapeHtml(washState.plate)}</span></div>
        ${washState.itemNote ? `<div class="summary-row"><span>Observaciones</span><span>${helpers.escapeHtml(washState.itemNote)}</span></div>` : ''}
        <div class="summary-row total"><span>Total</span><span>${helpers.formatCurrency(washState.service.price)}</span></div>
      </div>
      <div class="form-error" id="wash-error"></div>
      <div class="wash-wizard__nav">
        <button class="btn btn-secondary" id="wash-back-btn">Atrás</button>
        <button class="btn btn-primary" id="wash-confirm-btn">Reservar</button>
      </div>
    `;
    document.getElementById('wash-back-btn').addEventListener('click', () => setStep(3));
    document.getElementById('wash-confirm-btn').addEventListener('click', confirmReservation);
    return;
  }
}

/** Horario de atención configurable (/admin → Configuración) — antes era fijo acá (08:00 a 17:00). */
async function washBusinessHours() {
  const settings = await settingsService.get();
  return helpers.generateHourlySlots(settings.business_hours_start || '08:30', settings.business_hours_end || '16:30');
}

/**
 * Trae los horarios YA ocupados de este servicio en la fecha elegida
 * (GET /api/services/{id}/booked-times) y arma la grilla marcando esos como
 * no disponibles — así el usuario ve de entrada qué horas no puede elegir,
 * en vez de enterarse recién al confirmar (que es lo que pasaba antes: el
 * backend igual revalida esto mismo al crear el pedido, sección 35, esto
 * solo evita mostrar una opción que de todas formas iba a fallar).
 */
async function loadWashTimeSlots() {
  const mount = document.getElementById('wash-time-slots');
  mount.innerHTML = '<p class="loading-state">Cargando horarios…</p>';

  try {
    const [booked, businessHours] = await Promise.all([
      catalogService.serviceBookedTimes(washState.service.id, washState.date),
      washBusinessHours(),
    ]);
    const isToday = washState.date === new Date().toISOString().slice(0, 10);
    const nowHour = new Date().getHours();

    mount.innerHTML = `
      <div class="wash-time-grid">
        ${businessHours.map((time) => {
          const isBooked = booked.includes(time);
          const isPast = isToday && Number(time.slice(0, 2)) <= nowHour;
          const disabled = isBooked || isPast;
          return `
            <button type="button" class="wash-time-slot ${disabled ? 'is-disabled' : ''} ${washState.time === time ? 'is-selected' : ''}"
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
        washState.time = btn.dataset.time;
        mount.querySelectorAll('.wash-time-slot').forEach((el) => el.classList.remove('is-selected'));
        btn.classList.add('is-selected');
        document.getElementById('wash-next-btn').disabled = false;
      });
    });
  } catch (error) {
    mount.innerHTML = '<p class="error-state">No fue posible cargar los horarios disponibles.</p>';
  }
}

/**
 * El pago del lavado es en el local, no online (a diferencia de un pedido
 * normal) — por eso este wizard NO manda al checkout como antes: agenda el
 * servicio y confirma el pedido acá mismo con el método "Efectivo" (siempre
 * disponible, sin configuración — ver CashPaymentGateway), sin pedirle al
 * cliente que elija dirección/pago para algo que retira en persona.
 *
 * "checkout" igual exige una dirección real en el pedido (address_id NOT
 * NULL) aunque sea retiro en tienda — si el cliente no tiene ninguna
 * guardada, se crea una automáticamente con los datos del local, en vez de
 * obligarlo a cargar una dirección de ENTREGA para algo que no se entrega.
 */
async function resolvePickupAddressId() {
  if (washState.primaryAddress) return washState.primaryAddress.id;

  const address = await cartService.createAddress({
    recipient_name: washState.name,
    phone: washState.phone,
    country: 'Colombia',
    state: 'Caldas',
    city: 'Manizales',
    address_line: 'Retiro en el local — CRA 20 #17-35',
    reference: 'Dirección generada automáticamente para reservas con retiro en tienda.',
  });
  return address.id;
}

async function confirmReservation() {
  if (!authService.isAuthenticated()) {
    helpers.toast('Inicia sesión para completar tu reserva.', 'error');
    openAuthModal('login');
    return;
  }

  const errorBox = document.getElementById('wash-error');
  errorBox.textContent = '';

  const confirmBtn = document.getElementById('wash-confirm-btn');
  confirmBtn.disabled = true;
  confirmBtn.textContent = 'Reservando…';

  try {
    await cartService.addItem({
      service_id: washState.service.id,
      quantity: 1,
      scheduled_at: `${washState.date} ${washState.time}:00`,
    });

    const paymentMethods = await cartService.paymentMethods();
    const cashMethod = paymentMethods.find((method) => method.code === 'cash');
    if (!cashMethod) {
      throw new Error('El pago en efectivo no está disponible — contactanos para completar la reserva.');
    }

    const addressId = await resolvePickupAddressId();

    // El celular/placa/observaciones van como nota del pedido — nombre y
    // fecha ya quedan cubiertos por la reserva misma (scheduled_at).
    const noteParts = [`Tel: ${washState.phone}`, `Placa: ${washState.plate}`];
    if (washState.itemNote) noteParts.push(washState.itemNote);

    await cartService.checkout({
      address_id: addressId,
      payment_method_id: cashMethod.id,
      delivery_method: 'recogida_tienda',
      notes: noteParts.join(' — '),
    });

    helpers.toast('¡Reserva confirmada! Pagás en el local al llegar.', 'success');
    window.location.href = '.';
  } catch (error) {
    errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    confirmBtn.disabled = false;
    confirmBtn.textContent = 'Reservar';
  }
}

document.addEventListener('DOMContentLoaded', async () => {
  await loadPrimaryAddress();
  setStep(1);
});
