/**
 * Panel administrativo básico (Fase 6): pedidos e inventario. La seguridad
 * real está en la API (JWT + manage-orders/manage-inventory); aquí solo se
 * oculta el contenido si la petición falla con 401/403, para una UX clara.
 */
const GOOD_FINAL = ['ENTREGADO'];
const BAD_FINAL = ['CANCELADO', 'DEVUELTO'];
const MAX_CATALOG_IMAGES = 6; // debe coincidir con app.uploads.max_images_per_catalog_item (backend/config/app.php)

function statusBadgeClass(status) {
  if (GOOD_FINAL.includes(status)) return 'is-final-good';
  if (BAD_FINAL.includes(status)) return 'is-final-bad';
  return '';
}

/**
 * En vez de un selector con TODOS los estados posibles (confuso: ¿cuál sigue?),
 * un botón por cada estado al que la máquina de estados (sección 22,
 * OrderStatusTransitions) permite avanzar desde el actual — el backend ya
 * calcula esa lista (`order.next_statuses`) para no duplicar el grafo aquí.
 * Cancelar/devolver quedan en rojo y piden confirmación por ser irreversibles.
 */
function nextStatusActionsMarkup(nextStatuses) {
  if (nextStatuses.length === 0) {
    return '<span style="color:var(--gris-texto-tenue);font-size:0.8rem;">Sin más acciones</span>';
  }

  return `<div class="flex gap-8" style="flex-wrap:wrap;">${nextStatuses.map((status, index) => {
    const isDanger = BAD_FINAL.includes(status);
    const btnClass = isDanger ? 'btn-danger' : (index === 0 ? 'btn-primary' : 'btn-secondary');
    const confirmAttr = isDanger
      ? ` data-confirm="¿${helpers.orderActionLabel(status)}? Esta acción no se puede deshacer."`
      : '';

    return `<button class="btn ${btnClass}" data-action="advance-status" data-status="${status}"${confirmAttr}>${helpers.orderActionLabel(status)}</button>`;
  }).join('')}</div>`;
}

const ADMIN_SECTIONS = {
  dashboard: { title: 'Resumen', hint: 'Cómo va el negocio: ventas, pedidos y lo que necesita tu atención.' },
  orders: { title: 'Pedidos', hint: 'Cambia el estado de cada pedido con la acción que corresponde según en qué paso está.' },
  reservations: { title: 'Reservas', hint: 'Servicios agendados por los clientes, ordenados por fecha y hora.' },
  customers: { title: 'Clientes', hint: 'Cuentas de clientes registrados, con su historial de compras.' },
  inventory: { title: 'Inventario', hint: 'Stock disponible por producto y ajustes manuales con trazabilidad.' },
  services: { title: 'Servicios', hint: 'Crea, edita y elimina los servicios publicados en el catálogo.' },
  products: { title: 'Productos', hint: 'Crea, edita y elimina los productos publicados en el catálogo.' },
  categories: { title: 'Categorías', hint: 'Árbol de categorías del catálogo (ej. Motocicletas → Repuestos) — se pueden anidar unas dentro de otras.' },
  brands: { title: 'Marcas', hint: 'Fabricantes/marcas de los productos (ej. AKT, Bajaj) — lo más parecido a "proveedores" en un marketplace, donde no se le compra inventario a terceros para revender.' },
  suppliers: { title: 'Proveedores', hint: 'Agenda de a quién comprarle inventario — se puede vincular cada producto a su proveedor desde el formulario de Productos.' },
  'payment-methods': { title: 'Métodos de pago', hint: 'Actívalos o desactívalos sin tocar código — el checkout refleja el cambio al instante.' },
  coupons: { title: 'Cupones', hint: 'Códigos de descuento — porcentuales o fijos, con compra mínima, vigencia y límite de usos.' },
  settings: { title: 'Configuración', hint: 'Contenido general del sitio, guardado en la base de datos — hoy, términos y condiciones.' },
};

/** Barra lateral (fija en escritorio, cajón deslizable en pantallas angostas). */
function wireSidebar() {
  const sidebar = document.getElementById('admin-sidebar');
  const backdrop = document.getElementById('admin-drawer-backdrop');

  function openDrawer() {
    sidebar.classList.add('is-open');
    backdrop.classList.add('is-open');
  }
  function closeDrawer() {
    sidebar.classList.remove('is-open');
    backdrop.classList.remove('is-open');
  }

  document.getElementById('admin-drawer-toggle').addEventListener('click', openDrawer);
  document.getElementById('admin-sidebar-close').addEventListener('click', closeDrawer);
  backdrop.addEventListener('click', closeDrawer);

  document.querySelectorAll('.admin-nav-link').forEach((link) => {
    link.addEventListener('click', () => {
      document.querySelectorAll('.admin-nav-link').forEach((l) => l.classList.remove('is-active'));
      link.classList.add('is-active');

      const tab = link.dataset.tab;
      ['dashboard', 'orders', 'reservations', 'customers', 'inventory', 'services', 'products', 'categories', 'brands', 'suppliers', 'payment-methods', 'coupons', 'settings'].forEach((name) => {
        const section = document.getElementById(`admin-tab-${name}`);
        section.hidden = name !== tab;
        if (name === tab) {
          // Se reinicia la animación de entrada quitando y volviendo a poner la clase.
          section.classList.remove('admin-panel-enter');
          void section.offsetWidth; // fuerza reflow para que el navegador note el cambio
          section.classList.add('admin-panel-enter');
        }
      });

      document.getElementById('admin-section-title').textContent = ADMIN_SECTIONS[tab].title;
      document.getElementById('admin-section-hint').textContent = ADMIN_SECTIONS[tab].hint;

      closeDrawer();
    });
  });
}

/**
 * Resumen del negocio (sección 28): tarjetas de números + dos gráficas
 * (ingresos por día, pedidos por estado) + top de productos vendidos. Todo
 * viene calculado del backend con datos reales (DashboardController) —
 * aquí solo se pinta lo que ya llega listo.
 */
async function loadDashboard() {
  const errorBox = document.getElementById('dashboard-error');
  errorBox.textContent = '';

  try {
    const summary = await adminService.dashboardSummary();

    document.getElementById('dashboard-stat-cards').innerHTML = [
      statCardMarkup('💰', 'Ingresos (30 días)', helpers.formatCurrency(summary.revenue.last_30_days)),
      statCardMarkup('🧾', 'Pedidos totales', summary.revenue.orders_count),
      statCardMarkup('🎯', 'Ticket promedio', helpers.formatCurrency(summary.revenue.average_ticket)),
      statCardMarkup('📅', 'Reservas próximas', summary.upcoming_reservations_count),
      statCardMarkup('⚠️', 'Productos con stock bajo', summary.low_stock_count),
      statCardMarkup('🧑‍🤝‍🧑', 'Usuarios nuevos (30 días)', summary.new_users_last_30_days),
    ].join('');

    document.getElementById('dashboard-revenue-chart').innerHTML = barChartMarkup(
      summary.revenue_by_day,
      {
        valueKey: 'revenue',
        labelKey: 'date',
        formatValue: (v) => helpers.formatCurrency(v),
      }
    );
    // Las fechas completas solo se ven en el tooltip (<title>); en el eje X
    // se muestra únicamente día/mes para que no se amontonen las etiquetas.
    document.querySelectorAll('#dashboard-revenue-chart .chart-axis-label').forEach((label, i) => {
      const iso = summary.revenue_by_day[i * (summary.revenue_by_day.length > 10 ? 2 : 1)]?.date;
      if (iso) label.textContent = iso.slice(5).replace('-', '/');
    });

    const statusRows = Object.entries(summary.orders_by_status).map(([status, count]) => ({
      label: helpers.orderStatusLabel(status),
      value: count,
    }));
    document.getElementById('dashboard-status-chart').innerHTML = horizontalBarsMarkup(statusRows);

    const topProductsList = document.getElementById('dashboard-top-products');
    if (summary.top_products.length === 0) {
      topProductsList.innerHTML = '<p class="empty-state">Todavía no hay ventas registradas.</p>';
    } else {
      topProductsList.innerHTML = summary.top_products.map((product, index) => `
        <li>
          <span><span class="rank">#${index + 1}</span>${helpers.escapeHtml(product.name)}</span>
          <span>${product.units_sold} und. — ${helpers.formatCurrency(product.revenue)}</span>
        </li>
      `).join('');
    }
  } catch (error) {
    handleAdminError(error, errorBox);
  }
}

async function loadOrders() {
  const body = document.getElementById('orders-table-body');
  const errorBox = document.getElementById('orders-error');
  errorBox.textContent = '';

  const status = document.getElementById('orders-status-filter').value || undefined;

  try {
    const result = await adminService.orders({ status, per_page: 30 });

    if (result.data.length === 0) {
      body.innerHTML = '<tr><td colspan="6">No hay pedidos con este filtro.</td></tr>';
      return;
    }

    body.innerHTML = result.data.map((order) => `
      <tr data-order-number="${order.order_number}">
        <td>${helpers.escapeHtml(order.order_number)}</td>
        <td>${helpers.escapeHtml(order.customer_name)} ${helpers.escapeHtml(order.customer_last_name)}<br><span style="color:var(--gris-texto);">${helpers.escapeHtml(order.customer_email)}</span></td>
        <td>${helpers.formatCurrency(order.total)}</td>
        <td><span class="status-badge ${statusBadgeClass(order.status)}">${helpers.orderStatusLabel(order.status)}</span></td>
        <td>${new Date(order.created_at).toLocaleDateString('es-CO')}</td>
        <td>${nextStatusActionsMarkup(order.next_statuses || [])}</td>
      </tr>
    `).join('');

    body.querySelectorAll('[data-action="advance-status"]').forEach((button) => {
      button.addEventListener('click', async () => {
        const row = button.closest('tr');
        const orderNumber = row.dataset.orderNumber;
        const newStatus = button.dataset.status;

        if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) return;

        try {
          await adminService.updateOrderStatus(orderNumber, newStatus, null);
          helpers.toast(`Pedido ${orderNumber}: ${helpers.orderStatusLabel(newStatus)}.`, 'success');
          loadOrders();
        } catch (error) {
          helpers.toast(helpers.flattenErrors(error.fields) || error.message, 'error');
        }
      });
    });
  } catch (error) {
    handleAdminError(error, errorBox);
  }
}

/**
 * Reservas de servicios (sección 12): cada fila ES un pedido con un servicio
 * agendado — el cambio de estado reutiliza el mismo endpoint y los mismos
 * botones "siguiente paso" que la pestaña Pedidos (adminService.updateOrderStatus).
 */
