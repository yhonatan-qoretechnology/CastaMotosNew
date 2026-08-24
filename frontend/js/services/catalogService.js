/**
 * Catálogo (Fase 3) y búsqueda (Fase 4). Delgado a propósito: solo arma la
 * query string y delega en apiService.
 */
function toQueryString(params = {}) {
  const usable = Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== '');
  if (usable.length === 0) return '';
  return '?' + usable.map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`).join('&');
}

const catalogService = {
  categories: () => apiService.get('/categories'),
  createCategory: (payload) => apiService.post('/categories', payload),
  updateCategory: (id, payload) => apiService.put(`/categories/${id}`, payload),
  uploadCategoryImage: (id, file) => {
    const formData = new FormData();
    formData.append('image', file);
    return apiService.post(`/categories/${id}/image`, formData, { isFormData: true });
  },
  deleteCategory: (id) => apiService.del(`/categories/${id}`),
  brands: () => apiService.get('/brands'),
  createBrand: (payload) => apiService.post('/brands', payload),
  updateBrand: (id, payload) => apiService.put(`/brands/${id}`, payload),
  deleteBrand: (id) => apiService.del(`/brands/${id}`),

  products: (filters = {}) => apiService.get('/products' + toQueryString(filters)),
  product: (slug) => apiService.get(`/products/${encodeURIComponent(slug)}`),

  // Gestión de productos (panel admin) — requiere permiso manage-products.
  createProduct: (payload) => apiService.post('/products', payload),
  updateProduct: (id, payload) => apiService.put(`/products/${id}`, payload),
  deleteProduct: (id) => apiService.del(`/products/${id}`),
  uploadProductImage: (id, file, isPrimary = false) => {
    const formData = new FormData();
    formData.append('image', file);
    if (isPrimary) formData.append('is_primary', '1');
    return apiService.post(`/products/${id}/images`, formData, { isFormData: true });
  },
  deleteProductImage: (productId, imageId) => apiService.del(`/products/${productId}/images/${imageId}`),
  setPrimaryProductImage: (productId, imageId) => apiService.put(`/products/${productId}/images/${imageId}/primary`),

  services: (filters = {}) => apiService.get('/services' + toQueryString(filters)),
  service: (slug) => apiService.get(`/services/${encodeURIComponent(slug)}`),
  serviceBookedTimes: (serviceId, date) => apiService.get(`/services/${serviceId}/booked-times` + toQueryString({ date })),

  // Gestión de servicios (panel admin/vendedor) — requiere permiso manage-services,
  // aplicado en el backend (RequirePermissionMiddleware); aquí solo se arma la petición.
  createService: (payload) => apiService.post('/services', payload),
  updateService: (id, payload) => apiService.put(`/services/${id}`, payload),
  deleteService: (id) => apiService.del(`/services/${id}`),
  uploadServiceImage: (id, file) => {
    const formData = new FormData();
    formData.append('image', file);
    return apiService.post(`/services/${id}/images`, formData, { isFormData: true });
  },
  deleteServiceImage: (serviceId, imageId) => apiService.del(`/services/${serviceId}/images/${imageId}`),

  search: (q) => apiService.get('/search' + toQueryString({ q })),
  suggestions: (q) => apiService.get('/search/suggestions' + toQueryString({ q })),

  // Favoritos (Fase 4) — requieren sesión iniciada.
  favorites: () => apiService.get('/favorites'),
  addFavorite: (type, id) => apiService.post('/favorites', { type, id }),
  removeFavorite: (type, id) => apiService.del(`/favorites/${type}/${id}`),

  // Reseñas (sección 26) — leer es público, publicar requiere sesión + haber comprado.
  reviews: (type, id) => apiService.get('/reviews' + toQueryString({ type, id })),
  submitReview: (payload) => apiService.post('/reviews', payload),

  toQueryString,
};
