/**
 * Header + navegación de categorías + modal de login/registro + footer.
 * Se monta en TODAS las páginas sobre <div id="app-header"> / <div id="app-footer">
 * (sección 40: componentes reutilizables — Header, Navbar, Buscador, Modal).
 */
/**
 * Solo controla si se MUESTRA el link "Admin" (mejor UX) — no es la
 * seguridad real, que vive en el backend (AuthMiddleware + RequirePermissionMiddleware).
 */
function isAdminUser(user) {
  return !!user && Array.isArray(user.roles) && user.roles.some((role) => ['administrador', 'superadministrador'].includes(role));
}

function renderHeaderShell() {
  const mount = document.getElementById('app-header');
  if (!mount) return;

  const user = authService.currentUser();

  mount.innerHTML = `
    <header class="site-header">
      <div class="container site-header__bar">
        <a href="." class="site-header__logo">
          <img src="frontend/assets/img/logo.png" alt="CASTAMOTO">
          <span>CASTAMOTO</span>
        </a>
        <div class="site-header__search">
          <form id="header-search-form" role="search">
            <label class="sr-only" for="header-search-input">${i18nService.t('search.label')}</label>
            <input id="header-search-input" type="search" placeholder="${i18nService.t('search.placeholder')}" autocomplete="off">
            <button type="submit" aria-label="${i18nService.t('search.aria')}">🔍</button>
          </form>
        </div>
        <nav class="site-header__actions">
          <button class="icon-btn site-header__mobile-toggle" id="mobile-actions-toggle" type="button" aria-label="${i18nService.t('nav.menu')}" aria-haspopup="true" aria-expanded="false">
            ☰
          </button>
          <div class="site-header__actions-menu" id="site-header-actions-menu">
            <button class="icon-btn" id="lang-toggle-btn" type="button" aria-label="${i18nService.t('lang.toggle.aria')}" style="font-weight:700;">
              ${i18nService.current() === 'es' ? 'EN' : 'ES'}
            </button>
            <button class="icon-btn" id="theme-toggle-btn" type="button" aria-label="${themeService.current() === 'light' ? i18nService.t('theme.toggle.toDark') : i18nService.t('theme.toggle.toLight')}">
              ${themeService.current() === 'light' ? '☀️' : '🌙'}
            </button>
            ${user ? `
            <div class="notif-bell" id="notif-bell">
              <button class="icon-btn" id="notif-toggle-btn" type="button" aria-label="${i18nService.t('notif.aria')}" aria-haspopup="true" aria-expanded="false">
                🔔<span class="badge-count" id="notif-badge" hidden>0</span>
              </button>
              <div class="notif-panel" id="notif-panel" hidden>
                <div class="notif-panel__header">
                  <span>${i18nService.t('notif.title')}</span>
                  <button type="button" id="notif-mark-all-btn">${i18nService.t('notif.markAllRead')}</button>
                </div>
                <div class="notif-panel__list" id="notif-list">
                  <p class="loading-state" style="padding:12px;">${i18nService.t('nav.loading')}</p>
                </div>
              </div>
            </div>
            ` : ''}
            ${user ? `<a class="icon-btn" href="favoritos" aria-label="${i18nService.t('nav.favorites')}">♥</a>` : ''}
            <a class="icon-btn" href="carrito" aria-label="${i18nService.t('nav.cart')}">
              🛒<span class="badge-count" id="cart-badge" hidden>0</span>
            </a>
            ${isAdminUser(user) ? `<a class="icon-btn" href="admin">${i18nService.t('nav.admin')}</a>` : ''}
            ${user
              ? `<a href="perfil" style="font-size:0.85rem;color:var(--gris-texto);">${i18nService.t('nav.greeting')} <strong style="color:var(--blanco)">${helpers.escapeHtml(user.name)}</strong></a>
                 <button class="icon-btn" id="logout-btn">${i18nService.t('nav.logout')}</button>`
              : `<button class="icon-btn" id="open-login-btn">${i18nService.t('nav.login')}</button>`}
          </div>
        </nav>
      </div>
      <div class="category-nav">
        <div class="container category-nav__bar">
          <div class="category-nav__dropdown">
            <button class="category-nav__toggle" id="category-dropdown-toggle" type="button" aria-haspopup="true" aria-expanded="false">
              ☰ ${i18nService.t('nav.categories')} <span class="category-nav__caret">▾</span>
            </button>
            <div class="category-nav__menu" id="category-dropdown-menu">
              <p class="loading-state" style="padding:8px 10px;">${i18nService.t('nav.loading')}</p>
            </div>
          </div>
          <div class="category-nav__links-wrap">
            <button class="category-nav__toggle category-nav__links-toggle" id="nav-links-toggle" type="button" aria-haspopup="true" aria-expanded="false">
              ☰ ${i18nService.t('nav.menu')} <span class="category-nav__caret">▾</span>
            </button>
            <div class="category-nav__links" id="nav-links-menu">
              <a href="productos">${i18nService.t('nav.allProducts')}</a>
              <a href="productos?on_sale=1">${i18nService.t('nav.deals')}</a>
              <a href="servicios">${i18nService.t('nav.services')}</a>
            </div>
          </div>
        </div>
      </div>
    </header>
    ${authModalMarkup()}
  `;

  document.getElementById('lang-toggle-btn').addEventListener('click', () => i18nService.toggle());

  document.getElementById('theme-toggle-btn').addEventListener('click', () => {
    const next = themeService.toggle();
    const button = document.getElementById('theme-toggle-btn');
    button.textContent = next === 'light' ? '☀️' : '🌙';
    button.setAttribute('aria-label', next === 'light' ? i18nService.t('theme.toggle.toDark') : i18nService.t('theme.toggle.toLight'));
  });

  document.getElementById('header-search-form').addEventListener('submit', (event) => {
    event.preventDefault();
    const term = document.getElementById('header-search-input').value.trim();
    if (term) window.location.href = `productos?search=${encodeURIComponent(term)}`;
  });

  if (user) {
    document.getElementById('logout-btn').addEventListener('click', () => {
      authService.logout();
      helpers.toast('Sesión cerrada.', 'success');
      window.location.href = '.';
    });
  } else {
    document.getElementById('open-login-btn').addEventListener('click', () => openAuthModal('login'));
  }

  loadCategoryNav();
  initCategoryDropdown();
  wireDropdownToggle('mobile-actions-toggle', 'site-header-actions-menu', '__mobileActionsOutsideClickBound');
  refreshCartBadge();
  helpers.initPasswordToggles(mount);

  if (user) {
    initNotificationBell();
    refreshNotifBadge();
  }
}