async function loadReservations() {
  const body = document.getElementById('reservations-table-body');
  const errorBox = document.getElementById('reservations-error');
  errorBox.textContent = '';

  const filters = {
    date: document.getElementById('reservations-date-filter').value || undefined,
    upcoming_only: document.getElementById('reservations-upcoming-only').checked ? 1 : undefined,
    per_page: 50,
  };

  try {
    const result = await adminService.reservations(filters);

    if (result.data.length === 0) {
      body.innerHTML = '<tr><td colspan="7">No hay reservas con este filtro.</td></tr>';
      return;
    }

    body.innerHTML = result.data.map((reservation) => `
      <tr data-order-number="${reservation.order_number}">
        <td>${helpers.formatDateTime(reservation.scheduled_at)}</td>
        <td>${helpers.escapeHtml(reservation.service_name)}</td>
        <td>${helpers.escapeHtml(reservation.customer_name)} ${helpers.escapeHtml(reservation.customer_last_name)}<br><span style="color:var(--gris-texto);">${helpers.escapeHtml(reservation.customer_email)}</span></td>
        <td>${reservation.customer_phone ? helpers.escapeHtml(reservation.customer_phone) : '—'}</td>
        <td>${helpers.escapeHtml(reservation.order_number)}</td>
        <td><span class="status-badge ${statusBadgeClass(reservation.status)}">${helpers.orderStatusLabel(reservation.status)}</span></td>
        <td>${nextStatusActionsMarkup(reservation.next_statuses || [])}</td>
      </tr>
    `).join('');

    body.querySelectorAll('[data-action="advance-status"]').forEach((button) => {
      button.addEventListener('click', async () => {
        const row = button.closest('tr');
        const orderNumber = row.dataset.orderNumber;
        const newStatus = button.dataset.status;

        if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) return;

        try {
          await adminService.updateOrderStatus(orderNumber, newStatus, null);
          helpers.toast(`Reserva del pedido ${orderNumber}: ${helpers.orderStatusLabel(newStatus)}.`, 'success');
          loadReservations();
        } catch (error) {
          helpers.toast(helpers.flattenErrors(error.fields) || error.message, 'error');
        }
      });
    });
  } catch (error) {
    handleAdminError(error, errorBox);
  }
}

/**
 * Clientes registrados (sección 28: "dónde se ven los clientes"). Este
 * marketplace no maneja proveedores externos de inventario — cada producto
 * es propio de CASTAMOTO o de una tienda vendedora del marketplace; esa
 * gestión de tiendas/vendedores es la Fase 10 del prompt maestro, todavía
 * no construida (ver comentario en AdminCustomerController).
 */
const ADMIN_ROLES = ['cliente', 'vendedor', 'administrador', 'superadministrador'];

async function loadCustomers() {
  const body = document.getElementById('customers-table-body');
  const errorBox = document.getElementById('customers-error');
  errorBox.textContent = '';

  const search = document.getElementById('customers-search').value.trim() || undefined;
  const includeStaff = document.getElementById('customers-include-staff').checked ? 1 : undefined;

  try {
    const result = await adminService.customers({ search, include_staff: includeStaff, per_page: 50 });

    if (result.data.length === 0) {
      body.innerHTML = '<tr><td colspan="7">No hay clientes registrados todavía.</td></tr>';
      return;
    }

    body.innerHTML = result.data.map((customer) => `
      <tr data-user-id="${customer.id}">
        <td>${helpers.escapeHtml(customer.name)} ${helpers.escapeHtml(customer.last_name)}</td>
        <td>${helpers.escapeHtml(customer.email)}${customer.phone ? `<br><span style="color:var(--gris-texto);">${helpers.escapeHtml(customer.phone)}</span>` : ''}</td>
        <td>${new Date(customer.created_at).toLocaleDateString('es-CO')}</td>
        <td>${customer.email_verified_at ? '<span class="status-badge is-final-good">Sí</span>' : '<span class="status-badge is-final-bad">No</span>'}</td>
        <td>${customer.orders_count}</td>
        <td>${helpers.formatCurrency(customer.total_spent)}</td>
        <td>
          <div class="flex gap-8">
            <select data-role="role-select">
              ${ADMIN_ROLES.map((role) => `<option value="${role}" ${customer.roles.includes(role) ? 'selected' : ''}>${role}</option>`).join('')}
            </select>
            <button class="btn btn-secondary" data-action="save-role">Guardar</button>
          </div>
        </td>
      </tr>
    `).join('');

    body.querySelectorAll('[data-action="save-role"]').forEach((button) => {
      button.addEventListener('click', async () => {
        const row = button.closest('tr');
        const userId = row.dataset.userId;
        const role = row.querySelector('[data-role="role-select"]').value;

        try {
          await adminService.updateCustomerRole(userId, role);
          helpers.toast('Rol actualizado.', 'success');
          loadCustomers();
        } catch (error) {
          helpers.toast(helpers.flattenErrors(error.fields) || error.message, 'error');
        }
      });
    });
  } catch (error) {
    handleAdminError(error, errorBox);
  }
}

async function loadInventory() {
  const body = document.getElementById('inventory-table-body');
  const errorBox = document.getElementById('inventory-error');
  errorBox.textContent = '';

  const filters = {
    search: document.getElementById('inventory-search').value || undefined,
    low_stock: document.getElementById('inventory-low-stock').checked ? 1 : undefined,
    per_page: 50,
  };

  try {
    const result = await adminService.inventory(filters);

    if (result.data.length === 0) {
      body.innerHTML = '<tr><td colspan="8">No hay productos con este filtro.</td></tr>';
      return;
    }

    body.innerHTML = result.data.map((item) => `
      <tr data-product-id="${item.product_id}">
        <td>${helpers.escapeHtml(item.name)}</td>
        <td>${helpers.escapeHtml(item.sku)}</td>
        <td>${helpers.escapeHtml(item.category_name || '—')}</td>
        <td>${item.stock_current}</td>
        <td>${item.stock_reserved}</td>
        <td style="color:${item.stock_available <= item.min_stock ? 'var(--error)' : 'var(--exito)'};font-weight:700;">${item.stock_available}</td>
        <td>${item.min_stock}</td>
        <td>
          <div class="flex gap-8" style="flex-wrap:wrap;">
            <select data-role="adjust-type">
              <option value="in">Entrada</option>
              <option value="out">Salida</option>
              <option value="adjustment">Ajuste ±</option>
            </select>
            <input type="number" data-role="adjust-quantity" placeholder="Cant." style="width:70px;">
            <input type="text" data-role="adjust-reason" placeholder="Motivo" style="width:120px;">
            <button class="btn btn-secondary" data-action="adjust">Aplicar</button>
          </div>
        </td>
      </tr>
    `).join('');

    body.querySelectorAll('[data-action="adjust"]').forEach((button) => {
      button.addEventListener('click', async () => {
        const row = button.closest('tr');
        const productId = row.dataset.productId;
        const type = row.querySelector('[data-role="adjust-type"]').value;
        const quantity = Number(row.querySelector('[data-role="adjust-quantity"]').value);
        const reason = row.querySelector('[data-role="adjust-reason"]').value;

        try {
          await adminService.adjustInventory(productId, { type, quantity, reason });
          helpers.toast('Inventario actualizado.', 'success');
          loadInventory();
        } catch (error) {
          helpers.toast(helpers.flattenErrors(error.fields) || error.message, 'error');
        }
      });
    });
  } catch (error) {
    handleAdminError(error, errorBox);
  }
}

/**
 * Gestión de servicios (permiso manage-services). El backend ya tenía el CRUD
 * completo desde la Fase 3 (ServiceController) — esto es la primera interfaz
 * que lo usa; antes solo se podía cargar contenido por seeder.
 */
let serviceCategoriesFlat = [];

function statusLabelEs(status) {
  return { draft: 'Borrador', active: 'Activo', inactive: 'Inactivo' }[status] || status;
}

async function populateServiceCategorySelect() {
  const select = document.getElementById('service-category');
  try {
    const categories = await catalogService.categories();
    serviceCategoriesFlat = helpers.flattenCategories(categories);
    select.innerHTML = '<option value="">Sin categoría</option>' +
      serviceCategoriesFlat.map((cat) => `<option value="${cat.id}">${helpers.escapeHtml(cat.name)}</option>`).join('');
  } catch (error) {
    // El formulario sigue siendo usable sin categorías precargadas.
  }
}

async function loadServices() {
  const body = document.getElementById('services-table-body');
  const errorBox = document.getElementById('services-error');
  errorBox.textContent = '';

  try {
    const result = await catalogService.services({ per_page: 50, sort: 'newest' });

    if (result.data.length === 0) {
      body.innerHTML = '<tr><td colspan="6">Todavía no hay servicios creados.</td></tr>';
      return;
    }

    body.innerHTML = result.data.map((service) => `
      <tr data-service-id="${service.id}" data-service-slug="${service.slug}">
        <td>${helpers.escapeHtml(service.name)}</td>
        <td>${helpers.escapeHtml(service.category_name || '—')}</td>
        <td>${helpers.formatCurrency(service.price)}</td>
        <td>${service.location ? helpers.escapeHtml(service.location) : '—'}</td>
        <td><span class="status-badge ${service.status === 'active' ? 'is-final-good' : ''}">${statusLabelEs(service.status)}</span></td>
        <td>
          <div class="flex gap-8">
            <button class="btn btn-secondary" data-action="edit-service">Editar</button>
            <button class="btn btn-secondary" data-action="delete-service">Eliminar</button>
          </div>
        </td>
      </tr>
    `).join('');

    body.querySelectorAll('[data-action="edit-service"]').forEach((button) => {
      button.addEventListener('click', () => {
        const row = button.closest('tr');
        openServiceForm(row.dataset.serviceSlug);
      });
    });

    body.querySelectorAll('[data-action="delete-service"]').forEach((button) => {
      button.addEventListener('click', async () => {
        const row = button.closest('tr');
        if (!window.confirm('¿Eliminar este servicio? Esta acción no se puede deshacer.')) return;

        try {
          await catalogService.deleteService(row.dataset.serviceId);
          helpers.toast('Servicio eliminado.', 'success');
          loadServices();
        } catch (error) {
          helpers.toast(error.message, 'error');
        }
      });
    });
  } catch (error) {
    handleAdminError(error, errorBox);
  }
}

/** Actualiza el contador "(x/6)" y deshabilita el input al llegar al máximo
 * — el tope real lo aplica el backend (ver UploadServiceImageUseCase /
 * UploadProductImageUseCase), esto es solo para que la UI no invite a
 * seguir seleccionando fotos que el servidor va a rechazar. */
function updateImageCounter(prefix) {
  const count = document.getElementById(`${prefix}-images-list`).children.length;
  document.getElementById(`${prefix}-images-count`).textContent = `(${count}/${MAX_CATALOG_IMAGES})`;
  document.getElementById(`${prefix}-image-input`).disabled = count >= MAX_CATALOG_IMAGES;
}

function renderServiceImageThumb(serviceId, image) {
  const list = document.getElementById('service-images-list');
  const item = document.createElement('div');
  item.className = 'admin-image-list__item';
  item.dataset.imageId = image.id;
  item.innerHTML = `
    <img src="${helpers.mediaUrl('services', image.url)}" alt="Foto del servicio">
    <button type="button" class="admin-image-list__remove" aria-label="Eliminar foto">✕</button>
  `;

  item.querySelector('.admin-image-list__remove').addEventListener('click', async () => {
    try {
      await catalogService.deleteServiceImage(serviceId, image.id);
      item.remove();
      updateImageCounter('service');
    } catch (error) {
      helpers.toast(error.message, 'error');
    }
  });

  list.appendChild(item);
  updateImageCounter('service');
}

