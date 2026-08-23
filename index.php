<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CASTAMOTO — Todo para tu moto</title>
<meta name="description" content="Marketplace de motocicletas: repuestos, accesorios, cascos y servicios especializados.">
<!-- El sitio puede vivir en la raíz del dominio (producción, castamotos.com)
     o bajo un subdirectorio (desarrollo local, /proyectos/castamotos/) — en vez de
     un <base href> fijo que hay que recordar cambiar en 9 archivos cada vez que
     cambia el despliegue, se calcula solo según el host. document.write() es
     intencional acá: corre durante el parseo del HTML, ANTES que <link>/<script src>
     de más abajo intenten resolver rutas relativas, que es justo lo que <base> necesita. -->
<script>
(function () {
  var isLocal = location.hostname === 'localhost' || location.hostname === '127.0.0.1';
  document.write('<base href="' + (isLocal ? '/proyectos/castamotos/' : '/') + '">');
})();
</script>
<link rel="stylesheet" href="frontend/css/main.css?v=20260822g">
<link rel="icon" type="image/png" href="frontend/assets/img/logo.png">
<script src="frontend/js/theme-init.js?v=20260822g"></script>
</head>
<body>

<div id="app-header"></div>

<section class="hero">
  <div class="hero-float hero-float--1" aria-hidden="true">🔧</div>
  <div class="hero-float hero-float--2" aria-hidden="true">⚙️</div>
  <div class="hero-float hero-float--3" aria-hidden="true">🪖</div>
  <div class="hero-float hero-float--4" aria-hidden="true">🏍️</div>
  <div class="hero-float hero-float--5" aria-hidden="true">⚙️</div>
  <div class="container">
    <h1 data-i18n="home.hero.title">Todo para tu moto, en un solo lugar</h1>
    <p data-i18n="home.hero.subtitle">Repuestos, accesorios, cascos y servicios especializados para tu motocicleta.</p>
    <form id="hero-search-form" style="max-width:520px;margin:20px auto 0;display:flex;border-radius:10px;overflow:hidden;border:1px solid var(--gris-borde);">
      <label class="sr-only" for="hero-search-input" data-i18n="search.aria">Buscar</label>
      <input class="form-control" id="hero-search-input" type="search" placeholder="¿Qué estás buscando?" data-i18n-placeholder="home.hero.searchPlaceholder" style="border:none;border-radius:0;">
      <button class="btn btn-primary" type="submit" data-i18n="home.hero.searchBtn">Buscar</button>
    </form>
  </div>
</section>

<main class="container">
  <div class="home-banners">
    <a class="wash-cta" href="lavado">
      <span class="wash-cta__anim" aria-hidden="true">
        <span class="wash-cta__moto">🏍️</span>
        <span class="wash-cta__bubble wash-cta__bubble--1"></span>
        <span class="wash-cta__bubble wash-cta__bubble--2"></span>
        <span class="wash-cta__bubble wash-cta__bubble--3"></span>
        <span class="wash-cta__bubble wash-cta__bubble--4"></span>
      </span>
      <span class="wash-cta__text">
        <strong data-i18n="home.wash.title">Lavado de Motos y Cascos</strong>
        <span data-i18n="home.wash.subtitle">Reservá el día y la hora que prefieras.</span>
      </span>
      <span class="wash-cta__btn"><span data-i18n="home.wash.btn">Reservar</span> <span class="wash-cta__arrow">→</span></span>
    </a>

    <div class="promo-banner">
      <div class="promo-banner__text">
        <h2 data-i18n="home.promo.title">Todo para tu moto, con envío a todo Manizales</h2>
        <p data-i18n="home.promo.subtitle">Repuestos, accesorios y servicios especializados en un solo lugar. ¿Eres de otra ciudad? Consultanos por WhatsApp.</p>
      </div>
      <a class="btn btn-primary" href="productos" data-i18n="home.promo.btn">Explorar productos</a>
    </div>
  </div>

  <section class="section" id="home-wash-section">
    <h2 class="section__title" data-i18n="home.section.wash">Lavado de Motos y Cascos</h2>
    <div id="home-wash"><p class="loading-state" data-i18n="home.loading.wash">Cargando servicios de lavado…</p></div>
  </section>

  <section class="section">
    <h2 class="section__title" data-i18n="home.section.categories">Categorías</h2>
    <div id="home-categories"><p class="loading-state" data-i18n="home.loading.categories">Cargando categorías…</p></div>
  </section>

  <section class="section" id="home-deals-section" hidden>
    <h2 class="section__title" data-i18n="home.section.deals">Ofertas</h2>
    <div id="home-deals"></div>
  </section>

  <section class="section">
    <h2 class="section__title" data-i18n="home.section.products">Productos destacados</h2>
    <div id="home-products"><p class="loading-state" data-i18n="home.loading.products">Cargando productos…</p></div>
  </section>

  <section class="section">
    <h2 class="section__title" data-i18n="home.section.services">Servicios destacados</h2>
    <div id="home-services"><p class="loading-state" data-i18n="home.loading.services">Cargando servicios…</p></div>
  </section>

  <section class="section">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
      <div class="card"><div class="card__body text-center"><strong style="color:var(--amarillo);" data-i18n="home.trust.secure">🔒 Compra segura</strong><p class="card__meta" data-i18n="home.trust.secureDesc">Datos protegidos en cada pedido.</p></div></div>
      <div class="card"><div class="card__body text-center"><strong style="color:var(--amarillo);" data-i18n="home.trust.shipping">🚚 Envíos a todo Manizales</strong><p class="card__meta" data-i18n="home.trust.shippingDesc">Entrega a domicilio o recogida en tienda. Otra ciudad: consultanos por WhatsApp.</p></div></div>
      <div class="card"><div class="card__body text-center"><strong style="color:var(--amarillo);" data-i18n="home.trust.support">💬 Soporte especializado</strong><p class="card__meta" data-i18n="home.trust.supportDesc">Servicios realizados por profesionales.</p></div></div>
    </div>
  </section>
</main>

<div id="app-footer"></div>

<script src="frontend/js/i18n.js?v=20260822g"></script>
<script src="frontend/js/utils/helpers.js?v=20260822g"></script>
<script src="frontend/js/services/apiService.js?v=20260822g"></script>
<script src="frontend/js/services/authService.js?v=20260822g"></script>
<script src="frontend/js/services/catalogService.js?v=20260822g"></script>
<script src="frontend/js/services/cartService.js?v=20260822g"></script>
<script src="frontend/js/services/themeService.js?v=20260822g"></script>
<script src="frontend/js/services/settingsService.js?v=20260822g"></script>
<script src="frontend/js/services/notificationService.js?v=20260822g"></script>
<script src="frontend/js/components/layout.js?v=20260822g"></script>
<script src="frontend/js/components/cards.js?v=20260822g"></script>
<script src="frontend/js/pages/home.js?v=20260822g"></script>
</body>
</html>
