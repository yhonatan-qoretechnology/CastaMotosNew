/**
 * Política de datos — mismo patrón que terminos.js: contenido real guardado
 * en la base (site_settings), editable desde /admin → Configuración.
 */
async function initPrivacyPage() {
  const mount = document.getElementById('privacy-mount');

  try {
    const { content } = await settingsService.privacy();
    mount.textContent = content || 'Todavía no se ha publicado la política de datos.';
  } catch (error) {
    mount.innerHTML = `<p class="error-state">No fue posible cargar la política de datos.</p>`;
  }
}

document.addEventListener('DOMContentLoaded', initPrivacyPage);
