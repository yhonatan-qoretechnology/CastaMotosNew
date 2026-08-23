/**
 * Home (sección 41): buscador grande, categorías, productos y servicios
 * destacados, sección de confianza.
 */
async function loadHomeCategories() {
  const mount = document.getElementById('home-categories');
  if (!mount) return;

  try {
    const tree = await catalogService.categories();
    // Se muestran las subcategorías navegables (accesos rápidos, como en un
    // marketplace), no las raíces del árbol — con una sola raíz ("Motocicletas")
    // mostrar solo eso no ayuda a explorar el catálogo.
    const chips = tree.flatMap((cat) => (cat.children && cat.children.length > 0 ? cat.children : [cat]));

    if (chips.length === 0) {
      mount.innerHTML = `<p class="empty-state">${i18nService.t('home.empty.categories')}</p>`;
      return;
    }

    // Carrusel animado (desplazamiento automático continuo, sección 41):
    // el track se pinta DOS VECES seguidas y la animación CSS lo corre de
    // 0% a -50% — como la segunda copia es idéntica a la primera, al llegar
    // a -50% se ve exactamente igual que al arrancar en 0%, así que el loop
    // es perfecto sin ningún salto ni parpadeo. Se pausa al pasar el mouse
    // (o con foco de teclado) para poder leer/hacer click sin que se mueva.
    const chipMarkup = (cat) => `
      <a class="card category-chip" href="${helpers.categoryHref(cat)}">
        <div class="card__body text-center">
          <span class="card__icon">${helpers.categoryIcon(cat.slug)}</span>
          <span class="card__name">${helpers.escapeHtml(helpers.localized(cat, 'name'))}</span>
        </div>
      </a>
    `;

    mount.innerHTML = `
      <div class="category-carousel">
        <div class="category-carousel__track">
          ${chips.map(chipMarkup).join('')}
          <span aria-hidden="true" style="display:contents;">${chips.map(chipMarkup).join('')}</span>
        </div>
      </div>
    `;
  } catch (error) {
    mount.innerHTML = `<p class="error-state">${i18nService.t('home.error.categories')}</p>`;
  }
}

async function loadDeals() {
  const section = document.getElementById('home-deals-section');
  const mount = document.getElementById('home-deals');
  if (!mount) return;

  try {
    // Solo productos con descuento real ya cargado (discount_percentage > 0);
    // si no hay ninguno todavía, la sección completa se queda oculta en vez
    // de mostrar un "Ofertas" vacío o inventar descuentos.
    const result = await catalogService.products({ on_sale: 1, per_page: 8 });
    if (result.data.length === 0) return;

    section.hidden = false;
    mount.innerHTML = `<div class="grid">${result.data.map(productCardMarkup).join('')}</div>`;
    wireCardEvents(mount);
  } catch (error) {
    console.error('No fue posible cargar las ofertas.', error);
  }
}

async function loadFeaturedProducts() {
  const mount = document.getElementById('home-products');
  if (!mount) return;

  try {
    const result = await catalogService.products({ sort: 'newest', per_page: 8 });
    if (result.data.length === 0) {
      mount.innerHTML = `<p class="empty-state">${i18nService.t('home.empty.products')}</p>`;
      return;
    }

    mount.innerHTML = `<div class="grid">${result.data.map(productCardMarkup).join('')}</div>`;
    wireCardEvents(mount);
  } catch (error) {
    mount.innerHTML = `<p class="error-state">${i18nService.t('home.error.products')}</p>`;
  }
}

/** Igual que flattenCategoriesList() en servicios.js/lavado.js — el árbol de
 * categorías viene anidado (children[]) y hace falta buscar "lavado" en
 * cualquier nivel. */
function flattenCategories(tree) {
  return tree.reduce((flat, node) => flat.concat([node], flattenCategories(node.children || [])), []);
}

/**
 * Banner "Lavado de Motos y Cascos" (arriba de esta sección) + las tarjetas
 * reales de la categoría "Lavado" acá debajo — mismos servicios que carga el
 * wizard (lavado.js): nombre, precio y foto salen del catálogo, no hay nada
 * hardcodeado. Si todavía no hay ninguno creado, se ve el mismo mensaje que
 * ya usa el wizard en ese caso.
 */
async function loadWashServices() {
  const mount = document.getElementById('home-wash');
  if (!mount) return;

  try {
    const categories = await catalogService.categories();
    const washCategory = flattenCategories(categories).find((cat) => cat.slug === 'lavado');
    const result = washCategory
      ? await catalogService.services({ category_id: washCategory.id, per_page: 8 })
      : { data: [] };

    if (result.data.length === 0) {
      mount.innerHTML = `<p class="error-state">${i18nService.t('home.error.wash')}</p>`;
      return;
    }

    mount.innerHTML = `<div class="grid">${result.data.map(serviceCardMarkup).join('')}</div>`;
    wireCardEvents(mount);
  } catch (error) {
    mount.innerHTML = `<p class="error-state">${i18nService.t('home.error.wash')}</p>`;
  }
}

async function loadFeaturedServices() {
  const mount = document.getElementById('home-services');
  if (!mount) return;

  try {
    const result = await catalogService.services({ sort: 'newest', per_page: 4 });
    if (result.data.length === 0) {
      mount.innerHTML = `<p class="empty-state">${i18nService.t('home.empty.services')}</p>`;
      return;
    }

    mount.innerHTML = `<div class="grid">${result.data.map(serviceCardMarkup).join('')}</div>`;
    wireCardEvents(mount);
  } catch (error) {
    mount.innerHTML = `<p class="error-state">${i18nService.t('home.error.services')}</p>`;
  }
}

function wireHomeSearch() {
  const form = document.getElementById('hero-search-form');
  if (!form) return;

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const term = document.getElementById('hero-search-input').value.trim();
    if (term) window.location.href = `productos?search=${encodeURIComponent(term)}`;
  });
}

document.addEventListener('DOMContentLoaded', () => {
  wireHomeSearch();
  loadHomeCategories();
  loadDeals();
  loadFeaturedProducts();
  loadFeaturedServices();
  loadWashServices();
});