/** Tipo de notificación → a dónde lleva al hacer click (ver data en cada tabla notifications). */
function notificationHref(notification) {
  const data = notification.data || {};
  if (data.slug && notification.type === 'new_service') return `servicio/${data.slug}`;
  if (data.slug) return `producto/${data.slug}`;
  if (data.order_number) return `admin`;
  return null;
}

async function refreshNotifBadge() {
  const badge = document.getElementById('notif-badge');
  if (!badge) return;

  try {
    const { unread_count: unreadCount } = await notificationService.list();
    badge.textContent = String(unreadCount);
    badge.hidden = unreadCount === 0;
  } catch (error) {
    badge.hidden = true;
  }
}

async function loadNotificationList() {
  const list = document.getElementById('notif-list');
  if (!list) return;

  try {
    const { notifications } = await notificationService.list();

    if (notifications.length === 0) {
      list.innerHTML = `<p class="empty-state" style="padding:16px;font-size:0.85rem;">${i18nService.t('notif.empty')}</p>`;
      return;
    }

    list.innerHTML = notifications.map((notification) => `
      <button type="button" class="notif-item ${notification.is_read ? '' : 'notif-item--unread'}" data-id="${notification.id}">
        <span class="notif-item__title">${helpers.escapeHtml(notification.title)}</span>
        <span class="notif-item__message">${helpers.escapeHtml(notification.message)}</span>
        <span class="notif-item__date">${helpers.formatDateTime(notification.created_at)}</span>
      </button>
    `).join('');

    list.querySelectorAll('.notif-item').forEach((item) => {
      item.addEventListener('click', async () => {
        const id = item.dataset.id;
        const notification = notifications.find((n) => String(n.id) === id);
        try {
          await notificationService.markRead(id);
        } catch (error) {
          // Best-effort: si falla el "marcar como leída" igual navega/cierra.
        }
        refreshNotifBadge();
        const href = notification ? notificationHref(notification) : null;
        if (href) window.location.href = href;
      });
    });
  } catch (error) {
    list.innerHTML = `<p class="error-state" style="padding:16px;">${i18nService.t('notif.loadError')}</p>`;
  }
}

