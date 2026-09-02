/**
 * Confirmación de pedido (sección 19, paso 6: "Pedido creado").
 */

/**
 * Enlace de WhatsApp con el resumen del pedido ya escrito (sección 17: compartir).
 * Se arma con datos reales del pedido — nunca un texto genérico inventado — y
 * el botón solo se muestra si hay un número de contacto configurado
 * (CONTACT_WHATSAPP_NUMBER en backend/.env, ver settingsService.js).
 */
function orderWhatsappLink(order, whatsappNumber) {
  if (!whatsappNumber) return null;

  const itemsText = order.items.map((item) =>
    `- ${item.quantity}× ${item.name_snapshot}${item.variant_label_snapshot ? ` (${item.variant_label_snapshot})` : ''}${item.scheduled_at ? ` (${helpers.formatDateTime(item.scheduled_at)})` : ''}`
  ).join('\n');
  const message = `Hola CASTAMOTO! Quiero coordinar mi pedido *${order.order_number}*:\n${itemsText}\nTotal: ${helpers.formatCurrency(order.total)}`;

  return `https://wa.me/${whatsappNumber}?text=${encodeURIComponent(message)}`;
}

/** Línea de tiempo del pedido (sección 22/23) — incluye devoluciones/cancelaciones tal cual pasaron. */
function orderHistoryMarkup(history) {
  if (!history || history.length === 0) return '';

  return `
    <div class="purchase-box mt-16">
      <h2 style="font-size:0.95rem;margin:0 0 10px;">Historial del pedido</h2>
      <div class="order-history">
        ${history.map((step) => `
          <div class="order-history__step">
            <span class="badge ${helpers.orderStatusBadgeClass(step.status)}">${helpers.orderStatusLabel(step.status)}</span>
            <span class="order-history__date">${helpers.formatDateTime(step.created_at)}</span>
            ${step.comment ? `<p class="order-history__comment">${helpers.escapeHtml(step.comment)}</p>` : ''}
          </div>
        `).join('')}
      </div>
    </div>
  `;
}

async function initOrderConfirmationPage() {
  const orderNumber = helpers.routeParam('number', 'pedido');
  const mount = document.getElementById('order-mount');

  if (!authService.isAuthenticated()) {
    mount.innerHTML = '<p class="error-state">Inicia sesión para ver este pedido.</p>';
    return;
  }

  if (!orderNumber) {
    mount.innerHTML = '<p class="error-state">Pedido no especificado.</p>';
    return;
  }

  try {
    const [order, settings] = await Promise.all([cartService.order(orderNumber), settingsService.get()]);
    const whatsappLink = orderWhatsappLink(order, settings.contact_whatsapp_number);

    mount.innerHTML = `
      <div class="confirmation-box">
        <div style="font-size:3rem;">✅</div>
        <p>¡Gracias por tu compra!</p>
        <div class="order-number">${helpers.escapeHtml(order.order_number)}</div>
        <span class="badge ${helpers.orderStatusBadgeClass(order.status)}">${helpers.orderStatusLabel(order.status)}</span>
      </div>

      ${orderHistoryMarkup(order.status_history)}

      <div class="summary-box mt-16">
        ${order.items.map((item) => `
          <div class="summary-row">
            <span>${item.quantity}× ${helpers.escapeHtml(item.name_snapshot)}${item.variant_label_snapshot ? ` <br><small style="color:var(--gris-texto);">${helpers.escapeHtml(item.variant_label_snapshot)}</small>` : ''}${item.scheduled_at ? ` <br><small style="color:var(--gris-texto);">📅 ${helpers.formatDateTime(item.scheduled_at)}</small>` : ''}</span>
            <span>${helpers.formatCurrency(item.subtotal)}</span>
          </div>
        `).join('')}
        <div class="summary-row"><span>Subtotal</span><span>${helpers.formatCurrency(order.subtotal)}</span></div>
        <div class="summary-row"><span>Descuento</span><span>-${helpers.formatCurrency(order.discount_total)}</span></div>
        <div class="summary-row"><span>Impuestos</span><span>${helpers.formatCurrency(order.tax_total)}</span></div>
        <div class="summary-row"><span>Envío</span><span>${helpers.formatCurrency(order.shipping_total)}</span></div>
        <div class="summary-row total"><span>Total</span><span>${helpers.formatCurrency(order.total)}</span></div>
      </div>

      <div class="mt-16 flex gap-8" style="flex-wrap:wrap;">
        ${whatsappLink ? `<a class="btn btn-whatsapp" href="${whatsappLink}" target="_blank" rel="noopener">📲 Enviar pedido por WhatsApp</a>` : ''}
        <a class="btn btn-primary" href="productos">Seguir comprando</a>
      </div>
    `;
  } catch (error) {
    mount.innerHTML = `<p class="error-state">${helpers.escapeHtml(error.message)}</p>`;
  }
}

document.addEventListener('DOMContentLoaded', initOrderConfirmationPage);