/**
 * El horario de atención propio (opcional, mismo patrón que shipping_cost)
 * solo tiene sentido si el servicio/producto realmente agenda fecha y hora
 * — se oculta cuando "Requiere agendar fecha y hora" está desmarcado, en vez
 * de dejarlo siempre visible y confundir con un campo que no hace nada.
 */
function toggleScheduleHoursVisibility(prefix) {
  const requiresScheduling = document.getElementById(`${prefix}-requires-scheduling`).checked;
  document.getElementById(`${prefix}-schedule-hours-row`).hidden = !requiresScheduling;
  document.getElementById(`${prefix}-schedule-hours-hint`).hidden = !requiresScheduling;
}

function resetServiceForm() {
  document.getElementById('service-form').reset();
  document.getElementById('service-id').value = '';
  document.getElementById('service-slug').value = '';
  document.getElementById('service-images-list').innerHTML = '';
  document.getElementById('service-images-section').hidden = true;
  document.getElementById('service-image-input').disabled = false;
  document.getElementById('service-images-count').textContent = '(0/6)';
  document.getElementById('service-modal-title').textContent = 'Nuevo servicio';
  document.getElementById('service-submit-btn').textContent = 'Crear servicio';
  document.getElementById('service-form-error').textContent = '';
  toggleScheduleHoursVisibility('service');
}

// Ubicación del local (CRA 20 #17-35, Manizales) — mismo valor que el
// "value" por defecto de #service-latitude/#service-longitude en admin.html
// (eso cubre "Nuevo servicio"). Acá cubre "Editar servicio": si el servicio
// todavía no tiene coordenada propia cargada, se le asigna esta por defecto
// en vez de dejarla vacía, para no depender de "Usar mi ubicación actual".
const DEFAULT_SERVICE_LATITUDE = '5.0689123';
const DEFAULT_SERVICE_LONGITUDE = '-75.5206348';

/** @param {string|null} slug - null para crear, slug del servicio para editar. */
async function openServiceForm(slug) {
  resetServiceForm();
  document.getElementById('service-modal-overlay').classList.add('is-open');

  if (!slug) return;

  try {
    const service = await catalogService.service(slug);

    document.getElementById('service-id').value = service.id;
    document.getElementById('service-slug').value = service.slug;
    document.getElementById('service-name').value = service.name;
    document.getElementById('service-name-en').value = service.name_en || '';
    document.getElementById('service-category').value = service.category_id || '';
    document.getElementById('service-price').value = service.price;
    document.getElementById('service-duration').value = service.duration_minutes || '';
    document.getElementById('service-shipping-cost').value = service.shipping_cost ?? '';
    document.getElementById('service-requires-scheduling').checked = Number(service.requires_scheduling ?? 1) !== 0;
    document.getElementById('service-schedule-hours-start').value = service.schedule_hours_start || '';
    document.getElementById('service-schedule-hours-end').value = service.schedule_hours_end || '';
    toggleScheduleHoursVisibility('service');
    document.getElementById('service-is-informational').checked = Number(service.is_informational ?? 0) !== 0;
    document.getElementById('service-location').value = service.location || '';
    document.getElementById('service-latitude').value = service.latitude ?? DEFAULT_SERVICE_LATITUDE;
    document.getElementById('service-longitude').value = service.longitude ?? DEFAULT_SERVICE_LONGITUDE;
    document.getElementById('service-description').value = service.description || '';
    document.getElementById('service-description-en').value = service.description_en || '';
    document.getElementById('service-cancellation').value = service.cancellation_policy || '';
    document.getElementById('service-warranty').value = service.warranty || '';
    document.getElementById('service-facebook').value = service.facebook_url || '';
    document.getElementById('service-status').value = service.status;

    document.getElementById('service-modal-title').textContent = 'Editar servicio';
    document.getElementById('service-submit-btn').textContent = 'Guardar cambios';

    const imagesSection = document.getElementById('service-images-section');
    imagesSection.hidden = false;
    (service.images || []).forEach((image) => renderServiceImageThumb(service.id, image));
  } catch (error) {
    helpers.toast(error.message, 'error');
    closeServiceForm();
  }
}

function closeServiceForm() {
  document.getElementById('service-modal-overlay').classList.remove('is-open');
}

function serviceFormPayload() {
  return {
    name: document.getElementById('service-name').value.trim(),
    name_en: document.getElementById('service-name-en').value.trim() || undefined,
    category_id: document.getElementById('service-category').value || undefined,
    price: document.getElementById('service-price').value,
    duration_minutes: document.getElementById('service-duration').value || undefined,
    shipping_cost: document.getElementById('service-shipping-cost').value !== '' ? document.getElementById('service-shipping-cost').value : undefined,
    requires_scheduling: document.getElementById('service-requires-scheduling').checked,
    schedule_hours_start: document.getElementById('service-schedule-hours-start').value || undefined,
    schedule_hours_end: document.getElementById('service-schedule-hours-end').value || undefined,
    is_informational: document.getElementById('service-is-informational').checked,
    location: document.getElementById('service-location').value.trim() || undefined,
    latitude: document.getElementById('service-latitude').value || undefined,
    longitude: document.getElementById('service-longitude').value || undefined,
    description: document.getElementById('service-description').value.trim() || undefined,
    description_en: document.getElementById('service-description-en').value.trim() || undefined,
    cancellation_policy: document.getElementById('service-cancellation').value.trim() || undefined,
    warranty: document.getElementById('service-warranty').value.trim() || undefined,
    facebook_url: document.getElementById('service-facebook').value.trim() || undefined,
    status: document.getElementById('service-status').value,
  };
}

function wireServiceManagement() {
  document.getElementById('new-service-btn').addEventListener('click', () => openServiceForm(null));
  document.getElementById('service-modal-close').addEventListener('click', closeServiceForm);
  document.getElementById('service-modal-overlay').addEventListener('click', (event) => {
    if (event.target === document.getElementById('service-modal-overlay')) closeServiceForm();
  });

  document.getElementById('service-requires-scheduling').addEventListener('change', () => toggleScheduleHoursVisibility('service'));

  document.getElementById('service-use-location-btn').addEventListener('click', () => {
    if (!navigator.geolocation) {
      helpers.toast('Tu navegador no soporta geolocalización.', 'error');
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (position) => {
        document.getElementById('service-latitude').value = position.coords.latitude.toFixed(7);
        document.getElementById('service-longitude').value = position.coords.longitude.toFixed(7);
        helpers.toast('Ubicación actual cargada.', 'success');
      },
      () => helpers.toast('No fue posible obtener tu ubicación (¿permiso denegado?).', 'error')
    );
  });

  document.getElementById('service-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = document.getElementById('service-form-error');
    errorBox.textContent = '';

    const id = document.getElementById('service-id').value;
    const payload = serviceFormPayload();

    try {
      let service;
      if (id) {
        service = await catalogService.updateService(id, payload);
        helpers.toast('Servicio actualizado.', 'success');
      } else {
        service = await catalogService.createService(payload);
        helpers.toast('Servicio creado. Ahora puedes agregarle fotos.', 'success');
      }

      // Tras crear, el formulario pasa a modo "edición" del servicio recién creado
      // (sin cerrar el modal) para que se puedan subir fotos de inmediato — las
      // imágenes solo se pueden asociar a un servicio que ya existe.
      document.getElementById('service-id').value = service.id;
      document.getElementById('service-slug').value = service.slug;
      document.getElementById('service-modal-title').textContent = 'Editar servicio';
      document.getElementById('service-submit-btn').textContent = 'Guardar cambios';
      document.getElementById('service-images-section').hidden = false;

      loadServices();
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    }
  });

  document.getElementById('service-image-input').addEventListener('change', async (event) => {
    const files = Array.from(event.target.files);
    const serviceId = document.getElementById('service-id').value;
    if (files.length === 0 || !serviceId) return;

    const remaining = MAX_CATALOG_IMAGES - document.getElementById('service-images-list').children.length;
    if (files.length > remaining) {
      helpers.toast(`Solo se subirán ${remaining} de las ${files.length} fotos seleccionadas (máximo ${MAX_CATALOG_IMAGES} por servicio).`, 'info');
    }

    // Se suben una por una (el endpoint acepta un archivo por petición) — el
    // orden importa para que el giro 360° siga la secuencia elegida.
    for (const file of files.slice(0, remaining)) {
      try {
        const image = await catalogService.uploadServiceImage(serviceId, file);
        renderServiceImageThumb(serviceId, image);
      } catch (error) {
        helpers.toast(helpers.flattenErrors(error.fields) || error.message, 'error');
        break;
      }
    }

    event.target.value = '';
  });
}

/**
 * Gestión de productos (permiso manage-products) — mismo patrón que la
 * gestión de servicios de arriba. El stock NO se edita aquí a propósito: se
 * ajusta desde la pestaña "Inventario" (`adminService.adjustInventory`), que
 * sí deja trazabilidad en `inventory_movements` (Fase 6) — permitir editarlo
 * también desde este formulario rompería esa única fuente de verdad.
 */
async function populateProductSelects() {
  const categorySelect = document.getElementById('product-category');
  const brandSelect = document.getElementById('product-brand');
  const supplierSelect = document.getElementById('product-supplier');

  try {
    const [categories, brands, suppliers] = await Promise.all([
      catalogService.categories(),
      catalogService.brands(),
      adminService.suppliers(),
    ]);
    const flatCategories = helpers.flattenCategories(categories);

    categorySelect.innerHTML = '<option value="">Selecciona una categoría</option>' +
      flatCategories.map((cat) => `<option value="${cat.id}">${helpers.escapeHtml(cat.name)}</option>`).join('');
    brandSelect.innerHTML = '<option value="">Sin marca</option>' +
      brands.map((brand) => `<option value="${brand.id}">${helpers.escapeHtml(brand.name)}</option>`).join('');
    supplierSelect.innerHTML = '<option value="">Sin proveedor</option>' +
      suppliers.map((supplier) => `<option value="${supplier.id}">${helpers.escapeHtml(supplier.name)}</option>`).join('');
  } catch (error) {
    // El formulario sigue siendo usable sin categorías/marcas/proveedores precargados.
  }
}

