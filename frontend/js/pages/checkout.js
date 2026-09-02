/**
 * Checkout (sección 19): Carrito → Dirección → Método de entrega →
 * Método de pago → Confirmación → Pedido creado.
 */
let selectedAddressId = null;

async function guardCheckoutAccess() {
  if (!authService.isAuthenticated()) {
    helpers.toast('Inicia sesión para continuar con la compra.', 'error');
    window.location.href = 'carrito';
    return false;
  }
  return true;
}

async function loadCheckoutSummary() {
  const cart = await cartService.get();
  const mount = document.getElementById('checkout-summary');

  if (cart.items.length === 0) {
    document.getElementById('checkout-mount').innerHTML = `
      <div class="empty-state">
        <p>Tu carrito está vacío.</p>
        <a class="btn btn-primary" href="productos">Ver productos</a>
      </div>
    `;
    return null;
  }

  mount.innerHTML = `
    <div class="summary-box">
      ${cart.items.map((item) => `
        <div class="summary-row" data-item-id="${item.id}">
          <span>${item.quantity}× ${helpers.escapeHtml(item.name)}${item.variant_label ? ` <br><small style="color:var(--gris-texto);">${helpers.escapeHtml(item.variant_label)}</small>` : ''}${item.scheduled_at ? ` <br><small style="color:var(--gris-texto);">📅 ${helpers.formatDateTime(item.scheduled_at)}</small>` : ''}</span>
          <span style="display:flex;align-items:center;gap:8px;">
            ${helpers.formatCurrency(item.unit_price * item.quantity)}
            <button type="button" class="icon-btn" data-action="remove-summary-item" data-item-id="${item.id}" aria-label="Quitar" style="padding:2px 6px;font-size:0.75rem;">🗑</button>
          </span>
        </div>
      `).join('')}
      ${cart.coupon_code ? `<div class="summary-row" style="color:var(--exito);"><span>🏷️ Cupón ${helpers.escapeHtml(cart.coupon_code)}</span><span></span></div>` : ''}
      <div class="summary-row"><span>Subtotal</span><span>${helpers.formatCurrency(cart.subtotal)}</span></div>
      <div class="summary-row"><span>Descuento</span><span>-${helpers.formatCurrency(cart.discount_total)}</span></div>
      <div class="summary-row"><span>Impuestos</span><span>${helpers.formatCurrency(cart.tax_total)}</span></div>
      <div class="summary-row"><span>Envío</span><span id="summary-shipping">${helpers.formatCurrency(cart.shipping_total)}</span></div>
      <div class="summary-row total"><span>Total</span><span id="summary-total">${helpers.formatCurrency(cart.total)}</span></div>
    </div>
  `;

  mount.querySelectorAll('[data-action="remove-summary-item"]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        await cartService.removeItem(btn.dataset.itemId);
        helpers.toast('Producto eliminado del pedido.', 'success');
        window.location.reload(); // recalcula todo (direcciones/pago no cambian, solo el resumen)
      } catch (error) {
        helpers.toast(error.message, 'error');
      }
    });
  });

  return cart;
}

async function loadAddresses() {
  const mount = document.getElementById('address-list');
  const addresses = await cartService.addresses();

  if (addresses.length === 0) {
    mount.innerHTML = '<p class="empty-state">Todavía no tienes direcciones guardadas. Agrega una abajo.</p>';
    return;
  }

  mount.innerHTML = addresses.map((address) => `
    <div class="address-option ${address.is_primary ? 'is-selected' : ''}" data-address-id="${address.id}">
      <strong>${helpers.escapeHtml(address.recipient_name)}</strong> — ${helpers.escapeHtml(address.phone)}<br>
      ${helpers.escapeHtml(address.address_line)}, ${helpers.escapeHtml(address.city)}, ${helpers.escapeHtml(address.state)}
    </div>
  `).join('');

  const preselected = addresses.find((address) => address.is_primary) || addresses[0];
  selectedAddressId = preselected.id;

  mount.querySelectorAll('.address-option').forEach((option) => {
    option.addEventListener('click', () => {
      mount.querySelectorAll('.address-option').forEach((el) => el.classList.remove('is-selected'));
      option.classList.add('is-selected');
      selectedAddressId = Number(option.dataset.addressId);
    });
  });
}

/**
 * "Usar mi ubicación" (sección 19: agregar dirección) — pide el GPS del
 * navegador y convierte lat/lon a País/Departamento/Ciudad con geocodificación
 * inversa real (Nominatim/OpenStreetMap, gratis, sin API key: política de uso
 * en https://operations.osmfoundation.org/policies/nominatim/, 1 request a
 * la vez, que es justo el caso de uso acá — un clic manual, no scraping).
 * Solo RELLENA los campos, nunca los envía solo ni oculta el formulario: el
 * usuario siempre puede corregir a mano antes de guardar (el GPS del celular
 * puede errar de barrio, o el usuario puede estar comprando para otra ciudad).
 */
