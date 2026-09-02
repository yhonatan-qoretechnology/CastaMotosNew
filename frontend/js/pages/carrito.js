/**
 * Página de carrito (sección 18): cantidades, subtotal automático, stock
 * disponible, descuentos/envío/total, y paso a checkout.
 */
async function loadCartPage() {
  const mount = document.getElementById('cart-mount');

  try {
    const cart = await cartService.get();

    if (cart.items.length === 0) {
      mount.innerHTML = `
        <div class="empty-state">
          <p>Tu carrito está vacío.</p>
          <a class="btn btn-primary" href="productos">Ver productos</a>
        </div>
      `;
      return;
    }

    mount.innerHTML = `
      <div class="detail-grid">
        <div id="cart-lines">${cart.items.map(cartLineMarkup).join('')}</div>
        <div>
          <div class="summary-box">
            ${couponBoxMarkup(cart)}
            <div class="summary-row"><span>Subtotal</span><span>${helpers.formatCurrency(cart.subtotal)}</span></div>
            <div class="summary-row"><span>Descuento</span><span>-${helpers.formatCurrency(cart.discount_total)}</span></div>
            <div class="summary-row"><span>Impuestos</span><span>${helpers.formatCurrency(cart.tax_total)}</span></div>
            <div class="summary-row"><span>Envío estimado</span><span>${helpers.formatCurrency(cart.shipping_total)}</span></div>
            <div class="summary-row total"><span>Total</span><span>${helpers.formatCurrency(cart.total)}</span></div>
            <button class="btn btn-primary btn-block mt-16" id="go-to-checkout-btn">Ir a pagar</button>
          </div>
        </div>
      </div>
    `;

    wireCartLineEvents();
    wireCouponEvents();

    document.getElementById('go-to-checkout-btn').addEventListener('click', () => {
      if (!authService.isAuthenticated()) {
        helpers.toast('Inicia sesión para continuar con la compra.', 'error');
        openAuthModal('login');
        return;
      }
      window.location.href = 'checkout';
    });
  } catch (error) {
    mount.innerHTML = `<p class="error-state">${helpers.escapeHtml(error.message)}</p>`;
  }
}

/** Cupón de descuento (sección 30): input para aplicar uno, o el código ya
 * aplicado con botón para quitarlo. */
function couponBoxMarkup(cart) {
  if (cart.coupon_code) {
    return `
      <div class="coupon-box coupon-box--applied">
        <span>🏷️ Cupón <strong>${helpers.escapeHtml(cart.coupon_code)}</strong> aplicado</span>
        <button type="button" class="share-btn" id="remove-coupon-btn">Quitar</button>
      </div>
    `;
  }

  return `
    <form class="coupon-box" id="coupon-form">
      <input type="text" class="form-control" id="coupon-input" placeholder="¿Tienes un cupón?" style="text-transform:uppercase;">
      <button type="submit" class="btn btn-secondary">Aplicar</button>
    </form>
  `;
}

function wireCouponEvents() {
  const form = document.getElementById('coupon-form');
  if (form) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const code = document.getElementById('coupon-input').value.trim();
      if (!code) return;

      try {
        await cartService.applyCoupon(code);
        helpers.toast('Cupón aplicado.', 'success');
        loadCartPage();
      } catch (error) {
        helpers.toast(helpers.flattenErrors(error.fields) || error.message, 'error');
      }
    });
  }

  document.getElementById('remove-coupon-btn')?.addEventListener('click', async () => {
    try {
      await cartService.removeCoupon();
      helpers.toast('Cupón removido.', 'success');
      loadCartPage();
    } catch (error) {
      helpers.toast(error.message, 'error');
    }
  });
}

function cartLineMarkup(item) {
  const image = item.type === 'product' ? helpers.mediaUrl('products', item.image) : helpers.mediaUrl('services', item.image);
  const warning = !item.is_available
    ? '<p class="form-error">Ya no está disponible.</p>'
    : item.quantity_exceeds_stock
      ? `<p class="form-error">Solo quedan ${item.available_stock} disponibles.</p>`
      : '';

  // Un servicio es una reserva para un horario concreto (sección 12): no
  // tiene sentido subir/bajar cantidad como con un producto, solo eliminar.
  const qtyControl = item.type === 'service'
    ? '<span style="font-size:0.8rem;color:var(--gris-texto);">1 reserva</span>'
    : `
      <div class="cart-line__qty">
        <button data-action="decrease" aria-label="Disminuir">−</button>
        <input type="number" min="1" value="${item.quantity}" data-role="quantity">
        <button data-action="increase" aria-label="Aumentar">+</button>
      </div>
    `;

  return `
    <div class="cart-line" data-item-id="${item.id}">
      <div class="cart-line__image">${image ? `<img src="${image}" alt="">` : ''}</div>
      <div class="cart-line__info">
        <div>${helpers.escapeHtml(item.name)}</div>
        ${item.variant_label ? `<div style="font-size:0.78rem;color:var(--gris-texto);">${helpers.variantSwatchMarkup(item.variant_color, item.variant_color_name)}${helpers.escapeHtml(item.variant_label)}</div>` : ''}
        ${item.scheduled_at ? `<div style="font-size:0.78rem;color:var(--gris-texto);">📅 ${helpers.formatDateTime(item.scheduled_at)}</div>` : ''}
        <div style="color:var(--amarillo);font-weight:700;">${helpers.formatCurrency(item.unit_price)}</div>
        ${warning}
      </div>
      ${qtyControl}
      <button class="icon-btn" data-action="remove" aria-label="Eliminar">🗑</button>
    </div>
  `;
}

function wireCartLineEvents() {
  document.querySelectorAll('.cart-line').forEach((line) => {
    const itemId = line.dataset.itemId;
    const input = line.querySelector('[data-role="quantity"]');

    // Los items de servicio no tienen controles de cantidad (ver cartLineMarkup).
    if (input) {
      line.querySelector('[data-action="increase"]').addEventListener('click', () => updateCartLine(itemId, Number(input.value) + 1));
      line.querySelector('[data-action="decrease"]').addEventListener('click', () => {
        const next = Number(input.value) - 1;
        if (next >= 1) updateCartLine(itemId, next);
      });
      input.addEventListener('change', () => {
        const next = Number(input.value);
        if (next >= 1) updateCartLine(itemId, next);
      });
    }

    line.querySelector('[data-action="remove"]').addEventListener('click', () => removeCartLine(itemId));
  });
}

async function updateCartLine(itemId, quantity) {
  try {
    await cartService.updateItem(itemId, quantity);
    refreshCartBadge();
    loadCartPage();
  } catch (error) {
    helpers.toast(helpers.flattenErrors(error.fields) || error.message, 'error');
    loadCartPage();
  }
}

async function removeCartLine(itemId) {
  try {
    await cartService.removeItem(itemId);
    helpers.toast('Producto eliminado.', 'success');
    refreshCartBadge();
    loadCartPage();
  } catch (error) {
    helpers.toast(error.message, 'error');
  }
}

document.addEventListener('DOMContentLoaded', loadCartPage);