async function loadProductsAdmin() {
  const body = document.getElementById('products-table-body');
  const errorBox = document.getElementById('products-error');
  errorBox.textContent = '';

  try {
    const result = await catalogService.products({ per_page: 50 });

    if (result.data.length === 0) {
      body.innerHTML = '<tr><td colspan="7">Todavía no hay productos creados.</td></tr>';
      return;
    }

    body.innerHTML = result.data.map((product) => `
      <tr data-product-id="${product.id}" data-product-slug="${product.slug}">
        <td>${helpers.escapeHtml(product.name)}</td>
        <td>${helpers.escapeHtml(product.sku)}</td>
        <td>${helpers.escapeHtml(product.category_name || '—')}</td>
        <td>${helpers.formatCurrency(product.price)}</td>
        <td>${product.stock}</td>
        <td><span class="status-badge ${product.status === 'active' ? 'is-final-good' : ''}">${statusLabelEs(product.status)}</span></td>
        <td>
          <div class="flex gap-8">
            <button class="btn btn-secondary" data-action="edit-product">Editar</button>
            <button class="btn btn-secondary" data-action="delete-product">Eliminar</button>
          </div>
        </td>
      </tr>
    `).join('');

    body.querySelectorAll('[data-action="edit-product"]').forEach((button) => {
      button.addEventListener('click', () => {
        const row = button.closest('tr');
        openProductForm(row.dataset.productSlug);
      });
    });

    body.querySelectorAll('[data-action="delete-product"]').forEach((button) => {
      button.addEventListener('click', async () => {
        const row = button.closest('tr');
        if (!window.confirm('¿Eliminar este producto? Esta acción no se puede deshacer.')) return;

        try {
          await catalogService.deleteProduct(row.dataset.productId);
          helpers.toast('Producto eliminado.', 'success');
          loadProductsAdmin();
        } catch (error) {
          helpers.toast(error.message, 'error');
        }
      });
    });
  } catch (error) {
    handleAdminError(error, errorBox);
  }
}

function renderProductImageThumb(productId, image) {
  const list = document.getElementById('product-images-list');
  const item = document.createElement('div');
  item.className = 'admin-image-list__item';
  item.dataset.imageId = image.id;
  item.title = image.is_primary ? 'Foto principal' : 'Marcar como principal';
  item.innerHTML = `
    <img src="${helpers.mediaUrl('products', image.url)}" alt="Foto del producto" style="${image.is_primary ? 'outline:2px solid var(--amarillo);' : ''}">
    <button type="button" class="admin-image-list__remove" aria-label="Eliminar foto">✕</button>
  `;

  item.querySelector('img').addEventListener('click', async () => {
    try {
      await catalogService.setPrimaryProductImage(productId, image.id);
      document.querySelectorAll('#product-images-list img').forEach((img) => { img.style.outline = ''; });
      item.querySelector('img').style.outline = '2px solid var(--amarillo)';
    } catch (error) {
      helpers.toast(error.message, 'error');
    }
  });

  item.querySelector('.admin-image-list__remove').addEventListener('click', async () => {
    try {
      await catalogService.deleteProductImage(productId, image.id);
      item.remove();
      updateImageCounter('product');
    } catch (error) {
      helpers.toast(error.message, 'error');
    }
  });

  list.appendChild(item);
  updateImageCounter('product');
}

function resetProductForm() {
  document.getElementById('product-form').reset();
  document.getElementById('product-id').value = '';
  document.getElementById('product-slug').value = '';
  document.getElementById('product-images-list').innerHTML = '';
  document.getElementById('product-images-section').hidden = true;
  document.getElementById('product-image-input').disabled = false;
  document.getElementById('product-images-count').textContent = '(0/6)';
  document.getElementById('product-variants-list').innerHTML = '';
  // A diferencia de fotos (necesitan un id real para subirse), variantes y
  // atributos son texto simple — se pueden escribir ANTES de crear el
  // producto y se guardan junto con él (ver el submit de más abajo), en vez
  // de obligar a crear primero y recién ahí, en un segundo paso, enterarse
  // de que existía la opción de cargar tallas/colores.
  document.getElementById('product-variants-section').hidden = false;
  document.getElementById('product-attributes-list').innerHTML = '';
  document.getElementById('product-attributes-section').hidden = false;
  document.getElementById('product-stock').disabled = false;
  document.getElementById('product-stock-hint').hidden = true;
  document.getElementById('product-modal-title').textContent = 'Nuevo producto';
  document.getElementById('product-submit-btn').textContent = 'Crear producto';
  document.getElementById('product-form-error').textContent = '';
  toggleScheduleHoursVisibility('product');
}

/** @param {string|null} slug - null para crear, slug del producto para editar. */
async function openProductForm(slug) {
  resetProductForm();
  document.getElementById('product-modal-overlay').classList.add('is-open');

  if (!slug) return;

  try {
    const product = await catalogService.product(slug);

    document.getElementById('product-id').value = product.id;
    document.getElementById('product-slug').value = product.slug;
    document.getElementById('product-name').value = product.name;
    document.getElementById('product-name-en').value = product.name_en || '';
    document.getElementById('product-sku').value = product.sku;
    document.getElementById('product-category').value = product.category_id || '';
    document.getElementById('product-brand').value = product.brand_id || '';
    document.getElementById('product-supplier').value = product.supplier_id || '';
    document.getElementById('product-price').value = product.price;
    document.getElementById('product-previous-price').value = product.previous_price || '';
    document.getElementById('product-shipping-cost').value = product.shipping_cost ?? '';
    document.getElementById('product-stock').value = product.stock;
    document.getElementById('product-min-stock').value = product.min_stock || 0;
    document.getElementById('product-short-description').value = product.short_description || '';
    document.getElementById('product-short-description-en').value = product.short_description_en || '';
    document.getElementById('product-description').value = product.description || '';
    document.getElementById('product-requires-scheduling').checked = Number(product.requires_scheduling ?? 0) === 1;
    document.getElementById('product-schedule-hours-start').value = product.schedule_hours_start || '';
    document.getElementById('product-schedule-hours-end').value = product.schedule_hours_end || '';
    toggleScheduleHoursVisibility('product');
    document.getElementById('product-warranty').value = product.warranty || '';
    document.getElementById('product-status').value = product.status;

    // El stock ya existe en inventario: se bloquea aquí a propósito (ver comentario arriba).
    document.getElementById('product-stock').disabled = true;
    document.getElementById('product-stock-hint').hidden = false;

    document.getElementById('product-modal-title').textContent = 'Editar producto';
    document.getElementById('product-submit-btn').textContent = 'Guardar cambios';

    const imagesSection = document.getElementById('product-images-section');
    imagesSection.hidden = false;
    (product.images || []).forEach((image) => renderProductImageThumb(product.id, image));

    document.getElementById('product-variants-section').hidden = false;
    document.getElementById('product-attributes-section').hidden = false;
    renderProductVariantRows(product.variants);
    renderProductAttributeRows(product.attributes);
  } catch (error) {
    helpers.toast(error.message, 'error');
    closeProductForm();
  }
}

/**
 * Variantes de producto (talla, color, etc. — sección nueva) — cada una con
 * su propio SKU/precio/stock si aplica (product_variants, ya existía del
 * lado del backend — SyncProductVariantsUseCase — pero sin ninguna pantalla
 * en el admin para gestionarlas hasta ahora). A diferencia de las fotos
 * (necesitan un id real para subirse), se pueden cargar desde el alta misma:
 * el submit del formulario las manda junto con el producto nuevo (ver
 * wireProductManagement); el botón "Guardar variantes" de acá abajo es para
 * agregar/quitar después, editando un producto que ya existe.
 */
function renderProductVariantRows(variants) {
  const list = document.getElementById('product-variants-list');
  list.innerHTML = (variants || []).map((variant) => `
    <div class="form-row" data-variant-row style="align-items:flex-end;margin-bottom:8px;">
      <div class="form-group"><label>Tipo (opcional)</label><input class="form-control variant-type" value="${helpers.escapeHtml(variant.type || '')}" placeholder="Ej. Talla, Color"></div>
      <div class="form-group"><label>Nombre</label><input class="form-control variant-name" value="${helpers.escapeHtml(variant.name || '')}" placeholder="Ej. M, Rojo"></div>
      <div class="form-group"><label>SKU (opcional)</label><input class="form-control variant-sku" value="${helpers.escapeHtml(variant.sku || '')}"></div>
      <div class="form-group"><label>Ajuste de precio</label><input class="form-control variant-price" type="number" step="1" value="${variant.price_modifier ?? 0}"></div>
      <div class="form-group"><label>Stock</label><input class="form-control variant-stock" type="number" min="0" step="1" value="${variant.stock ?? 0}"></div>
      <button class="btn btn-secondary" type="button" data-action="remove-variant-row">✕</button>
    </div>
  `).join('');

  list.querySelectorAll('[data-action="remove-variant-row"]').forEach((btn) => {
    btn.addEventListener('click', () => btn.closest('[data-variant-row]').remove());
  });
}

function collectProductVariantRows() {
  return Array.from(document.querySelectorAll('#product-variants-list [data-variant-row]'))
    .map((row) => ({
      type: row.querySelector('.variant-type').value.trim() || undefined,
      name: row.querySelector('.variant-name').value.trim(),
      sku: row.querySelector('.variant-sku').value.trim() || undefined,
      price_modifier: row.querySelector('.variant-price').value || 0,
      stock: row.querySelector('.variant-stock').value || 0,
    }))
    .filter((variant) => variant.name);
}

/** Atributos/especificaciones (product_attributes) — mismo criterio que variantes: ya existía en el backend, faltaba la pantalla. */
function renderProductAttributeRows(attributes) {
  const list = document.getElementById('product-attributes-list');
  list.innerHTML = (attributes || []).map((attribute) => `
    <div class="form-row" data-attribute-row style="align-items:flex-end;margin-bottom:8px;">
      <div class="form-group"><label>Nombre</label><input class="form-control attribute-name" value="${helpers.escapeHtml(attribute.name || '')}" placeholder="Ej. Material"></div>
      <div class="form-group"><label>Valor</label><input class="form-control attribute-value" value="${helpers.escapeHtml(attribute.value || '')}" placeholder="Ej. Cuero"></div>
      <button class="btn btn-secondary" type="button" data-action="remove-attribute-row">✕</button>
    </div>
  `).join('');

  list.querySelectorAll('[data-action="remove-attribute-row"]').forEach((btn) => {
    btn.addEventListener('click', () => btn.closest('[data-attribute-row]').remove());
  });
}

function collectProductAttributeRows() {
  return Array.from(document.querySelectorAll('#product-attributes-list [data-attribute-row]'))
    .map((row) => ({
      name: row.querySelector('.attribute-name').value.trim(),
      value: row.querySelector('.attribute-value').value.trim(),
    }))
    .filter((attribute) => attribute.name && attribute.value);
}

function closeProductForm() {
  document.getElementById('product-modal-overlay').classList.remove('is-open');
}

function productFormPayload() {
  const isEditing = !!document.getElementById('product-id').value;

  return {
    name: document.getElementById('product-name').value.trim(),
    name_en: document.getElementById('product-name-en').value.trim() || undefined,
    // El campo está deshabilitado a propósito (nunca se escribe a mano): al
    // crear, vacío = el backend genera un SKU único automático (sección 10);
    // al editar, sigue mostrando (y reenviando sin cambios) el SKU real ya
    // asignado — ver UpdateProductUseCase.
    sku: document.getElementById('product-sku').value.trim() || undefined,
    category_id: document.getElementById('product-category').value,
    brand_id: document.getElementById('product-brand').value || undefined,
    supplier_id: document.getElementById('product-supplier').value || undefined,
    price: document.getElementById('product-price').value,
    previous_price: document.getElementById('product-previous-price').value || undefined,
    shipping_cost: document.getElementById('product-shipping-cost').value !== '' ? document.getElementById('product-shipping-cost').value : undefined,
    // Al editar, el input está deshabilitado (readonly) — su .value sigue siendo
    // el stock actual precargado, así que el campo "required" del backend se
    // cumple sin permitir que este formulario lo cambie de verdad.
    stock: document.getElementById('product-stock').value || (isEditing ? '0' : undefined),
    min_stock: document.getElementById('product-min-stock').value || undefined,
    requires_scheduling: document.getElementById('product-requires-scheduling').checked,
    schedule_hours_start: document.getElementById('product-schedule-hours-start').value || undefined,
    schedule_hours_end: document.getElementById('product-schedule-hours-end').value || undefined,
    short_description: document.getElementById('product-short-description').value.trim() || undefined,
    short_description_en: document.getElementById('product-short-description-en').value.trim() || undefined,
    description: document.getElementById('product-description').value.trim() || undefined,
    warranty: document.getElementById('product-warranty').value.trim() || undefined,
    status: document.getElementById('product-status').value,
  };
}

