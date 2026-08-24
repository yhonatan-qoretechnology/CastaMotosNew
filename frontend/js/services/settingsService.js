/**
 * Configuración pública no sensible (GET /api/settings/public), ej. el número
 * de WhatsApp del negocio para el botón de "Enviar pedido". Se cachea en
 * memoria tras la primera carga: es la misma para todas las páginas de una
 * sesión de navegación, no hace falta pedirla de nuevo en cada vista.
 */
const settingsService = {
  _cache: null,

  async get() {
    if (this._cache) return this._cache;
    try {
      this._cache = await apiService.get('/settings/public');
    } catch (error) {
      this._cache = { contact_whatsapp_number: '' };
    }
    return this._cache;
  },

  /** Términos y condiciones (sección 6), guardados en la BD — ver /terminos. */
  terms: () => apiService.get('/settings/terms'),

  /** Política de datos, guardada en la BD — ver /privacidad. */
  privacy: () => apiService.get('/settings/privacy'),
};
