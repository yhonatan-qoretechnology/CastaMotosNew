/**
 * Sesión del usuario (Fase 2). El JWT y los datos básicos se guardan en
 * localStorage; el propio backend valida el token en cada petición protegida.
 */
const authService = {
  currentUser() {
    const raw = localStorage.getItem('castamoto_user');
    return raw ? JSON.parse(raw) : null;
  },

  isAuthenticated() {
    return !!apiService.authToken();
  },

  /**
   * Vuelve a pedir el usuario actual al backend (GET /auth/me) y actualiza
   * la copia en localStorage. Sin esto, "castamoto_user" solo se escribe en
   * login/registro: si el rol de una cuenta cambia en el servidor, o si el
   * dato quedó desactualizado por una sesión anterior, el header seguiría
   * mostrando (o escondiendo) el link "Admin" con datos viejos para siempre,
   * sin importar cuántas veces se recargue la página. Se llama en cada carga
   * de layout (ver initLayout) cuando hay token guardado.
   */
  async refreshUser() {
    if (!apiService.authToken()) return null;

    try {
      const user = await apiService.get('/auth/me');
      localStorage.setItem('castamoto_user', JSON.stringify(user));
      return user;
    } catch (error) {
      // Token vencido o inválido: se limpia la sesión para no quedar en un
      // estado inconsistente (token guardado pero backend ya no lo reconoce).
      this.logout();
      return null;
    }
  },

  async login(email, password, remember = false) {
    const data = await apiService.post('/auth/login', { email, password, remember });
    this.persistSession(data);
    return data;
  },

  async register(payload) {
    const data = await apiService.post('/auth/register', payload);
    this.persistSession(data);
    return data;
  },

  /** "Continuar con Google": credential = el ID token que entrega Google Identity Services. */
  async loginWithGoogle(credential) {
    const data = await apiService.post('/auth/google', { credential });
    this.persistSession(data);
    return data;
  },

  /** Sección "recuperar contraseña": el backend responde igual exista o no el correo (evita enumeración de usuarios). */
  async forgotPassword(email) {
    await apiService.post('/auth/forgot-password', { email });
  },

  async resetPassword(token, password, passwordConfirmation) {
    await apiService.post('/auth/reset-password', {
      token,
      password,
      password_confirmation: passwordConfirmation,
    });
  },

  persistSession(data) {
    localStorage.setItem('castamoto_token', data.token);
    localStorage.setItem('castamoto_user', JSON.stringify(data.user));
    // El backend fusiona el carrito de invitado con el del usuario en el login
    // (Fase 5); una vez fusionado, el token de invitado ya no aplica.
    localStorage.removeItem('castamoto_cart_token');
  },

  logout() {
    localStorage.removeItem('castamoto_token');
    localStorage.removeItem('castamoto_user');
  },

  // "Mi perfil" — el usuario editando sus propios datos (distinto del panel
  // admin de clientes, que además puede cambiar el rol de OTROS usuarios).
  async updateProfile(payload) {
    const user = await apiService.put('/profile', payload);
    localStorage.setItem('castamoto_user', JSON.stringify(user));
    return user;
  },

  async uploadAvatar(file) {
    const formData = new FormData();
    formData.append('avatar', file);
    const result = await apiService.post('/profile/avatar', formData, { isFormData: true });
    const user = this.currentUser();
    if (user) {
      user.avatar = result.avatar;
      localStorage.setItem('castamoto_user', JSON.stringify(user));
    }
    return result;
  },

  changePassword(currentPassword, password, passwordConfirmation) {
    return apiService.post('/auth/change-password', {
      current_password: currentPassword,
      password,
      password_confirmation: passwordConfirmation,
    });
  },
};
