/**
 * Detalle de producto (sección 11): galería con zoom simple, variantes,
 * atributos, disponibilidad, agregar al carrito, favorito y compartir
 * (sección 17: copiar enlace, WhatsApp, Facebook, X, Telegram, Web Share API).
 */
let currentProduct = null;
let currentPaymentMethods = [];

function shareLinks(product) {
  const url = product.canonical_url || window.location.href;
  const text = encodeURIComponent(`Mira "${product.name}" en CASTAMOTO`);
  const encodedUrl = encodeURIComponent(url);

  return {
    whatsapp: `https://wa.me/?text=${text}%20${encodedUrl}`,
    facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`,
    x: `https://twitter.com/intent/tweet?text=${text}&url=${encodedUrl}`,
    telegram: `https://t.me/share/url?url=${encodedUrl}&text=${text}`,
    url,
  };
}

function productImageUrls(product) {
  return product.images && product.images.length > 0
    ? product.images.map((img) => helpers.mediaUrl('products', img.url))
    : [];
}

function variantOptionsMarkup(product) {
  if (!product.variants || product.variants.length === 0) return '';

  const options = product.variants.map((variant) =>
    `<option value="${variant.price_modifier}">${helpers.escapeHtml(variant.name)}${Number(variant.price_modifier) > 0 ? ` (+${helpers.formatCurrency(variant.price_modifier)})` : ''}</option>`
  ).join('');

  return `
    <div class="form-group">
      <label for="variant-select">Variante</label>
      <select class="form-control" id="variant-select">${options}</select>
    </div>
  `;
}

/**
 * "Lo que tienes que saber de este producto" (patrón de marketplace): un
 * resumen de los primeros atributos como viñetas, con la tabla completa
 * plegada detrás de "Ver todas las características" si hay más de 5 — evita
 * que la ficha técnica completa empuje todo lo demás hacia abajo cuando el
 * producto tiene muchos atributos cargados.
 */
function attributesMarkup(product) {
  if (!product.attributes || product.attributes.length === 0) return '';

  const summary = product.attributes.slice(0, 5);
  const hasMore = product.attributes.length > summary.length;

  const summaryList = `
    <ul class="specs-summary">
      ${summary.map((attr) => `<li><strong>${helpers.escapeHtml(attr.name)}:</strong> ${helpers.escapeHtml(attr.value)}</li>`).join('')}
    </ul>
  `;

  const fullTable = `
    <table class="specs-table" id="specs-full-table" ${hasMore ? 'hidden' : ''}>
      ${product.attributes.map((attr) => `<tr><td>${helpers.escapeHtml(attr.name)}</td><td>${helpers.escapeHtml(attr.value)}</td></tr>`).join('')}
    </table>
  `;

  const toggleButton = hasMore
    ? `<button type="button" class="link-btn" id="toggle-specs-btn">Ver todas las características</button>`
    : '';

  return `
    <div class="mt-16">
      <h2 class="specs-title">Lo que tienes que saber de este producto</h2>
      ${summaryList}
      ${toggleButton}
      ${fullTable}
    </div>
  `;
}

/**
 * Medios de pago realmente habilitados ahora mismo (GET /api/payment-methods,
 * el mismo dato público que consume el checkout) — nunca una lista fija en
 * el HTML que podría mentir si un método se desactiva desde /admin.
 */
function paymentMethodsMarkup(methods) {
  if (!methods || methods.length === 0) return '';

  return `
    <div class="payment-methods-box mt-16">
      <strong>Medios de pago disponibles</strong>
      <ul class="payment-methods-list">
        ${methods.map((m) => `<li>${helpers.escapeHtml(m.name)}</li>`).join('')}
      </ul>
    </div>
  `;
}

