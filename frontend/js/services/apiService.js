/**
 * Cliente HTTP centralizado (sección 39: "el frontend debe comunicarse con
 * el backend exclusivamente mediante API"). Todos los demás servicios pasan
 * por aquí — un solo lugar sabe cómo autenticar y leer errores del backend.
 *
 * Ruta relativa (sin "/" inicial) a propósito: el sitio corre bajo un
 * subdirectorio (<base href> en cada página, ver index.php), así que una
 * ruta absoluta como "/api" apuntaría a la raíz real del dominio, no a la
 * app. fetch() resuelve rutas relativas contra <base> igual que los <a href>.
 */
const API_BASE = 'api';

function authToken() {
  return localStorage.getItem('castamoto_token');
}

function cartToken() {
  return localStorage.getItem('castamoto_cart_token');
}

function setCartToken(token) {
  if (token) {
    localStorage.setItem('castamoto_cart_token', token);
  }
}

async function request(method, path, { body, isFormData = false } = {}) {
  const headers = {};
  const token = authToken();
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const cart = cartToken();
  if (cart) headers['X-Cart-Token'] = cart;

  const options = { method, headers };

  if (body !== undefined) {
    if (isFormData) {
      options.body = body; // FormData: el navegador pone el Content-Type con boundary
    } else {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }
  }

  let response;
  try {
    response = await fetch(API_BASE + path, options);
  } catch (networkError) {
    throw new Error('No fue posible conectar con el servidor. Verifica tu conexión.');
  }

  let payload = null;
  try {
    payload = await response.json();
  } catch (parseError) {
    throw new Error('El servidor respondió con un formato inesperado.');
  }

  // El backend de CASTAMOTO siempre envuelve la respuesta en { success, message, data|errors }.
  if (!payload.success) {
    // Sesión perdida/expirada (no un intento de login con contraseña mala:
    // ESE caso nunca manda token, así que "token &&" no lo intercepta acá —
    // solo actúa cuando YA creíamos estar logueados y el backend rechazó ese
    // token). Se limpia la sesión y se recarga con una marca para que
    // initLayout() (layout.js) abra el login solo, en vez de dejar a medio
    // camino cualquier pantalla que asumía que seguías logueado.
    if (response.status === 401 && token) {
      localStorage.removeItem('castamoto_token');
      localStorage.removeItem('castamoto_user');
      sessionStorage.setItem('castamoto_session_expired', '1');
      window.location.reload();
      return new Promise(() => {}); // no resuelve: la recarga ya está en camino
    }

    const error = new Error(payload.message || 'Ocurrió un error.');
    error.fields = payload.errors || {};
    error.status = response.status;
    throw error;
  }

  // El carrito de invitado devuelve su token en la propia respuesta (Fase 5): se guarda solo.
  if (payload.data && typeof payload.data === 'object' && payload.data.cart_token) {
    setCartToken(payload.data.cart_token);
  }

  return payload.data;
}

const apiService = {
  get: (path) => request('GET', path),
  post: (path, body, options = {}) => request('POST', path, { body, ...options }),
  put: (path, body) => request('PUT', path, { body }),
  del: (path) => request('DELETE', path),
  authToken,
  cartToken,
  setCartToken,
};