function wireProductManagement() {
  document.getElementById('new-product-btn').addEventListener('click', () => openProductForm(null));
  document.getElementById('product-modal-close').addEventListener('click', closeProductForm);
  document.getElementById('product-modal-overlay').addEventListener('click', (event) => {
    if (event.target === document.getElementById('product-modal-overlay')) closeProductForm();
  });

  document.getElementById('product-requires-scheduling').addEventListener('change', () => toggleScheduleHoursVisibility('product'));

  document.getElementById('product-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = document.getElementById('product-form-error');
    errorBox.textContent = '';

    const id = document.getElementById('product-id').value;
    const payload = productFormPayload();
    // Se leen ANTES de crear el producto: en el alta, variantes/atributos ya
    // se ven en el formulario (no hace falta guardar primero y volver a
    // entrar a editar), así que lo que haya cargado el vendedor acá se manda
    // junto con el producto nuevo.
    const variantRows = collectProductVariantRows();
    const attributeRows = collectProductAttributeRows();

    try {
      let product;
      if (id) {
        product = await catalogService.updateProduct(id, payload);
        helpers.toast('Producto actualizado.', 'success');
      } else {
        product = await catalogService.createProduct(payload);

        if (variantRows.length > 0) await catalogService.syncProductVariants(product.id, variantRows);
        if (attributeRows.length > 0) await catalogService.syncProductAttributes(product.id, attributeRows);

        helpers.toast('Producto creado. Ahora puedes agregarle fotos.', 'success');
      }

      // Mismo criterio que servicios: tras crear, el formulario pasa a modo
      // "edición" sin cerrarse, para poder subir fotos de inmediato.
      document.getElementById('product-id').value = product.id;
      document.getElementById('product-slug').value = product.slug;
      // Muestra el SKU real ya guardado — si se dejó vacío, este es el que
      // el backend generó automáticamente (sección 10).
      document.getElementById('product-sku').value = product.sku;
      document.getElementById('product-modal-title').textContent = 'Editar producto';
      document.getElementById('product-submit-btn').textContent = 'Guardar cambios';
      document.getElementById('product-stock').disabled = true;
      document.getElementById('product-stock-hint').hidden = false;
      document.getElementById('product-images-section').hidden = false;
      document.getElementById('product-variants-section').hidden = false;
      document.getElementById('product-attributes-section').hidden = false;

      loadProductsAdmin();
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    }
  });

  document.getElementById('product-image-input').addEventListener('change', async (event) => {
    const files = Array.from(event.target.files);
    const productId = document.getElementById('product-id').value;
    if (files.length === 0 || !productId) return;

    const remaining = MAX_CATALOG_IMAGES - document.getElementById('product-images-list').children.length;
    if (files.length > remaining) {
      helpers.toast(`Solo se subirán ${remaining} de las ${files.length} fotos seleccionadas (máximo ${MAX_CATALOG_IMAGES} por producto).`, 'info');
    }

    for (const file of files.slice(0, remaining)) {
      const isFirstImage = document.getElementById('product-images-list').children.length === 0;

      try {
        const image = await catalogService.uploadProductImage(productId, file, isFirstImage);
        renderProductImageThumb(productId, { ...image, is_primary: isFirstImage });
      } catch (error) {
        helpers.toast(helpers.flattenErrors(error.fields) || error.message, 'error');
        break;
      }
    }

    event.target.value = '';
  });

  document.getElementById('product-variant-add-btn').addEventListener('click', () => {
    const list = document.getElementById('product-variants-list');
    list.insertAdjacentHTML('beforeend', `
      <div class="form-row" data-variant-row style="align-items:flex-end;margin-bottom:8px;">
        <div class="form-group"><label>Tipo (opcional)</label><input class="form-control variant-type" placeholder="Ej. Talla, Color"></div>
        <div class="form-group"><label>Nombre</label><input class="form-control variant-name" placeholder="Ej. M, Rojo"></div>
        <div class="form-group"><label>SKU (opcional)</label><input class="form-control variant-sku"></div>
        <div class="form-group"><label>Ajuste de precio</label><input class="form-control variant-price" type="number" step="1" value="0"></div>
        <div class="form-group"><label>Stock</label><input class="form-control variant-stock" type="number" min="0" step="1" value="0"></div>
        <button class="btn btn-secondary" type="button" data-action="remove-variant-row">✕</button>
      </div>
    `);
    list.lastElementChild.querySelector('[data-action="remove-variant-row"]').addEventListener('click', (event) => {
      event.target.closest('[data-variant-row]').remove();
    });
  });

  document.getElementById('product-variants-save-btn').addEventListener('click', async () => {
    const productId = document.getElementById('product-id').value;
    const errorBox = document.getElementById('product-variants-error');
    errorBox.textContent = '';
    if (!productId) {
      errorBox.textContent = 'Primero creá el producto con el botón "Crear producto" — las variantes que ya cargaste se guardan junto con él.';
      return;
    }

    try {
      await catalogService.syncProductVariants(productId, collectProductVariantRows());
      helpers.toast('Variantes guardadas.', 'success');
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    }
  });

  document.getElementById('product-attribute-add-btn').addEventListener('click', () => {
    const list = document.getElementById('product-attributes-list');
    list.insertAdjacentHTML('beforeend', `
      <div class="form-row" data-attribute-row style="align-items:flex-end;margin-bottom:8px;">
        <div class="form-group"><label>Nombre</label><input class="form-control attribute-name" placeholder="Ej. Material"></div>
        <div class="form-group"><label>Valor</label><input class="form-control attribute-value" placeholder="Ej. Cuero"></div>
        <button class="btn btn-secondary" type="button" data-action="remove-attribute-row">✕</button>
      </div>
    `);
    list.lastElementChild.querySelector('[data-action="remove-attribute-row"]').addEventListener('click', (event) => {
      event.target.closest('[data-attribute-row]').remove();
    });
  });

  document.getElementById('product-attributes-save-btn').addEventListener('click', async () => {
    const productId = document.getElementById('product-id').value;
    const errorBox = document.getElementById('product-attributes-error');
    errorBox.textContent = '';
    if (!productId) {
      errorBox.textContent = 'Primero creá el producto con el botón "Crear producto" — los atributos que ya cargaste se guardan junto con él.';
      return;
    }

    try {
      await catalogService.syncProductAttributes(productId, collectProductAttributeRows());
      helpers.toast('Atributos guardados.', 'success');
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    }
  });
}

/**
 * Marcas (permiso manage-brands, ya existía desde la Fase 3 en el backend
 * sin ninguna interfaz). Es lo más parecido a "proveedores" que tiene un
 * marketplace como este: no se compra inventario a terceros para revenderlo,
 * cada producto ya viene con su marca/fabricante real (ej. AKT, Bajaj).
 */
/**
 * CRUD de categorías (permiso manage-categories) — hasta ahora solo se
 * gestionaban por seeder (backend/database/seeders/005_categories_seeder.php),
 * sin ninguna pantalla en el admin. El árbol viene anidado (children[], ver
 * catalogService.categories()); se aplana con su profundidad para mostrarlo
 * indentado en la tabla y en el selector de "categoría padre" del formulario.
 */
let categoriesFlatCache = [];

function flattenCategoriesWithDepth(tree, depth = 0) {
  return tree.reduce(
    (flat, node) => flat.concat([{ ...node, depth }], flattenCategoriesWithDepth(node.children || [], depth + 1)),
    []
  );
}

async function loadCategoriesAdmin() {
  const body = document.getElementById('categories-table-body');
  const errorBox = document.getElementById('categories-error');
  errorBox.textContent = '';

  try {
    const tree = await catalogService.categories();
    categoriesFlatCache = flattenCategoriesWithDepth(tree);

    if (categoriesFlatCache.length === 0) {
      body.innerHTML = '<tr><td colspan="4">Todavía no hay categorías creadas.</td></tr>';
      return;
    }

    body.innerHTML = categoriesFlatCache.map((cat) => `
      <tr data-category-id="${cat.id}">
        <td>${'— '.repeat(cat.depth)}${helpers.escapeHtml(cat.name)}</td>
        <td><span class="status-badge ${cat.status === 'active' ? 'is-final-good' : ''}">${cat.status === 'active' ? 'Activa' : 'Inactiva'}</span></td>
        <td>${cat.sort_order}</td>
        <td>
          <div class="flex gap-8">
            <button class="btn btn-secondary" data-action="edit-category">Editar</button>
            <button class="btn btn-secondary" data-action="delete-category">Eliminar</button>
          </div>
        </td>
      </tr>
    `).join('');

    body.querySelectorAll('[data-action="edit-category"]').forEach((button) => {
      button.addEventListener('click', () => {
        const category = categoriesFlatCache.find((c) => c.id === Number(button.closest('tr').dataset.categoryId));
        openCategoryForm(category);
      });
    });

    body.querySelectorAll('[data-action="delete-category"]').forEach((button) => {
      button.addEventListener('click', async () => {
        const id = button.closest('tr').dataset.categoryId;
        if (!window.confirm('¿Eliminar esta categoría? Sus subcategorías (si tiene) y los productos/servicios que la usan quedarán sin esa categoría asignada.')) return;

        try {
          await catalogService.deleteCategory(id);
          helpers.toast('Categoría eliminada.', 'success');
          loadCategoriesAdmin();
        } catch (error) {
          helpers.toast(error.message, 'error');
        }
      });
    });
  } catch (error) {
    handleAdminError(error, errorBox);
  }
}

/** Excluye la propia categoría (si se está editando) de su propio selector de padre —
 * ciclos más profundos (ej. elegir a un nieto como padre) los rechaza igual el backend
 * (CategoryRepositoryInterface::wouldCreateCycle), esto es solo la ayuda obvia en UI. */
function populateCategoryParentSelect(excludeId) {
  const select = document.getElementById('category-parent');
  const options = categoriesFlatCache.filter((c) => c.id !== excludeId);
  select.innerHTML = '<option value="">Sin categoría padre (raíz)</option>' +
    options.map((c) => `<option value="${c.id}">${'— '.repeat(c.depth)}${helpers.escapeHtml(c.name)}</option>`).join('');
}