function wireGpsAddressFill() {
  const button = document.getElementById('gps-fill-btn');
  const errorBox = document.getElementById('gps-fill-error');
  if (!button) return;

  button.addEventListener('click', () => {
    errorBox.textContent = '';

    if (!('geolocation' in navigator)) {
      errorBox.textContent = 'Tu navegador no soporta geolocalización.';
      return;
    }

    const originalLabel = button.textContent;
    button.disabled = true;
    button.textContent = 'Obteniendo ubicación…';

    navigator.geolocation.getCurrentPosition(
      async (position) => {
        try {
          const { latitude, longitude } = position.coords;
          const response = await fetch(
            `https://nominatim.openstreetmap.org/reverse?format=json&lat=${latitude}&lon=${longitude}&addressdetails=1&accept-language=es`
          );
          if (!response.ok) throw new Error('El servicio de ubicación no respondió.');

          const data = await response.json();
          const addr = data.address || {};

          if (addr.country) document.getElementById('addr-country').value = addr.country;
          document.getElementById('addr-state').value = addr.state || addr.region || '';
          document.getElementById('addr-city').value = addr.city || addr.town || addr.municipality || addr.village || '';

          if (!addr.city && !addr.town && !addr.municipality && !addr.village) {
            helpers.toast('Ubicación obtenida, pero no se pudo identificar la ciudad exacta — revisa/completa el campo.', 'info');
          } else {
            helpers.toast('País/Departamento/Ciudad completados con tu ubicación.', 'success');
          }
        } catch (error) {
          errorBox.textContent = 'No fue posible convertir tu ubicación en dirección. Completa los campos manualmente.';
        } finally {
          button.disabled = false;
          button.textContent = originalLabel;
        }
      },
      (geoError) => {
        button.disabled = false;
        button.textContent = originalLabel;
        errorBox.textContent = geoError.code === geoError.PERMISSION_DENIED
          ? 'Bloqueaste el permiso de ubicación del navegador — habilítalo o completa los campos manualmente.'
          : 'No fue posible obtener tu ubicación.';
      },
      { enableHighAccuracy: true, timeout: 10000 }
    );
  });
}

function wireNewAddressForm() {
  document.getElementById('new-address-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = document.getElementById('new-address-error');
    errorBox.textContent = '';

    const payload = {
      recipient_name: document.getElementById('addr-recipient').value,
      phone: document.getElementById('addr-phone').value,
      country: document.getElementById('addr-country').value,
      state: document.getElementById('addr-state').value,
      city: document.getElementById('addr-city').value,
      address_line: document.getElementById('addr-line').value,
      complement: document.getElementById('addr-complement').value,
    };

    try {
      await cartService.createAddress(payload);
      helpers.toast('Dirección agregada.', 'success');
      document.getElementById('new-address-form').reset();
      loadAddresses();
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    }
  });
}

async function loadPaymentMethods() {
  const mount = document.getElementById('payment-method-list');
  const methods = await cartService.paymentMethods();

  if (methods.length === 0) {
    mount.innerHTML = '<p class="error-state">No hay métodos de pago disponibles por ahora.</p>';
    return;
  }

  mount.innerHTML = methods.map((method, index) => `
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
      <input type="radio" name="payment_method" value="${method.id}" ${index === 0 ? 'checked' : ''} style="width:auto;">
      ${helpers.escapeHtml(method.name)} — <span style="color:var(--gris-texto);font-size:0.8rem;">${helpers.escapeHtml(method.description || '')}</span>
    </label>
  `).join('');
}

function wireDeliveryMethod(cart) {
  const amountBeforeShipping = cart.subtotal - cart.discount_total + cart.tax_total;

  document.querySelectorAll('input[name="delivery_method"]').forEach((radio) => {
    radio.addEventListener('change', () => {
      // Vista previa: el envío/total real se confirma en la respuesta del backend al pagar.
      const shipping = radio.value === 'recogida_tienda' ? 0 : cart.shipping_total;
      document.getElementById('summary-shipping').textContent = helpers.formatCurrency(shipping);
      document.getElementById('summary-total').textContent = helpers.formatCurrency(amountBeforeShipping + shipping);
    });
  });
}

function wireConfirmOrder() {
  document.getElementById('confirm-order-btn').addEventListener('click', async () => {
    const errorBox = document.getElementById('checkout-error');
    errorBox.textContent = '';

    if (!selectedAddressId) {
      errorBox.textContent = 'Selecciona o agrega una dirección de entrega.';
      return;
    }

    const paymentMethodId = document.querySelector('input[name="payment_method"]:checked')?.value;
    const deliveryMethod = document.querySelector('input[name="delivery_method"]:checked')?.value || 'domicilio';

    const button = document.getElementById('confirm-order-btn');
    button.disabled = true;
    button.textContent = 'Confirmando...';

    try {
      const order = await cartService.checkout({
        address_id: selectedAddressId,
        payment_method_id: Number(paymentMethodId),
        delivery_method: deliveryMethod,
        notes: document.getElementById('order-notes').value || undefined,
      });
      window.location.href = `pedido/${encodeURIComponent(order.order_number)}`;
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
      button.disabled = false;
      button.textContent = 'Confirmar pedido';
    }
  });
}

async function initCheckoutPage() {
  if (!(await guardCheckoutAccess())) return;

  const cart = await loadCheckoutSummary();
  if (!cart) return;

  await loadAddresses();
  await loadPaymentMethods();
  wireGpsAddressFill();
  wireNewAddressForm();
  wireDeliveryMethod(cart);
  wireConfirmOrder();

  // Viene del wizard de reserva de lavado (lavado.js): precarga el teléfono/
  // modelo como nota, para no duplicar esos campos acá — el usuario igual
  // puede editarla o borrarla antes de confirmar.
  const prefilledNote = helpers.queryParam('note');
  if (prefilledNote) {
    document.getElementById('order-notes').value = prefilledNote;
  }
}

document.addEventListener('DOMContentLoaded', initCheckoutPage);