function initNotificationBell() {
  const toggle = document.getElementById('notif-toggle-btn');
  const panel = document.getElementById('notif-panel');
  if (!toggle || !panel) return;

  toggle.addEventListener('click', () => {
    const willOpen = panel.hidden;
    panel.hidden = !willOpen;
    toggle.setAttribute('aria-expanded', String(willOpen));
    if (willOpen) loadNotificationList();
  });

  document.getElementById('notif-mark-all-btn').addEventListener('click', async (event) => {
    event.stopPropagation();
    try {
      await notificationService.markAllRead();
      await loadNotificationList();
      await refreshNotifBadge();
    } catch (error) {
      helpers.toast('No fue posible marcar las notificaciones como leídas.', 'error');
    }
  });

  // Mismo criterio que el dropdown de categorías (ver initCategoryDropdown):
  // un solo listener global, busca los elementos vigentes por id en cada click,
  // porque renderHeaderShell() se puede volver a llamar (login/logout).
  if (!window.__notifOutsideClickBound) {
    window.__notifOutsideClickBound = true;
    document.addEventListener('click', (event) => {
      const currentBell = document.getElementById('notif-bell');
      const currentPanel = document.getElementById('notif-panel');
      if (!currentBell || !currentPanel || currentPanel.hidden) return;

      if (!currentBell.contains(event.target)) {
        currentPanel.hidden = true;
        document.getElementById('notif-toggle-btn')?.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // Refresco periódico del contador (no hay push real todavía — ver
  // PushNotificationFactory — así que se sondea cada 60s mientras la
  // pestaña está abierta).
  if (!window.__notifPollingStarted) {
    window.__notifPollingStarted = true;
    setInterval(() => {
      if (document.getElementById('notif-badge')) refreshNotifBadge();
    }, 60000);
  }
}

async function loadCategoryNav() {
  const menu = document.getElementById('category-dropdown-menu');
  if (!menu) return;

  try {
    const categories = await catalogService.categories();

    if (categories.length === 0) {
      menu.innerHTML = '<p class="empty-state" style="padding:8px 10px;">Sin categorías todavía.</p>';
      return;
    }

    // Chips en fila, todas al mismo nivel (una raíz con hijas muestra sus
    // hijas, no la raíz misma — mismo criterio que loadHomeCategories() en
    // home.js) — panel chico y horizontal, no una lista vertical larga.
    const chips = categories.flatMap((cat) => (cat.children && cat.children.length > 0 ? cat.children : [cat]));
    menu.innerHTML = chips.map((cat) =>
      `<a href="categoria/${encodeURIComponent(cat.slug)}">${helpers.escapeHtml(cat.name)}</a>`
    ).join('');
  } catch (error) {
    // La navegación de categorías es un "nice to have": si falla, el resto de la página sigue funcionando.
    menu.innerHTML = '<p class="error-state" style="padding:8px 10px;">No fue posible cargar las categorías.</p>';
    console.error('No fue posible cargar las categorías del menú.', error);
  }
}

/**
 * Cablea un dropdown genérico (toggle + panel que se abre/cierra con
 * .is-open) — usado por el de Categorías Y el de "Menú" (Todos los
 * productos/Ofertas/Servicios en mobile, ver category-nav__links-toggle).
 * Un solo listener global de "click afuera cierra" por par, guardado en
 * window[boundFlag] porque renderHeaderShell() se puede volver a llamar
 * (login/logout/cambio de idioma) y no se puede cerrar sobre referencias
 * puntuales de una llamada anterior.
 */
function wireDropdownToggle(toggleId, menuId, boundFlag) {
  const toggle = document.getElementById(toggleId);
  const menu = document.getElementById(menuId);
  if (!toggle || !menu) return;

  toggle.addEventListener('click', () => {
    const willOpen = !menu.classList.contains('is-open');
    menu.classList.toggle('is-open', willOpen);
    toggle.setAttribute('aria-expanded', String(willOpen));
  });

  if (!window[boundFlag]) {
    window[boundFlag] = true;
    document.addEventListener('click', (event) => {
      const currentToggle = document.getElementById(toggleId);
      const currentMenu = document.getElementById(menuId);
      if (!currentToggle || !currentMenu) return;

      if (!currentToggle.contains(event.target) && !currentMenu.contains(event.target)) {
        currentMenu.classList.remove('is-open');
        currentToggle.setAttribute('aria-expanded', 'false');
      }
    });
  }
}

function initCategoryDropdown() {
  wireDropdownToggle('category-dropdown-toggle', 'category-dropdown-menu', '__categoryDropdownOutsideClickBound');
  wireDropdownToggle('nav-links-toggle', 'nav-links-menu', '__navLinksOutsideClickBound');
}

async function refreshCartBadge() {
  const badge = document.getElementById('cart-badge');
  if (!badge) return;

  try {
    const cart = await cartService.get();
    const count = cart.items.reduce((sum, item) => sum + item.quantity, 0);
    badge.textContent = String(count);
    badge.hidden = count === 0;
  } catch (error) {
    badge.hidden = true;
  }
}

function authModalMarkup() {
  return `
    <div class="modal-overlay" id="auth-modal-overlay">
      <div class="modal">
        <div class="modal__header">
          <h2 class="modal__title" id="auth-modal-title">${i18nService.t('auth.login.title')}</h2>
          <button class="modal__close" id="auth-modal-close" aria-label="${i18nService.t('auth.close')}">✕</button>
        </div>
        <div class="modal__tabs">
          <button class="modal__tab is-active" data-tab="login">${i18nService.t('auth.login.title')}</button>
          <button class="modal__tab" data-tab="register">${i18nService.t('auth.register.title')}</button>
        </div>

        <form id="login-form">
          <div class="form-group">
            <label for="login-email">${i18nService.t('auth.email')}</label>
            <input class="form-control" type="email" id="login-email" required>
          </div>
          <div class="form-group">
            <label for="login-password">${i18nService.t('auth.password')}</label>
            <div class="password-field">
              <input class="form-control" type="password" id="login-password" required>
              <button type="button" class="password-toggle" data-target="login-password" aria-label="${i18nService.t('auth.showPassword')}"></button>
            </div>
          </div>
          <a class="auth-link" href="recuperar-password">${i18nService.t('auth.forgotPassword')}</a>
          <div class="form-error" id="login-error"></div>
          <button class="btn btn-primary btn-block" type="submit">${i18nService.t('auth.submitLogin')}</button>
        </form>

        <form id="register-form" hidden>
          <div class="form-row">
            <div class="form-group">
              <label for="register-name">${i18nService.t('auth.name')}</label>
              <input class="form-control" id="register-name" required>
            </div>
            <div class="form-group">
              <label for="register-last-name">${i18nService.t('auth.lastName')}</label>
              <input class="form-control" id="register-last-name" required>
            </div>
          </div>
          <div class="form-group">
            <label for="register-email">${i18nService.t('auth.email')}</label>
            <input class="form-control" type="email" id="register-email" required>
          </div>
          <div class="form-group">
            <label for="register-password">${i18nService.t('auth.password')}</label>
            <div class="password-field">
              <input class="form-control" type="password" id="register-password" minlength="8" required>
              <button type="button" class="password-toggle" data-target="register-password" aria-label="${i18nService.t('auth.showPassword')}"></button>
            </div>
          </div>
          <div class="form-group">
            <label for="register-password-confirmation">${i18nService.t('auth.passwordConfirm')}</label>
            <div class="password-field">
              <input class="form-control" type="password" id="register-password-confirmation" minlength="8" required>
              <button type="button" class="password-toggle" data-target="register-password-confirmation" aria-label="${i18nService.t('auth.showPassword')}"></button>
            </div>
          </div>
          <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem;">
              <input type="checkbox" id="register-terms" required style="width:auto;">
              ${i18nService.t('auth.acceptTermsPrefix')} <a href="terminos" id="register-terms-link" target="_blank" rel="noopener" style="color:var(--amarillo);text-decoration:underline;">${i18nService.t('auth.termsLink')}</a>
            </label>
          </div>
          <div class="form-error" id="register-error"></div>
          <button class="btn btn-primary btn-block" type="submit">${i18nService.t('auth.submitRegister')}</button>
        </form>
      </div>
    </div>

    <div class="modal-overlay" id="terms-modal-overlay">
      <div class="modal modal--wide">
        <div class="modal__header">
          <h2 class="modal__title">${i18nService.t('auth.termsLink')}</h2>
          <button class="modal__close" id="terms-modal-close" aria-label="${i18nService.t('auth.close')}">✕</button>
        </div>
        <div id="terms-modal-body" style="white-space:pre-line;color:var(--gris-texto);font-size:0.9rem;">
          <p class="loading-state">${i18nService.t('nav.loading')}</p>
        </div>
      </div>
    </div>
  `;
}

function openAuthModal(tab = 'login') {
  const overlay = document.getElementById('auth-modal-overlay');
  overlay.classList.add('is-open');
  switchAuthTab(tab);
}

function closeAuthModal() {
  document.getElementById('auth-modal-overlay').classList.remove('is-open');
}

// Se cachea una vez por carga de página — mismo texto para toda la sesión,
// no hace falta volver a pedirlo cada vez que se reabre el modal.
let cachedTermsContent = null;

/**
 * Términos y condiciones EN MODAL (antes abría /terminos en pestaña nueva):
 * mismo contenido real de site_settings (sección 6, ver settingsService.terms()
 * / terminos.js) — no un texto duplicado acá — para no perder lo ya escrito
 * en el formulario de registro al querer leerlos.
 */
async function openTermsModal() {
  const overlay = document.getElementById('terms-modal-overlay');
  const body = document.getElementById('terms-modal-body');
  overlay.classList.add('is-open');

  if (cachedTermsContent !== null) {
    body.textContent = cachedTermsContent;
    return;
  }

  body.innerHTML = `<p class="loading-state">${i18nService.t('nav.loading')}</p>`;
  try {
    const { content } = await settingsService.terms();
    cachedTermsContent = content || 'Todavía no se ha publicado el texto de términos y condiciones.';
    body.textContent = cachedTermsContent;
  } catch (error) {
    body.innerHTML = `<p class="error-state">No fue posible cargar los términos y condiciones.</p>`;
  }
}

function closeTermsModal() {
  document.getElementById('terms-modal-overlay').classList.remove('is-open');
}

function switchAuthTab(tab) {
  document.querySelectorAll('.modal__tab').forEach((btn) => btn.classList.toggle('is-active', btn.dataset.tab === tab));
  document.getElementById('login-form').hidden = tab !== 'login';
  document.getElementById('register-form').hidden = tab !== 'register';
  document.getElementById('auth-modal-title').textContent = tab === 'login' ? i18nService.t('auth.login.title') : i18nService.t('auth.register.title');
}

function initAuthModalEvents() {
  const overlay = document.getElementById('auth-modal-overlay');
  if (!overlay) return;

  document.getElementById('auth-modal-close').addEventListener('click', closeAuthModal);
  overlay.addEventListener('click', (event) => { if (event.target === overlay) closeAuthModal(); });

  document.getElementById('register-terms-link').addEventListener('click', (event) => {
    event.preventDefault();
    openTermsModal();
  });
  const termsOverlay = document.getElementById('terms-modal-overlay');
  document.getElementById('terms-modal-close').addEventListener('click', closeTermsModal);
  termsOverlay.addEventListener('click', (event) => { if (event.target === termsOverlay) closeTermsModal(); });

  document.querySelectorAll('.modal__tab').forEach((btn) => {
    btn.addEventListener('click', () => switchAuthTab(btn.dataset.tab));
  });

  document.getElementById('login-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = document.getElementById('login-error');
    errorBox.textContent = '';

    try {
      const data = await authService.login(
        document.getElementById('login-email').value,
        document.getElementById('login-password').value
      );
      // El admin entra directo al panel (sección 28): no tiene sentido que
      // inicie sesión y vuelva a ver la página pública que estaba mirando.
      if (isAdminUser(data.user) && !window.location.pathname.replace(/\/$/, '').endsWith('/admin')) {
        window.location.href = 'admin';
      } else {
        window.location.reload();
      }
    } catch (error) {
      errorBox.textContent = error.message;
    }
  });

  document.getElementById('register-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const errorBox = document.getElementById('register-error');
    errorBox.textContent = '';

    try {
      await authService.register({
        name: document.getElementById('register-name').value,
        last_name: document.getElementById('register-last-name').value,
        email: document.getElementById('register-email').value,
        password: document.getElementById('register-password').value,
        password_confirmation: document.getElementById('register-password-confirmation').value,
        terms_accepted: document.getElementById('register-terms').checked,
      });
      window.location.reload();
    } catch (error) {
      errorBox.textContent = helpers.flattenErrors(error.fields) || error.message;
    }
  });
}

function renderFooter() {
  const mount = document.getElementById('app-footer');
  if (!mount) return;

  mount.innerHTML = `
    <footer class="site-footer">
      <div class="container">
        <div class="site-footer__grid">
          <div class="site-footer__col">
            <h3>CASTAMOTO</h3>
            <p style="color:var(--gris-texto);font-size:0.85rem;">${i18nService.t('footer.tagline')}</p>
          </div>
          <div class="site-footer__col">
            <h3>${i18nService.t('footer.buy')}</h3>
            <a href="productos">${i18nService.t('nav.allProducts')}</a>
            <a href="servicios">${i18nService.t('nav.services')}</a>
            <a href="productos?on_sale=1">${i18nService.t('nav.deals')}</a>
          </div>
          <div class="site-footer__col">
            <h3>${i18nService.t('footer.myAccount')}</h3>
            <a href="perfil">${i18nService.t('footer.myProfile')}</a>
            <a href="pedidos">${i18nService.t('footer.myOrders')}</a>
            <a href="carrito">${i18nService.t('nav.cart')}</a>
            <a href="favoritos">${i18nService.t('footer.myFavorites')}</a>
          </div>
        </div>
        <div class="site-footer__bottom">
          <a href="terminos">${i18nService.t('footer.terms')}</a> · ${i18nService.t('footer.progressNotice')} © ${new Date().getFullYear()} CASTAMOTO
        </div>
      </div>
    </footer>
  `;
}

/**
 * Botón flotante de WhatsApp (sección 51/contacto), visible en TODO el
 * sitio, no solo en la confirmación de pedido (ahí ya existía uno con el
 * resumen de la compra — ver pedido.js — este es el de "contactar al
 * negocio" en general). Oculto por completo si no hay número configurado
 * (CONTACT_WHATSAPP_NUMBER vacío en el .env del servidor) — nunca se
 * inventa un número, mismo criterio que el resto del sitio.
 */
async function renderWhatsappButton() {
  if (typeof settingsService === 'undefined') return; // por si alguna página no cargó el script

  try {
    const settings = await settingsService.get();
    const number = settings.contact_whatsapp_number;
    if (!number) return;

    const link = document.createElement('a');
    link.className = 'whatsapp-float-btn';
    link.href = `https://wa.me/${number}?text=${encodeURIComponent('Hola, tengo una consulta sobre CASTAMOTO.')}`;
    link.target = '_blank';
    link.rel = 'noopener';
    link.setAttribute('aria-label', 'Escribir por WhatsApp');
    // Ícono en línea (no emoji: se ve distinto/apenas visible según el
    // sistema operativo, mismo motivo que el ojito de contraseña) — silueta
    // de auricular en burbuja de chat en blanco, sobre el círculo verde que
    // ya pone .whatsapp-float-btn en el CSS (currentColor = --blanco acá).
    link.innerHTML = `
      <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor" aria-hidden="true">
        <path d="M12 2C6.48 2 2 6.19 2 11.35c0 1.95.65 3.76 1.76 5.29L2.6 20.9a.6.6 0 0 0 .74.74l4.53-1.14a10.5 10.5 0 0 0 4.13.86c5.52 0 10-4.19 10-9.35C22 6.19 17.52 2 12 2zm4.86 13.02c-.2.57-1.17 1.11-1.62 1.15-.42.04-.81.2-2.74-.57-2.32-.93-3.82-3.19-3.94-3.34-.11-.15-.94-1.24-.94-2.37s.6-1.68.82-1.91c.21-.23.46-.29.62-.29h.44c.14 0 .33 0 .5.38.2.44.65 1.53.71 1.64.06.11.1.24.02.4-.08.15-.12.24-.24.37-.12.14-.25.31-.36.42-.12.11-.24.24-.1.47.14.23.63 1.02 1.35 1.65.93.81 1.71 1.06 1.94 1.18.23.11.37.09.5-.05.14-.15.6-.68.76-.91.16-.23.31-.19.53-.11.21.08 1.35.62 1.58.73.23.11.38.16.44.26.06.1.06.57-.14 1.14z"/>
      </svg>
    `;
    document.body.appendChild(link);
  } catch (error) {
    // Sin número disponible o falló la carga: el sitio sigue funcionando
    // normal, simplemente no aparece el botón.
  }
}

/**
 * Bot de preguntas (Fase 11, sección "info de la página"): widget flotante
 * en todo el sitio, arriba del botón de WhatsApp para no superponerse.
 * Funciona sin sesión (igual que el carrito) — si el proveedor de IA
 * todavía no está configurado en el servidor, el backend responde con un
 * mensaje claro (ver AiProviderFactory) que se muestra tal cual en el chat,
 * nunca un error crudo ni una respuesta inventada.
 */
function assistantMessageMarkup(role, text) {
  const cls = role === 'user' ? 'assistant-msg--user' : 'assistant-msg--bot';
  return `<div class="assistant-msg ${cls}">${helpers.escapeHtml(text)}</div>`;
}

function renderAssistantWidget() {
  const toggle = document.createElement('button');
  toggle.className = 'assistant-toggle-btn';
  toggle.type = 'button';
  toggle.setAttribute('aria-label', 'Preguntas sobre CASTAMOTO');
  toggle.innerHTML = `
    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
    </svg>
  `;

  const panel = document.createElement('div');
  panel.className = 'assistant-panel';
  panel.hidden = true;
  panel.innerHTML = `
    <div class="assistant-panel__header">
      <span>💬 Asistente CASTAMOTO</span>
      <button type="button" id="assistant-close-btn" aria-label="Cerrar">✕</button>
    </div>
    <div class="assistant-panel__messages" id="assistant-messages">
      ${assistantMessageMarkup('assistant', '¡Hola! Preguntame sobre productos, servicios, envíos o cómo comprar en CASTAMOTO.')}
    </div>
    <form class="assistant-panel__form" id="assistant-form">
      <label class="sr-only" for="assistant-input">Tu pregunta</label>
      <input class="form-control" type="text" id="assistant-input" placeholder="Escribí tu pregunta…" autocomplete="off" maxlength="1000">
      <button type="submit" aria-label="Enviar">➤</button>
    </form>
  `;

  document.body.appendChild(toggle);
  document.body.appendChild(panel);

  const messagesBox = panel.querySelector('#assistant-messages');
  let conversationId = null;
  try {
    const saved = sessionStorage.getItem('castamoto_ai_conversation_id');
    conversationId = saved ? Number(saved) : null;
  } catch (error) {
    // sessionStorage puede fallar en navegación privada estricta: simplemente no persiste entre páginas.
  }

  toggle.addEventListener('click', () => {
    panel.hidden = !panel.hidden;
    if (!panel.hidden) document.getElementById('assistant-input').focus();
  });
  panel.querySelector('#assistant-close-btn').addEventListener('click', () => { panel.hidden = true; });

  panel.querySelector('#assistant-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const input = document.getElementById('assistant-input');
    const message = input.value.trim();
    if (!message) return;

    messagesBox.insertAdjacentHTML('beforeend', assistantMessageMarkup('user', message));
    input.value = '';
    input.disabled = true;

    const typingEl = document.createElement('div');
    typingEl.className = 'assistant-msg assistant-msg--bot assistant-msg--typing';
    typingEl.textContent = 'Escribiendo…';
    messagesBox.appendChild(typingEl);
    messagesBox.scrollTop = messagesBox.scrollHeight;

    try {
      const result = await apiService.post('/assistant/ask', { message, conversation_id: conversationId });
      conversationId = result.conversation_id;
      try { sessionStorage.setItem('castamoto_ai_conversation_id', String(conversationId)); } catch (error) { /* ver comentario arriba */ }
      typingEl.remove();
      messagesBox.insertAdjacentHTML('beforeend', assistantMessageMarkup('assistant', result.reply));
    } catch (error) {
      typingEl.remove();
      messagesBox.insertAdjacentHTML('beforeend', assistantMessageMarkup('assistant', helpers.flattenErrors(error.fields) || error.message));
    } finally {
      input.disabled = false;
      input.focus();
      messagesBox.scrollTop = messagesBox.scrollHeight;
    }
  });
}

async function initLayout() {
  // Si hay token guardado, se refresca el usuario contra el backend ANTES de
  // pintar el header: así el link "Admin" (y el nombre mostrado) siempre
  // reflejan el rol real actual, no una copia vieja de localStorage.
  if (authService.isAuthenticated()) {
    await authService.refreshUser();
  }

  renderHeaderShell();
  initAuthModalEvents();
  renderFooter();
  renderWhatsappButton();
  renderAssistantWidget();
}

document.addEventListener('DOMContentLoaded', initLayout);