/** Ícono actual (imagen real subida) o el genérico de siempre si todavía no tiene una. */
function renderCategoryImagePreview(category) {
  const img = document.getElementById('category-image-preview');
  const placeholder = document.getElementById('category-image-placeholder');

  if (category && category.image) {
    img.src = helpers.mediaUrl('categories', category.image);
    img.hidden = false;
    placeholder.hidden = true;
  } else {
    img.hidden = true;
    placeholder.hidden = false;
  }
}

function openCategoryForm(category) {
  document.getElementById('category-form').reset();
  document.getElementById('category-form-error').textContent = '';
  document.getElementById('category-id').value = category ? category.id : '';
  document.getElementById('category-name').value = category ? category.name : '';
  populateCategoryParentSelect(category ? category.id : null);
  document.getElementById('category-parent').value = category && category.parent_id ? category.parent_id : '';
  document.getElementById('category-status').value = category ? category.status : 'active';
  document.getElementById('category-sort-order').value = category ? category.sort_order : 0;
  document.getElementById('category-modal-title').textContent = category ? 'Editar categoría' : 'Nueva categoría';
  document.getElementById('category-submit-btn').textContent = category ? 'Guardar cambios' : 'Crear categoría';
  renderCategoryImagePreview(category);

  document.getElementById('category-modal-overlay').classList.add('is-open');
}

function wireCategoryManagement() {
  document.getElementById('new-category-btn').addEventListener('click', () => openCategoryForm(null));
  document.getElementById('category-modal-close').addEventListener('click', () => {
    document.getElementById('category-modal-overlay').classList.remove('is-open');
  });
  document.getElementById('category-modal-overlay').addEventListener('click', (event) => {
    if (event.target === document.getElementById('category-modal-overlay')) {
      document.getElementById('category-modal-overlay').classList.remove('is-open');
    }
  });

  document.getElementById('category-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = document.getElementById('category-form-error');
    errorBox.textContent = '';

    const id = document.getElementById('category-id').value;
    const payload = {
      name: document.getElementById('category-name').value.trim(),
      parent_id: document.getElementById('category-parent').value || undefined,
      status: document.getElementById('category-status').value,
      sort_order: document.getElementById('category-sort-order').value || 0,
    };

    try {
      let category;
      if (id) {
        category = await catalogService.updateCategory(id, payload);
      } else {
        category = await catalogService.createCategory(payload);
      }

      // El ícono se sube en el mismo paso que el resto del formulario (crear
      // o editar) — solo hace falta el id de la categoría para subirlo, que
      // recién existe DESPUÉS de crearla, por eso va acá y no antes.
      const imageFile = document.getElementById('category-image-input').files[0];
      if (imageFile) {
        const { image } = await catalogService.uploadCategoryImage(category.id, imageFile);
        category.image = image;
      }

      helpers.toast(id ? 'Categoría actualizada.' : 'Categoría creada.', 'success');

      // Pasa a modo "edición" de la categoría recién creada (sin cerrar el
      // modal) — mismo patrón que servicios/productos.
      document.getElementById('category-id').value = category.id;
      document.getElementById('category-modal-title').textContent = 'Editar categoría';
      document.getElementById('category-submit-btn').textContent = 'Guardar cambios';
      document.getElementById('category-image-input').value = '';
      renderCategoryImagePreview(category);

      loadCategoriesAdmin();
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    }
  });
}

async function loadBrands() {
  const body = document.getElementById('brands-table-body');
  const errorBox = document.getElementById('brands-error');
  errorBox.textContent = '';

  try {
    const brands = await catalogService.brands();

    if (brands.length === 0) {
      body.innerHTML = '<tr><td colspan="4">Todavía no hay marcas creadas.</td></tr>';
      return;
    }

    body.innerHTML = brands.map((brand) => `
      <tr data-brand-id="${brand.id}">
        <td>${helpers.escapeHtml(brand.name)}</td>
        <td>${brand.logo ? `<img src="${helpers.mediaUrl('brands', brand.logo)}" alt="" style="height:28px;width:auto;border-radius:4px;">` : '—'}</td>
        <td><span class="status-badge ${brand.status === 'active' ? 'is-final-good' : ''}">${brand.status === 'active' ? 'Activa' : 'Inactiva'}</span></td>
        <td>
          <div class="flex gap-8">
            <button class="btn btn-secondary" data-action="edit-brand">Editar</button>
            <button class="btn btn-secondary" data-action="delete-brand">Eliminar</button>
          </div>
        </td>
      </tr>
    `).join('');

    body.querySelectorAll('[data-action="edit-brand"]').forEach((button) => {
      button.addEventListener('click', () => {
        const brand = brands.find((b) => b.id === Number(button.closest('tr').dataset.brandId));
        openBrandForm(brand);
      });
    });

    body.querySelectorAll('[data-action="delete-brand"]').forEach((button) => {
      button.addEventListener('click', async () => {
        const id = button.closest('tr').dataset.brandId;
        if (!window.confirm('¿Eliminar esta marca? Los productos que la usan quedarán sin marca asignada.')) return;

        try {
          await catalogService.deleteBrand(id);
          helpers.toast('Marca eliminada.', 'success');
          loadBrands();
        } catch (error) {
          helpers.toast(error.message, 'error');
        }
      });
    });
  } catch (error) {
    handleAdminError(error, errorBox);
  }
}

/** Logo real subido, o el ícono genérico si la marca todavía no tiene uno. */
function renderBrandLogoPreview(brand) {
  const img = document.getElementById('brand-logo-preview');
  const placeholder = document.getElementById('brand-logo-placeholder');

  if (brand && brand.logo) {
    img.src = helpers.mediaUrl('brands', brand.logo);
    img.hidden = false;
    placeholder.hidden = true;
  } else {
    img.hidden = true;
    placeholder.hidden = false;
  }
}

function openBrandForm(brand) {
  document.getElementById('brand-form').reset();
  document.getElementById('brand-form-error').textContent = '';
  document.getElementById('brand-id').value = brand ? brand.id : '';
  document.getElementById('brand-name').value = brand ? brand.name : '';
  document.getElementById('brand-status').value = brand ? brand.status : 'active';
  document.getElementById('brand-modal-title').textContent = brand ? 'Editar marca' : 'Nueva marca';
  document.getElementById('brand-submit-btn').textContent = brand ? 'Guardar cambios' : 'Crear marca';
  renderBrandLogoPreview(brand);
  document.getElementById('brand-modal-overlay').classList.add('is-open');
}

function wireBrandManagement() {
  document.getElementById('new-brand-btn').addEventListener('click', () => openBrandForm(null));
  document.getElementById('brand-modal-close').addEventListener('click', () => {
    document.getElementById('brand-modal-overlay').classList.remove('is-open');
  });
  document.getElementById('brand-modal-overlay').addEventListener('click', (event) => {
    if (event.target === document.getElementById('brand-modal-overlay')) {
      document.getElementById('brand-modal-overlay').classList.remove('is-open');
    }
  });

  document.getElementById('brand-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = document.getElementById('brand-form-error');
    errorBox.textContent = '';

    const id = document.getElementById('brand-id').value;
    const payload = {
      name: document.getElementById('brand-name').value.trim(),
      status: document.getElementById('brand-status').value,
    };

    try {
      let brand;
      if (id) {
        brand = await catalogService.updateBrand(id, payload);
      } else {
        brand = await catalogService.createBrand(payload);
      }

      // El logo se sube en el mismo paso que el resto del formulario — solo
      // hace falta el id de la marca, que recién existe después de crearla.
      const logoFile = document.getElementById('brand-logo-input').files[0];
      if (logoFile) {
        const { logo } = await catalogService.uploadBrandLogo(brand.id, logoFile);
        brand.logo = logo;
      }

      helpers.toast(id ? 'Marca actualizada.' : 'Marca creada.', 'success');

      // Pasa a modo "edición" de la marca recién creada (sin cerrar el modal)
      // — mismo patrón que categorías/servicios/productos.
      document.getElementById('brand-id').value = brand.id;
      document.getElementById('brand-modal-title').textContent = 'Editar marca';
      document.getElementById('brand-submit-btn').textContent = 'Guardar cambios';
      document.getElementById('brand-logo-input').value = '';
      renderBrandLogoPreview(brand);

      loadBrands();
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    }
  });
}

/**
 * Proveedores (permiso manage-suppliers): a diferencia de marcas, es una
 * agenda interna (a quién comprarle) — no es público, no aparece en el
 * catálogo del cliente. Cada producto puede vincularse a uno (ver
 * populateProductSelects/productFormPayload más arriba).
 */
async function loadSuppliers() {
  const body = document.getElementById('suppliers-table-body');
  const errorBox = document.getElementById('suppliers-error');
  errorBox.textContent = '';

  try {
    const suppliers = await adminService.suppliers({ include_inactive: 1 });

    if (suppliers.length === 0) {
      body.innerHTML = '<tr><td colspan="5">Todavía no hay proveedores creados.</td></tr>';
      return;
    }

    body.innerHTML = suppliers.map((supplier) => `
      <tr data-supplier-id="${supplier.id}">
        <td>${helpers.escapeHtml(supplier.name)}</td>
        <td>${helpers.escapeHtml(supplier.contact_name || '—')}</td>
        <td>${helpers.escapeHtml(supplier.phone || '—')}</td>
        <td><span class="status-badge ${supplier.status === 'active' ? 'is-final-good' : ''}">${supplier.status === 'active' ? 'Activo' : 'Inactivo'}</span></td>
        <td>
          <div class="flex gap-8">
            <button class="btn btn-secondary" data-action="edit-supplier">Editar</button>
            <button class="btn btn-secondary" data-action="delete-supplier">Eliminar</button>
          </div>
        </td>
      </tr>
    `).join('');

    body.querySelectorAll('[data-action="edit-supplier"]').forEach((button) => {
      button.addEventListener('click', () => {
        const supplier = suppliers.find((s) => s.id === Number(button.closest('tr').dataset.supplierId));
        openSupplierForm(supplier);
      });
    });

    body.querySelectorAll('[data-action="delete-supplier"]').forEach((button) => {
      button.addEventListener('click', async () => {
        const id = button.closest('tr').dataset.supplierId;
        if (!window.confirm('¿Eliminar este proveedor? Los productos vinculados quedarán sin proveedor asignado.')) return;

        try {
          await adminService.deleteSupplier(id);
          helpers.toast('Proveedor eliminado.', 'success');
          loadSuppliers();
        } catch (error) {
          helpers.toast(error.message, 'error');
        }
      });
    });
  } catch (error) {
    handleAdminError(error, errorBox);
  }
}