function renderProductDetail(product) {
  const stockStatus = product.stock_status || 'disponible';
  const stockLabel = { disponible: 'Disponible', ultimas_unidades: `Últimas unidades (${product.stock} disp.)`, agotado: 'Agotado' }[stockStatus];
  const links = shareLinks(product);
  const hasDiscount = product.previous_price && Number(product.previous_price) > Number(product.price);

  const breadcrumbCategory = product.category_slug
    ? `<a href="categoria/${encodeURIComponent(product.category_slug)}">${helpers.escapeHtml(product.category_name)}</a>`
    : helpers.escapeHtml(product.category_name || '');
  const stars = helpers.renderStars(product.rating_avg, product.rating_count);

  document.getElementById('product-detail-mount').innerHTML = `
    <nav class="breadcrumbs" aria-label="Ruta de navegación">
      <a href=".">Inicio</a> › <a href="productos">Productos</a>
      ${breadcrumbCategory ? ` › ${breadcrumbCategory}` : ''} › <span aria-current="page">${helpers.escapeHtml(product.name)}</span>
    </nav>
    <div class="detail-grid mt-16">
      ${gallery360Markup(productImageUrls(product), product.name)}
      <div>
        <h1>${helpers.escapeHtml(product.name)}</h1>
        <p style="color:var(--gris-texto);">${product.brand_name ? helpers.escapeHtml(product.brand_name) + ' · ' : ''}SKU ${helpers.escapeHtml(product.sku)}</p>
        ${stars ? `<p class="rating-summary">${stars}</p>` : ''}

        <div class="purchase-box mt-16">
          <span class="badge badge-${stockStatus}">${stockLabel}</span>
          <p style="font-size:1.8rem;font-weight:800;color:var(--amarillo);margin:12px 0 4px;">
            ${helpers.formatCurrency(product.price)}
            ${hasDiscount ? `<span class="card__price-old" style="font-size:1rem;">${helpers.formatCurrency(product.previous_price)}</span>` : ''}
          </p>
          ${product.short_description ? `<p style="color:var(--gris-texto);">${helpers.escapeHtml(product.short_description)}</p>` : ''}
          ${product.warranty ? `<p style="color:var(--gris-texto);font-size:0.85rem;">🛡️ Garantía: ${helpers.escapeHtml(product.warranty)}</p>` : ''}

          ${variantOptionsMarkup(product)}

          <div class="form-row" style="align-items:flex-end;">
            <div class="form-group" style="max-width:160px;">
              <label for="add-quantity">Cantidad ${stockStatus !== 'agotado' ? `<span class="stock-hint">(+${product.stock} disp.)</span>` : ''}</label>
              <input class="form-control" type="number" id="add-quantity" value="1" min="1" max="${product.stock}">
            </div>
            <div class="form-group" style="flex:2;">
              <button class="btn btn-primary btn-block" id="add-to-cart-btn" ${stockStatus === 'agotado' ? 'disabled' : ''}>
                ${stockStatus === 'agotado' ? 'Agotado' : 'Agregar al carrito'}
              </button>
            </div>
          </div>

          <button class="btn btn-secondary btn-block" id="favorite-btn">
            ${product.is_favorite ? '♥ En favoritos' : '♡ Agregar a favoritos'}
          </button>
        </div>

        <div class="seller-box">
          <span class="seller-box__icon">🏍️</span>
          <div>
            <div class="seller-box__name">Vendido por ${helpers.escapeHtml(product.store_name || 'CASTAMOTO')}</div>
            <div class="seller-box__meta">${product.store_name ? 'Tienda del marketplace' : 'Vendido y despachado directamente por CASTAMOTO'}</div>
          </div>
        </div>

        ${paymentMethodsMarkup(currentPaymentMethods)}

        <div class="product-share">
          <button class="product-share-btn" id="share-toggle-btn" type="button" aria-label="Compartir" aria-haspopup="true" aria-expanded="false">🔗</button>
          <div class="share-dropdown" id="share-dropdown">
            <a href="${links.whatsapp}" target="_blank" rel="noopener">WhatsApp</a>
            <a href="${links.facebook}" target="_blank" rel="noopener">Facebook</a>
            <a href="${links.x}" target="_blank" rel="noopener">X</a>
            <a href="${links.telegram}" target="_blank" rel="noopener">Telegram</a>
            <button type="button" id="copy-link-btn">Copiar enlace</button>
          </div>
        </div>

        ${attributesMarkup(product)}
        ${product.description ? `
          <div class="mt-16">
            <h2 class="specs-title">Descripción</h2>
            <div style="color:var(--gris-texto);white-space:pre-line;">${helpers.escapeHtml(product.description)}</div>
          </div>
        ` : ''}
      </div>
    </div>

    <div class="section">
      <h2 class="section__title">Productos relacionados</h2>
      <div class="carousel" id="related-products"></div>
    </div>

    <div class="section">
      <h2 class="section__title">Opiniones del producto</h2>
      <div id="reviews-mount"><p class="loading-state">Cargando opiniones…</p></div>
    </div>
  `;

  wireProductDetailEvents(product);
}

