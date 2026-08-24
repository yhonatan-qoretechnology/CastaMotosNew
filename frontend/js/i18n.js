/**
 * Selector de idioma ES/EN (sección nueva). Diseño: el español SIGUE siendo
 * el texto real en el HTML/JS (cero riesgo para el 99% de usuarios que no
 * cambian de idioma) — en inglés, un solo paso de traducción pisa esos
 * textos por clave. Dos mecanismos que conviven:
 *
 *  1) HTML estático (index.php, páginas): atributo `data-i18n="clave"` en el
 *     elemento. Ver applyStaticTranslations(), que corre en cada carga.
 *  2) HTML armado por JS (layout.js, home.js, etc.): usar i18nService.t('clave')
 *     directamente en el template literal en vez de tipear el string en español.
 *
 * Contenido de negocio (productos/servicios/categorías) NO vive acá — usa
 * sus propias columnas *_en en la base (ver helpers.localized()), porque lo
 * escribe el admin, no es texto fijo de la interfaz.
 *
 * Guardado en localStorage (mismo criterio que themeService) — cambiar de
 * idioma recarga la página para que TODO (datos ya cargados desde la API
 * incluidos) se re-renderice consistente, en vez de tener que reescribir cada
 * función de render para reaccionar en vivo.
 */
const I18N_DICTIONARY = {
  es: {
    'search.placeholder': 'Buscar cascos, repuestos, servicios...',
    'search.aria': 'Buscar',
    'search.label': 'Buscar productos y servicios',
    'theme.toggle.toDark': 'Cambiar a tema oscuro',
    'theme.toggle.toLight': 'Cambiar a tema claro',
    'lang.toggle.aria': 'Cambiar idioma',
    'notif.title': 'Notificaciones',
    'notif.markAllRead': 'Marcar todas como leídas',
    'notif.empty': 'No tenés notificaciones todavía.',
    'notif.loadError': 'No fue posible cargar las notificaciones.',
    'notif.aria': 'Notificaciones',
    'nav.favorites': 'Mis favoritos',
    'nav.cart': 'Carrito',
    'nav.admin': 'Admin',
    'nav.greeting': 'Hola,',
    'nav.logout': 'Salir',
    'nav.login': 'Iniciar sesión',
    'nav.categories': 'Categorías',
    'nav.menu': 'Menú',
    'nav.allProducts': 'Todos los productos',
    'nav.deals': 'Ofertas',
    'nav.services': 'Servicios',
    'nav.loading': 'Cargando…',
    'auth.login.title': 'Iniciar sesión',
    'auth.register.title': 'Crear cuenta',
    'auth.close': 'Cerrar',
    'auth.email': 'Correo',
    'auth.phone': 'Celular / WhatsApp',
    'auth.password': 'Contraseña',
    'auth.passwordConfirm': 'Confirmar contraseña',
    'auth.name': 'Nombre',
    'auth.lastName': 'Apellido',
    'auth.forgotPassword': '¿Olvidaste tu contraseña?',
    'auth.submitLogin': 'Entrar',
    'auth.submitRegister': 'Crear cuenta',
    'auth.showPassword': 'Mostrar contraseña',
    'auth.acceptTermsPrefix': 'Acepto los',
    'auth.termsLink': 'términos y condiciones',
    'auth.orDivider': 'o',
    'footer.tagline': 'Todo para tu moto: repuestos, accesorios y servicios especializados.',
    'footer.buy': 'Comprar',
    'footer.myAccount': 'Mi cuenta',
    'footer.myProfile': 'Mi perfil',
    'footer.myOrders': 'Mis pedidos',
    'footer.myFavorites': 'Mis favoritos',
    'footer.terms': 'Términos y condiciones',
    'footer.privacy': 'Política de datos',
    'footer.progressNotice': 'Plataforma en construcción progresiva.',
    'home.hero.title': 'Todo para tu moto, en un solo lugar',
    'home.hero.subtitle': 'Repuestos, accesorios, cascos y servicios especializados para tu motocicleta.',
    'home.hero.searchPlaceholder': '¿Qué estás buscando?',
    'home.hero.searchBtn': 'Buscar',
    'home.wash.title': 'Lavado de Motos y Cascos',
    'home.wash.subtitle': 'Reservá el día y la hora que prefieras.',
    'home.wash.btn': 'Reservar',
    'home.promo.title': 'Todo para tu moto, con envío a todo Manizales',
    'home.promo.subtitle': 'Repuestos, accesorios y servicios especializados en un solo lugar. ¿Eres de otra ciudad? Consultanos por WhatsApp.',
    'home.promo.btn': 'Explorar productos',
    'home.section.categories': 'Categorías',
    'home.section.deals': 'Ofertas',
    'home.section.products': 'Productos destacados',
    'home.section.services': 'Servicios destacados',
    'home.section.wash': 'Lavado de Motos y Cascos',
    'home.loading.categories': 'Cargando categorías…',
    'home.loading.products': 'Cargando productos…',
    'home.loading.services': 'Cargando servicios…',
    'home.loading.wash': 'Cargando servicios de lavado…',
    'home.trust.secure': '🔒 Compra segura',
    'home.trust.secureDesc': 'Datos protegidos en cada pedido.',
    'home.trust.shipping': '🚚 Envíos a todo Manizales',
    'home.trust.shippingDesc': 'Entrega a domicilio o recogida en tienda. Otra ciudad: consultanos por WhatsApp.',
    'home.trust.support': '💬 Soporte especializado',
    'home.trust.supportDesc': 'Servicios realizados por profesionales.',
    'home.trust.hours': '🕐 Horario de atención',
    'card.noImage': 'Sin imagen',
    'card.favorite': 'Favorito',
    'card.stock.available': 'Disponible',
    'card.stock.lastUnits': 'Últimas unidades',
    'card.stock.soldOut': 'Agotado',
    'home.error.categories': 'No fue posible cargar las categorías.',
    'home.error.products': 'No fue posible cargar los productos.',
    'home.error.services': 'No fue posible cargar los servicios.',
    'home.error.wash': 'No fue posible cargar los servicios de lavado — todavía no están creados en el catálogo.',
    'home.empty.categories': 'Todavía no hay categorías publicadas.',
    'home.empty.products': 'Todavía no hay productos publicados.',
    'home.empty.services': 'Todavía no hay servicios publicados.',
  },
  en: {
    'search.placeholder': 'Search helmets, parts, services...',
    'search.aria': 'Search',
    'search.label': 'Search products and services',
    'theme.toggle.toDark': 'Switch to dark theme',
    'theme.toggle.toLight': 'Switch to light theme',
    'lang.toggle.aria': 'Change language',
    'notif.title': 'Notifications',
    'notif.markAllRead': 'Mark all as read',
    'notif.empty': "You don't have any notifications yet.",
    'notif.loadError': 'Could not load notifications.',
    'notif.aria': 'Notifications',
    'nav.favorites': 'My favorites',
    'nav.cart': 'Cart',
    'nav.admin': 'Admin',
    'nav.greeting': 'Hi,',
    'nav.logout': 'Log out',
    'nav.login': 'Log in',
    'nav.categories': 'Categories',
    'nav.menu': 'Menu',
    'nav.allProducts': 'All products',
    'nav.deals': 'Deals',
    'nav.services': 'Services',
    'nav.loading': 'Loading…',
    'auth.login.title': 'Log in',
    'auth.register.title': 'Create account',
    'auth.close': 'Close',
    'auth.email': 'Email',
    'auth.phone': 'Mobile / WhatsApp',
    'auth.password': 'Password',
    'auth.passwordConfirm': 'Confirm password',
    'auth.name': 'First name',
    'auth.lastName': 'Last name',
    'auth.forgotPassword': 'Forgot your password?',
    'auth.submitLogin': 'Log in',
    'auth.submitRegister': 'Create account',
    'auth.showPassword': 'Show password',
    'auth.acceptTermsPrefix': 'I accept the',
    'auth.termsLink': 'terms and conditions',
    'auth.orDivider': 'or',
    'footer.tagline': 'Everything for your motorcycle: parts, accessories and specialized services.',
    'footer.buy': 'Shop',
    'footer.myAccount': 'My account',
    'footer.myProfile': 'My profile',
    'footer.myOrders': 'My orders',
    'footer.myFavorites': 'My favorites',
    'footer.terms': 'Terms and conditions',
    'footer.privacy': 'Privacy policy',
    'footer.progressNotice': 'Platform under ongoing development.',
    'home.hero.title': 'Everything for your motorcycle, in one place',
    'home.hero.subtitle': 'Parts, accessories, helmets and specialized services for your motorcycle.',
    'home.hero.searchPlaceholder': 'What are you looking for?',
    'home.hero.searchBtn': 'Search',
    'home.wash.title': 'Motorcycle & Helmet Washing',
    'home.wash.subtitle': 'Book the day and time that works for you.',
    'home.wash.btn': 'Book now',
    'home.promo.title': 'Everything for your motorcycle, shipped across Manizales',
    'home.promo.subtitle': 'Parts, accessories and specialized services in one place. In another city? Ask us on WhatsApp.',
    'home.promo.btn': 'Explore products',
    'home.section.categories': 'Categories',
    'home.section.deals': 'Deals',
    'home.section.products': 'Featured products',
    'home.section.services': 'Featured services',
    'home.section.wash': 'Motorcycle & Helmet Washing',
    'home.loading.categories': 'Loading categories…',
    'home.loading.products': 'Loading products…',
    'home.loading.services': 'Loading services…',
    'home.loading.wash': 'Loading wash services…',
    'home.trust.secure': '🔒 Secure checkout',
    'home.trust.secureDesc': 'Your data is protected on every order.',
    'home.trust.shipping': '🚚 Shipping across Manizales',
    'home.trust.shippingDesc': 'Home delivery or in-store pickup. Other city? Ask us on WhatsApp.',
    'home.trust.support': '💬 Specialized support',
    'home.trust.supportDesc': 'Services performed by professionals.',
    'home.trust.hours': '🕐 Business hours',
    'card.noImage': 'No image',
    'card.favorite': 'Favorite',
    'card.stock.available': 'In stock',
    'card.stock.lastUnits': 'Last units',
    'card.stock.soldOut': 'Sold out',
    'home.error.categories': 'Could not load categories.',
    'home.error.products': 'Could not load products.',
    'home.error.services': 'Could not load services.',
    'home.error.wash': 'Could not load wash services — they have not been created in the catalog yet.',
    'home.empty.categories': 'No categories published yet.',
    'home.empty.products': 'No products published yet.',
    'home.empty.services': 'No services published yet.',
  },
};

const i18nService = {
  current() {
    return localStorage.getItem('castamoto_lang') || 'es';
  },
  set(lang) {
    localStorage.setItem('castamoto_lang', lang);
    window.location.reload();
  },
  toggle() {
    this.set(this.current() === 'es' ? 'en' : 'es');
  },
  t(key) {
    const lang = this.current();
    return (I18N_DICTIONARY[lang] && I18N_DICTIONARY[lang][key]) || I18N_DICTIONARY.es[key] || key;
  },
};

/**
 * Traduce el HTML estático marcado con data-i18n (ver index.php). Solo hace
 * algo si el idioma actual es inglés — en español el HTML ya está en el
 * idioma correcto, no hay nada que pisar (cero riesgo de romper nada).
 */
function applyStaticTranslations() {
  if (i18nService.current() === 'es') return;

  document.querySelectorAll('[data-i18n]').forEach((el) => {
    el.textContent = i18nService.t(el.dataset.i18n);
  });
  document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
    el.placeholder = i18nService.t(el.dataset.i18nPlaceholder);
  });
}

document.addEventListener('DOMContentLoaded', applyStaticTranslations);