function openSupplierForm(supplier) {
  document.getElementById('supplier-form').reset();
  document.getElementById('supplier-form-error').textContent = '';
  document.getElementById('supplier-id').value = supplier ? supplier.id : '';
  document.getElementById('supplier-name').value = supplier ? supplier.name : '';
  document.getElementById('supplier-contact-name').value = supplier ? (supplier.contact_name || '') : '';
  document.getElementById('supplier-phone').value = supplier ? (supplier.phone || '') : '';
  document.getElementById('supplier-email').value = supplier ? (supplier.email || '') : '';
  document.getElementById('supplier-tax-id').value = supplier ? (supplier.tax_id || '') : '';
  document.getElementById('supplier-address').value = supplier ? (supplier.address || '') : '';
  document.getElementById('supplier-notes').value = supplier ? (supplier.notes || '') : '';
  document.getElementById('supplier-status').value = supplier ? supplier.status : 'active';
  document.getElementById('supplier-modal-title').textContent = supplier ? 'Editar proveedor' : 'Nuevo proveedor';
  document.getElementById('supplier-submit-btn').textContent = supplier ? 'Guardar cambios' : 'Crear proveedor';
  document.getElementById('supplier-modal-overlay').classList.add('is-open');
}

function wireSupplierManagement() {
  document.getElementById('new-supplier-btn').addEventListener('click', () => openSupplierForm(null));
  document.getElementById('supplier-modal-close').addEventListener('click', () => {
    document.getElementById('supplier-modal-overlay').classList.remove('is-open');
  });
  document.getElementById('supplier-modal-overlay').addEventListener('click', (event) => {
    if (event.target === document.getElementById('supplier-modal-overlay')) {
      document.getElementById('supplier-modal-overlay').classList.remove('is-open');
    }
  });

  document.getElementById('supplier-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = document.getElementById('supplier-form-error');
    errorBox.textContent = '';

    const id = document.getElementById('supplier-id').value;
    const payload = {
      name: document.getElementById('supplier-name').value.trim(),
      contact_name: document.getElementById('supplier-contact-name').value.trim() || undefined,
      phone: document.getElementById('supplier-phone').value.trim() || undefined,
      email: document.getElementById('supplier-email').value.trim() || undefined,
      tax_id: document.getElementById('supplier-tax-id').value.trim() || undefined,
      address: document.getElementById('supplier-address').value.trim() || undefined,
      notes: document.getElementById('supplier-notes').value.trim() || undefined,
      status: document.getElementById('supplier-status').value,
    };

    try {
      if (id) {
        await adminService.updateSupplier(id, payload);
        helpers.toast('Proveedor actualizado.', 'success');
      } else {
        await adminService.createSupplier(payload);
        helpers.toast('Proveedor creado.', 'success');
      }
      document.getElementById('supplier-modal-overlay').classList.remove('is-open');
      loadSuppliers();
      populateProductSelects();
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    }
  });
}

/**
 * Configuración de métodos de pago (sección 21, "MUY IMPORTANTE": activar/
 * desactivar sin escribir código). El checkbox de "Activo" guarda apenas se
 * toca — no hace falta un botón "Guardar" aparte para lo más común, que es
 * prender o apagar un método. "Configurar" abre un formulario aparte solo
 * para los campos de cada pasarela (nunca número de tarjeta/CVV, sección 20).
 */
const PAYMENT_CONFIG_FIELDS = {
  bank_transfer: [
    { key: 'bank_name', label: 'Banco', placeholder: 'Ej. Bancolombia' },
    { key: 'account_type', label: 'Tipo de cuenta', placeholder: 'Ahorros / Corriente' },
    { key: 'account_number', label: 'Número de cuenta', placeholder: '123-456789-00' },
    { key: 'account_holder', label: 'Titular de la cuenta', placeholder: 'CASTAMOTO SAS' },
  ],
  // Tarjeta/Wompi/Mercado Pago/PayU/Stripe: mismos dos campos genéricos —
  // ninguna tiene todavía una integración real conectada (ver
  // ExternalPaymentGateway en el backend), así que alcanza con guardar las
  // llaves para cuando se conecte de verdad.
  _external: [
    { key: 'public_key', label: 'Llave pública', placeholder: 'pk_...' },
    { key: 'api_key', label: 'Llave privada / API key', placeholder: 'sk_...', type: 'password' },
  ],
};

function paymentConfigFieldsFor(code) {
  return PAYMENT_CONFIG_FIELDS[code] || PAYMENT_CONFIG_FIELDS._external;
}

async function loadPaymentMethods() {
  const body = document.getElementById('payment-methods-table-body');
  const errorBox = document.getElementById('payment-methods-error');
  errorBox.textContent = '';

  try {
    const methods = await adminService.paymentMethods();

    body.innerHTML = methods.map((method) => `
      <tr data-method-id="${method.id}" data-method-code="${method.code}">
        <td>${helpers.escapeHtml(method.name)}</td>
        <td><code>${helpers.escapeHtml(method.code)}</code></td>
        <td>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="checkbox" data-role="toggle-enabled" ${method.is_enabled ? 'checked' : ''} style="width:auto;">
            ${method.is_enabled ? 'Sí' : 'No'}
          </label>
        </td>
        <td style="color:var(--gris-texto);font-size:0.8rem;">
          ${method.code === 'cash' ? 'No requiere' : (method.config ? '✓ Configurado' : 'Sin configurar')}
        </td>
        <td>${method.code === 'cash' ? '' : '<button class="btn btn-secondary" data-action="configure">Configurar</button>'}</td>
      </tr>
    `).join('');

    body.querySelectorAll('[data-role="toggle-enabled"]').forEach((checkbox) => {
      checkbox.addEventListener('change', async () => {
        const row = checkbox.closest('tr');
        const id = row.dataset.methodId;

        try {
          await adminService.updatePaymentMethod(id, { is_enabled: checkbox.checked });
          helpers.toast(`${row.querySelector('td').textContent}: ${checkbox.checked ? 'activado' : 'desactivado'}.`, 'success');
          loadPaymentMethods();
        } catch (error) {
          checkbox.checked = !checkbox.checked; // revierte el check si el backend lo rechazó
          helpers.toast(helpers.flattenErrors(error.fields) || error.message, 'error');
        }
      });
    });

    body.querySelectorAll('[data-action="configure"]').forEach((button) => {
      button.addEventListener('click', () => {
        const row = button.closest('tr');
        const method = methods.find((m) => m.id === Number(row.dataset.methodId));
        openPaymentConfigForm(method);
      });
    });
  } catch (error) {
    handleAdminError(error, errorBox);
  }
}

function openPaymentConfigForm(method) {
  document.getElementById('payment-config-id').value = method.id;
  document.getElementById('payment-config-modal-title').textContent = `Configurar ${method.name}`;
  document.getElementById('payment-config-form-error').textContent = '';

  const fields = paymentConfigFieldsFor(method.code);
  const config = method.config || {};

  document.getElementById('payment-config-fields').innerHTML = fields.map((field) => `
    <div class="form-group">
      <label for="payment-config-${field.key}">${field.label}</label>
      <input class="form-control" type="${field.type || 'text'}" id="payment-config-${field.key}"
        placeholder="${field.placeholder}" value="${helpers.escapeHtml(config[field.key] || '')}">
    </div>
  `).join('');

  document.getElementById('payment-config-modal-overlay').classList.add('is-open');
}

function wirePaymentMethodManagement() {
  document.getElementById('payment-config-modal-close').addEventListener('click', () => {
    document.getElementById('payment-config-modal-overlay').classList.remove('is-open');
  });
  document.getElementById('payment-config-modal-overlay').addEventListener('click', (event) => {
    if (event.target === document.getElementById('payment-config-modal-overlay')) {
      document.getElementById('payment-config-modal-overlay').classList.remove('is-open');
    }
  });

  document.getElementById('payment-config-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = document.getElementById('payment-config-form-error');
    errorBox.textContent = '';

    const id = document.getElementById('payment-config-id').value;
    const row = document.querySelector(`[data-method-id="${id}"]`);
    const fields = paymentConfigFieldsFor(row.dataset.methodCode);

    const config = {};
    fields.forEach((field) => {
      const value = document.getElementById(`payment-config-${field.key}`).value.trim();
      if (value) config[field.key] = value;
    });

    try {
      await adminService.updatePaymentMethod(id, {
        is_enabled: row.querySelector('[data-role="toggle-enabled"]').checked,
        config,
      });
      helpers.toast('Configuración guardada.', 'success');
      document.getElementById('payment-config-modal-overlay').classList.remove('is-open');
      loadPaymentMethods();
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    }
  });
}