function wireProductDetailEvents(product) {
  initGallery360(productImageUrls(product));

  document.getElementById('toggle-specs-btn')?.addEventListener('click', (event) => {
    document.getElementById('specs-full-table').hidden = false;
    event.target.remove();
  });

  document.getElementById('add-to-cart-btn')?.addEventListener('click', async () => {
    const quantity = Number(document.getElementById('add-quantity').value) || 1;
    try {
      // Si ya está en el carrito, se lo avisa en vez de sumarlo en silencio
      // — el backend igual lo suma a la misma fila (nunca lo duplica), esto
      // es solo para que no se lleve una sorpresa con la cantidad final.
      const cart = await cartService.get();
      const existing = cart.items.find((item) => item.type === 'product' && item.reference_id === product.id);
      if (existing && !window.confirm(`Ya tenés ${existing.quantity} de "${product.name}" en tu carrito. ¿Agregar ${quantity} más?`)) {
        return;
      }

      await cartService.addItem({ product_id: product.id, quantity });
      helpers.toast('Producto agregado al carrito.', 'success');
      refreshCartBadge();
    } catch (error) {
      helpers.toast(helpers.flattenErrors(error.fields) || error.message, 'error');
    }
  });

  document.getElementById('favorite-btn').addEventListener('click', async () => {
    if (!authService.isAuthenticated()) {
      helpers.toast('Inicia sesión para guardar favoritos.', 'error');
      return;
    }
    try {
      if (product.is_favorite) {
        await catalogService.removeFavorite('product', product.id);
        product.is_favorite = false;
      } else {
        await catalogService.addFavorite('product', product.id);
        product.is_favorite = true;
      }
      renderProductDetail(product);
    } catch (error) {
      helpers.toast(error.message, 'error');
    }
  });

  const links = shareLinks(product);
  const shareToggleBtn = document.getElementById('share-toggle-btn');
  const shareDropdown = document.getElementById('share-dropdown');

  document.getElementById('copy-link-btn').addEventListener('click', async () => {
    await navigator.clipboard.writeText(links.url);
    helpers.toast('Enlace copiado.', 'success');
    shareDropdown.classList.remove('is-open');
  });

  // Con Web Share API disponible (la mayoría de móviles), un solo tap abre
  // la hoja nativa de compartir del sistema en vez de nuestro menú propio —
  // es lo que el usuario ya espera de "el" botón compartir en el celular.
  // En desktop (sin navigator.share) se abre/cierra el menú desplegable.
  shareToggleBtn.addEventListener('click', () => {
    if (navigator.share) {
      navigator.share({ title: product.name, url: links.url }).catch(() => {});
      return;
    }

    const willOpen = !shareDropdown.classList.contains('is-open');
    shareDropdown.classList.toggle('is-open', willOpen);
    shareToggleBtn.setAttribute('aria-expanded', String(willOpen));
  });

  // renderProductDetail() (y por lo tanto este wireup) se vuelve a llamar
  // cada vez que se togglea "favorito" — un listener en `document` sin más
  // se iría acumulando en cada re-render. Se registra una sola vez para
  // toda la página y busca los elementos vigentes por id en cada click, en
  // vez de cerrar sobre las referencias (que quedarían viejas tras el re-render).
  if (!window.__shareDropdownOutsideClickBound) {
    window.__shareDropdownOutsideClickBound = true;
    document.addEventListener('click', (event) => {
      const btn = document.getElementById('share-toggle-btn');
      const dropdown = document.getElementById('share-dropdown');
      if (!btn || !dropdown) return;

      if (!btn.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  const relatedMount = document.getElementById('related-products');
  if (product.related && product.related.length > 0) {
    relatedMount.innerHTML = product.related.map(productCardMarkup).join('');
    wireCardEvents(relatedMount);
  } else {
    relatedMount.innerHTML = '<p class="empty-state">Sin productos relacionados por ahora.</p>';
  }

  loadReviews(product);
}

/**
 * Opiniones del producto (sección 26): lista pública + formulario para
 * dejar una reseña. El backend es quien de verdad exige "compraste esto y
 * todavía no lo reseñaste" (SubmitReviewUseCase) — acá solo se muestra el
 * formulario si hay sesión, y se traduce el error del backend si no
 * corresponde (nunca se oculta el formulario adivinando si compró o no,
 * porque el frontend no tiene forma confiable de saberlo de antemano).
 */
function reviewItemMarkup(review) {
  const stars = '★'.repeat(review.rating) + '☆'.repeat(5 - review.rating);
  const author = [review.user_name, review.user_last_name].filter(Boolean).join(' ');

  return `
    <div class="review-item">
      <div class="review-item__header">
        <span class="stars">${stars}</span>
        <strong>${helpers.escapeHtml(author)}</strong>
        <span class="review-item__date">${helpers.formatDateTime(review.created_at)}</span>
      </div>
      ${review.comment ? `<p class="review-item__comment">${helpers.escapeHtml(review.comment)}</p>` : ''}
    </div>
  `;
}

function reviewFormMarkup() {
  if (!authService.isAuthenticated()) {
    return `<p class="empty-state">Inicia sesión para dejar tu opinión (solo quienes compraron el producto pueden reseñarlo).</p>`;
  }

  return `
    <form id="review-form" class="mt-16">
      <div class="form-group">
        <label for="review-rating">Tu calificación</label>
        <select class="form-control" id="review-rating" required style="max-width:200px;">
          <option value="5">★★★★★ (5)</option>
          <option value="4">★★★★☆ (4)</option>
          <option value="3">★★★☆☆ (3)</option>
          <option value="2">★★☆☆☆ (2)</option>
          <option value="1">★☆☆☆☆ (1)</option>
        </select>
      </div>
      <div class="form-group">
        <label for="review-comment">Tu opinión (opcional)</label>
        <textarea class="form-control" id="review-comment" rows="3" maxlength="1000"></textarea>
      </div>
      <div class="form-error" id="review-error"></div>
      <button class="btn btn-secondary" type="submit">Publicar opinión</button>
    </form>
  `;
}

async function loadReviews(product) {
  const mount = document.getElementById('reviews-mount');
  if (!mount) return;

  try {
    const reviews = await catalogService.reviews('product', product.id);

    mount.innerHTML = `
      ${reviews.length > 0
        ? reviews.map(reviewItemMarkup).join('')
        : '<p class="empty-state">Todavía no hay opiniones de este producto — sé el primero en dejar la tuya.</p>'}
      ${reviewFormMarkup()}
    `;

    document.getElementById('review-form')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const errorBox = document.getElementById('review-error');
      errorBox.textContent = '';

      try {
        await catalogService.submitReview({
          type: 'product',
          id: product.id,
          rating: Number(document.getElementById('review-rating').value),
          comment: document.getElementById('review-comment').value || undefined,
        });
        helpers.toast('¡Gracias por tu opinión!', 'success');
        loadReviews(product);
      } catch (error) {
        errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
      }
    });
  } catch (error) {
    mount.innerHTML = '<p class="error-state">No fue posible cargar las opiniones.</p>';
  }
}

async function initProductDetailPage() {
  const slug = helpers.routeParam('slug', 'producto');
  const mount = document.getElementById('product-detail-mount');

  if (!slug) {
    mount.innerHTML = '<p class="error-state">Producto no especificado.</p>';
    return;
  }

  try {
    currentProduct = await catalogService.product(slug);
    document.title = `${currentProduct.name} — CASTAMOTO`;

    // Best-effort: si falla, la ficha se muestra igual sin esa sección en
    // vez de tumbar toda la página por un dato secundario.
    try {
      currentPaymentMethods = await cartService.paymentMethods();
    } catch (error) {
      currentPaymentMethods = [];
    }

    renderProductDetail(currentProduct);
  } catch (error) {
    mount.innerHTML = `<p class="error-state">${helpers.escapeHtml(error.message)}</p>`;
  }
}

document.addEventListener('DOMContentLoaded', initProductDetailPage);
