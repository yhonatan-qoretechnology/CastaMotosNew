/**
 * Panel administrativo básico (Fase 6): pedidos e inventario. Requiere JWT +
 * permiso manage-orders/manage-inventory — la seguridad real la aplica el
 * backend (AuthMiddleware + RequirePermissionMiddleware); esto es solo el
 * cliente HTTP.
 */
const adminService = {
  orders: (filters = {}) => apiService.get('/admin/orders' + catalogService.toQueryString(filters)),
  order: (orderNumber) => apiService.get(`/admin/orders/${encodeURIComponent(orderNumber)}`),
  updateOrderStatus: (orderNumber, status, comment) =>
    apiService.put(`/admin/orders/${encodeURIComponent(orderNumber)}/status`, { status, comment }),

  inventory: (filters = {}) => apiService.get('/admin/inventory' + catalogService.toQueryString(filters)),
  adjustInventory: (productId, payload) => apiService.post(`/admin/inventory/${productId}/adjust`, payload),
  movements: (filters = {}) => apiService.get('/admin/inventory/movements' + catalogService.toQueryString(filters)),

  // Reservas de servicios (sección 12) — cada una ES un pedido con servicio
  // agendado; el cambio de estado reutiliza updateOrderStatus() de arriba.
  reservations: (filters = {}) => apiService.get('/admin/reservations' + catalogService.toQueryString(filters)),

  // Resumen del negocio para la pestaña "Resumen" (sección 28).
  dashboardSummary: () => apiService.get('/admin/dashboard/summary'),

  // Clientes registrados (sección 28) — excluye staff por defecto.
  customers: (filters = {}) => apiService.get('/admin/customers' + catalogService.toQueryString(filters)),
  updateCustomerRole: (userId, role) => apiService.put(`/admin/customers/${userId}/role`, { role }),

  // Configuración de métodos de pago (sección 21) — activar/desactivar y
  // guardar su config (nunca escritos en el código).
  paymentMethods: () => apiService.get('/admin/payment-methods'),
  updatePaymentMethod: (id, payload) => apiService.put(`/admin/payment-methods/${id}`, payload),

  // Cupones (sección 30).
  coupons: (filters = {}) => apiService.get('/admin/coupons' + catalogService.toQueryString(filters)),
  createCoupon: (payload) => apiService.post('/admin/coupons', payload),
  updateCoupon: (id, payload) => apiService.put(`/admin/coupons/${id}`, payload),
  deleteCoupon: (id) => apiService.del(`/admin/coupons/${id}`),

  // Configuración general del sitio (permiso manage-settings).
  updateTerms: (content) => apiService.put('/admin/settings/terms', { content }),
  uploadLogo: (file) => {
    const formData = new FormData();
    formData.append('logo', file);
    return apiService.post('/admin/settings/logo', formData, { isFormData: true });
  },

  // Traducción ES → EN para los campos "_en" de productos/servicios (botón "Traducir").
  translate: (text) => apiService.post('/admin/translate', { text }),

  // Proveedores (agenda interna, no pública — a diferencia de brands/marcas).
  suppliers: (filters = {}) => apiService.get('/admin/suppliers' + catalogService.toQueryString(filters)),
  createSupplier: (payload) => apiService.post('/admin/suppliers', payload),
  updateSupplier: (id, payload) => apiService.put(`/admin/suppliers/${id}`, payload),
  deleteSupplier: (id) => apiService.del(`/admin/suppliers/${id}`),
};