/** Cupones (sección 30, permiso manage-coupons). */
async function loadCoupons() {
  const body = document.getElementById('coupons-table-body');
  const errorBox = document.getElementById('coupons-error');
  errorBox.textContent = '';

  try {
    const result = await adminService.coupons({ per_page: 50 });

    if (result.data.length === 0) {
      body.innerHTML = '<tr><td colspan="8">Todavía no hay cupones creados.</td></tr>';
      return;
    }

    body.innerHTML = result.data.map((coupon) => {
      const valueLabel = coupon.type === 'percentage' ? `${Number(coupon.value)}%` : helpers.formatCurrency(coupon.value);
      const usage = coupon.usage_limit ? `${coupon.used_count} / ${coupon.usage_limit}` : `${coupon.used_count} (sin límite)`;
      const vigencia = [coupon.starts_at, coupon.ends_at].some(Boolean)
        ? `${coupon.starts_at ? new Date(coupon.starts_at).toLocaleDateString('es-CO') : '—'} a ${coupon.ends_at ? new Date(coupon.ends_at).toLocaleDateString('es-CO') : '—'}`
        : 'Sin límite';

      return `
        <tr data-coupon-id="${coupon.id}">
          <td><code>${helpers.escapeHtml(coupon.code)}</code></td>
          <td>${coupon.type === 'percentage' ? 'Porcentaje' : 'Monto fijo'}</td>
          <td>${valueLabel}</td>
          <td>${coupon.min_purchase ? helpers.formatCurrency(coupon.min_purchase) : '—'}</td>
          <td>${usage}</td>
          <td style="font-size:0.78rem;">${vigencia}</td>
          <td><span class="status-badge ${coupon.status === 'active' ? 'is-final-good' : ''}">${coupon.status === 'active' ? 'Activo' : 'Inactivo'}</span></td>
          <td>
            <div class="flex gap-8">
              <button class="btn btn-secondary" data-action="edit-coupon">Editar</button>
              <button class="btn btn-secondary" data-action="delete-coupon">Eliminar</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    body.querySelectorAll('[data-action="edit-coupon"]').forEach((button) => {
      button.addEventListener('click', () => {
        const coupon = result.data.find((c) => c.id === Number(button.closest('tr').dataset.couponId));
        openCouponForm(coupon);
      });
    });

    body.querySelectorAll('[data-action="delete-coupon"]').forEach((button) => {
      button.addEventListener('click', async () => {
        const id = button.closest('tr').dataset.couponId;
        if (!window.confirm('¿Eliminar este cupón? Esta acción no se puede deshacer.')) return;

        try {
          await adminService.deleteCoupon(id);
          helpers.toast('Cupón eliminado.', 'success');
          loadCoupons();
        } catch (error) {
          helpers.toast(error.message, 'error');
        }
      });
    });
  } catch (error) {
    handleAdminError(error, errorBox);
  }
}

function openCouponForm(coupon) {
  document.getElementById('coupon-form-admin').reset();
  document.getElementById('coupon-form-error').textContent = '';
  document.getElementById('coupon-id').value = coupon ? coupon.id : '';
  document.getElementById('coupon-code').value = coupon ? coupon.code : '';
  document.getElementById('coupon-type').value = coupon ? coupon.type : 'percentage';
  document.getElementById('coupon-value').value = coupon ? coupon.value : '';
  document.getElementById('coupon-min-purchase').value = coupon && coupon.min_purchase ? coupon.min_purchase : '';
  document.getElementById('coupon-usage-limit').value = coupon && coupon.usage_limit ? coupon.usage_limit : '';
  document.getElementById('coupon-starts-at').value = coupon && coupon.starts_at ? coupon.starts_at.slice(0, 10) : '';
  document.getElementById('coupon-ends-at').value = coupon && coupon.ends_at ? coupon.ends_at.slice(0, 10) : '';
  document.getElementById('coupon-status').value = coupon ? coupon.status : 'active';
  document.getElementById('coupon-modal-title').textContent = coupon ? 'Editar cupón' : 'Nuevo cupón';
  document.getElementById('coupon-submit-btn').textContent = coupon ? 'Guardar cambios' : 'Crear cupón';
  document.getElementById('coupon-modal-overlay').classList.add('is-open');
}

function wireCouponManagement() {
  document.getElementById('new-coupon-btn').addEventListener('click', () => openCouponForm(null));
  document.getElementById('coupon-modal-close').addEventListener('click', () => {
    document.getElementById('coupon-modal-overlay').classList.remove('is-open');
  });
  document.getElementById('coupon-modal-overlay').addEventListener('click', (event) => {
    if (event.target === document.getElementById('coupon-modal-overlay')) {
      document.getElementById('coupon-modal-overlay').classList.remove('is-open');
    }
  });

  document.getElementById('coupon-form-admin').addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = document.getElementById('coupon-form-error');
    errorBox.textContent = '';

    const id = document.getElementById('coupon-id').value;
    const payload = {
      code: document.getElementById('coupon-code').value.trim(),
      type: document.getElementById('coupon-type').value,
      value: document.getElementById('coupon-value').value,
      min_purchase: document.getElementById('coupon-min-purchase').value || undefined,
      usage_limit: document.getElementById('coupon-usage-limit').value || undefined,
      starts_at: document.getElementById('coupon-starts-at').value || undefined,
      ends_at: document.getElementById('coupon-ends-at').value || undefined,
      status: document.getElementById('coupon-status').value,
    };

    try {
      if (id) {
        await adminService.updateCoupon(id, payload);
        helpers.toast('Cupón actualizado.', 'success');
      } else {
        await adminService.createCoupon(payload);
        helpers.toast('Cupón creado.', 'success');
      }
      document.getElementById('coupon-modal-overlay').classList.remove('is-open');
      loadCoupons();
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    }
  });
}

function handleAdminError(error, errorBox) {
  if (error.status === 401 || error.status === 403) {
    errorBox.textContent = 'No tienes permisos para ver esta sección.';
    return;
  }
  errorBox.textContent = error.message;
}

/**
 * Traducción automática ES → EN (sección nueva): al salir del campo en
 * español, si el campo "_en" correspondiente todavía está vacío, se
 * completa solo con la traducción (adminService.translate, mismo proveedor
 * de IA del asistente — AI_PROVIDER/AI_API_KEY en backend/.env). Nunca pisa
 * una traducción que el admin ya haya escrito a mano — solo actúa si el
 * campo destino sigue vacío. Si no hay proveedor de IA configurado, o la
 * llamada falla, no bloquea el formulario: el campo se sigue completando a
 * mano, como siempre ("vacío = fallback al texto en español" en el sitio).
 */
function wireAutoTranslate(sourceId, targetId) {
  const source = document.getElementById(sourceId);
  const target = document.getElementById(targetId);
  if (!source || !target) return;

  source.addEventListener('blur', async () => {
    const text = source.value.trim();
    if (!text || target.value.trim()) return;

    target.disabled = true;
    try {
      const { translated } = await adminService.translate(text);
      if (translated && !target.value.trim()) target.value = translated;
    } catch (error) {
      // Silencioso a propósito — ver comentario de arriba.
    } finally {
      target.disabled = false;
    }
  });
}

async function initAdminPage() {
  if (!authService.isAuthenticated()) {
    document.querySelector('main').innerHTML = '<p class="error-state mt-16">Inicia sesión con una cuenta con permisos administrativos.</p>';
    return;
  }

  wireSidebar();
  wireServiceManagement();
  wireProductManagement();
  wireAutoTranslate('service-name', 'service-name-en');
  wireAutoTranslate('service-description', 'service-description-en');
  wireAutoTranslate('product-name', 'product-name-en');
  wireAutoTranslate('product-short-description', 'product-short-description-en');
  wireCategoryManagement();
  wireBrandManagement();
  wireSupplierManagement();
  wirePaymentMethodManagement();
  wireCouponManagement();
  document.getElementById('orders-status-filter').addEventListener('change', loadOrders);
  document.getElementById('inventory-filter-form').addEventListener('submit', (event) => {
    event.preventDefault();
    loadInventory();
  });
  document.getElementById('reservations-date-filter').addEventListener('change', loadReservations);
  document.getElementById('reservations-upcoming-only').addEventListener('change', loadReservations);
  document.getElementById('reservations-clear-filter-btn').addEventListener('click', () => {
    document.getElementById('reservations-date-filter').value = '';
    loadReservations();
  });
  document.getElementById('customers-search-form').addEventListener('submit', (event) => {
    event.preventDefault();
    loadCustomers();
  });
  document.getElementById('customers-include-staff').addEventListener('change', loadCustomers);

  loadDashboard();
  loadOrders();
  loadReservations();
  loadCustomers();
  loadInventory();
  populateServiceCategorySelect();
  loadServices();
  populateProductSelects();
  loadProductsAdmin();
  loadCategoriesAdmin();
  loadBrands();
  loadSuppliers();
  loadPaymentMethods();
  loadCoupons();
  loadSettingsTerms();
  loadSettingsPrivacy();
  loadSettingsContact();
  loadSettingsHours();
  loadSettingsLogo();
  wireSettingsForm();
}

/**
 * Configuración general (sección "falta un editor de configuración general
 * del sitio" del README) — arranca con términos y condiciones, el mismo
 * texto que ya muestra /terminos, editable acá sin tocar código.
 */
async function loadSettingsTerms() {
  const textarea = document.getElementById('settings-terms-content');
  try {
    const { content } = await settingsService.terms();
    textarea.value = content || '';
  } catch (error) {
    document.getElementById('settings-terms-error').textContent = 'No fue posible cargar el contenido actual.';
  }
}

/** Política de datos — mismo patrón que loadSettingsTerms(), ver /privacidad. */
async function loadSettingsPrivacy() {
  const textarea = document.getElementById('settings-privacy-content');
  try {
    const { content } = await settingsService.privacy();
    textarea.value = content || '';
  } catch (error) {
    document.getElementById('settings-privacy-error').textContent = 'No fue posible cargar el contenido actual.';
  }
}

/** Correo/WhatsApp públicos — mismos campos que expone GET /settings/public. */
async function loadSettingsContact() {
  try {
    const settings = await settingsService.get();
    document.getElementById('settings-contact-email').value = settings.contact_email || '';
    document.getElementById('settings-contact-whatsapp').value = settings.contact_whatsapp_number || '';
  } catch (error) {
    document.getElementById('settings-contact-error').textContent = 'No fue posible cargar el contenido actual.';
  }
}

/** Horario de atención — arma los horarios de lavado.js/servicio.js y se muestra en la portada. */
async function loadSettingsHours() {
  try {
    const settings = await settingsService.get();
    document.getElementById('settings-hours-start').value = settings.business_hours_start || '08:30';
    document.getElementById('settings-hours-end').value = settings.business_hours_end || '16:30';
  } catch (error) {
    document.getElementById('settings-hours-error').textContent = 'No fue posible cargar el contenido actual.';
  }
}

/** Logo del sitio: muestra el actual (o el estático de siempre si nunca se subió ninguno). */
async function loadSettingsLogo() {
  try {
    const settings = await settingsService.get();
    if (settings.site_logo) {
      document.getElementById('settings-logo-preview').src = helpers.mediaUrl('settings', settings.site_logo);
    }
  } catch (error) {
    // Sigue mostrando el logo estático por defecto — no es bloqueante.
  }
}

function wireSettingsForm() {
  document.getElementById('settings-terms-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = document.getElementById('settings-terms-error');
    errorBox.textContent = '';

    try {
      await adminService.updateTerms(document.getElementById('settings-terms-content').value);
      helpers.toast('Términos y condiciones actualizados.', 'success');
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    }
  });

  document.getElementById('settings-privacy-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = document.getElementById('settings-privacy-error');
    errorBox.textContent = '';

    try {
      await adminService.updatePrivacyPolicy(document.getElementById('settings-privacy-content').value);
      helpers.toast('Política de datos actualizada.', 'success');
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    }
  });

  document.getElementById('settings-contact-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = document.getElementById('settings-contact-error');
    errorBox.textContent = '';

    try {
      await adminService.updateContactInfo({
        contact_email: document.getElementById('settings-contact-email').value.trim(),
        contact_whatsapp_number: document.getElementById('settings-contact-whatsapp').value.trim(),
      });
      settingsService._cache = null; // el botón de WhatsApp/correo público se refresca en la próxima carga de página
      helpers.toast('Información de contacto actualizada.', 'success');
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    }
  });

  document.getElementById('settings-hours-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = document.getElementById('settings-hours-error');
    errorBox.textContent = '';

    try {
      await adminService.updateBusinessHours({
        business_hours_start: document.getElementById('settings-hours-start').value,
        business_hours_end: document.getElementById('settings-hours-end').value,
      });
      settingsService._cache = null; // el wizard de lavado y la portada lo vuelven a pedir en la próxima carga
      helpers.toast('Horario de atención actualizado.', 'success');
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    }
  });

  document.getElementById('settings-logo-input').addEventListener('change', async (event) => {
    const file = event.target.files[0];
    if (!file) return;

    const errorBox = document.getElementById('settings-logo-error');
    errorBox.textContent = '';

    try {
      const { site_logo } = await adminService.uploadLogo(file);
      document.getElementById('settings-logo-preview').src = helpers.mediaUrl('settings', site_logo);
      settingsService._cache = null; // el header (layout.js) vuelve a pedirlo en la próxima carga de página
      helpers.toast('Logo actualizado. Se va a ver en el resto del sitio al recargar la página.', 'success');
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    } finally {
      event.target.value = '';
    }
  });

  document.getElementById('settings-backup-btn').addEventListener('click', async () => {
    const btn = document.getElementById('settings-backup-btn');
    const errorBox = document.getElementById('settings-backup-error');
    errorBox.textContent = '';
    btn.disabled = true;
    btn.textContent = 'Generando backup…';

    try {
      const result = await adminService.sendBackup();
      helpers.toast(`Backup enviado a ${result.email}.`, 'success');
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    } finally {
      btn.disabled = false;
      btn.textContent = '📦 Generar y enviar backup';
    }
  });
}

document.addEventListener('DOMContentLoaded', initAdminPage);
