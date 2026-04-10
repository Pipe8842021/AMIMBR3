/* ============================================================
   script-preinscripcion.js
   - Calcula edad automáticamente al cambiar fecha de nacimiento
   - Valida campos requeridos antes de enviar
   - Muestra modal de éxito o error tras el envío del formulario
   ============================================================ */

(function () {
    'use strict';

    /* ── 1. CÁLCULO AUTOMÁTICO DE EDAD ─────────────────────── */
    const fechaNac = document.getElementById('fecha_nacimiento');
    const edadInput = document.getElementById('edad');

    if (fechaNac && edadInput) {
        fechaNac.addEventListener('change', function () {
            const hoy = new Date();
            const nac = new Date(this.value);
            if (!isNaN(nac)) {
                let edad = hoy.getFullYear() - nac.getFullYear();
                const m = hoy.getMonth() - nac.getMonth();
                if (m < 0 || (m === 0 && hoy.getDate() < nac.getDate())) edad--;
                edadInput.value = edad >= 0 ? edad : '';
            }
        });
    }

    /* ── 2. MODAL ───────────────────────────────────────────── */
    /**
     * Crea e inyecta el HTML de la modal en el body.
     * @param {'success'|'error'} type
     * @param {string} name  - Nombre del estudiante (para personalizar el mensaje)
     */
    function crearModal(type, name) {
        // Evitar duplicados
        const existing = document.getElementById('preins-modal');
        if (existing) existing.remove();

        const isSuccess = type === 'success';

        const iconSVG = isSuccess
            ? `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                   <polyline points="20 6 9 17 4 12"/>
               </svg>`
            : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                   <circle cx="12" cy="12" r="10"/>
                   <line x1="12" y1="8" x2="12" y2="12"/>
                   <line x1="12" y1="16" x2="12.01" y2="16"/>
               </svg>`;

        const titulo = isSuccess ? '¡Preinscripción enviada!' : 'Error al enviar';

        const mensaje = isSuccess
            ? (name
                ? `Tu solicitud ha sido registrada con éxito, <strong>${name}</strong>. Nos comunicaremos contigo muy pronto para confirmar tu cupo.`
                : 'Tu solicitud ha sido registrada con éxito. Nos comunicaremos contigo muy pronto para confirmar tu cupo.')
            : 'Ocurrió un problema al procesar tu solicitud. Por favor revisa tu conexión e inténtalo nuevamente. Si el problema persiste, contáctanos directamente.';

        const botones = isSuccess
            ? `<button class="modal-btn modal-btn--primary" id="modal-cerrar">Entendido</button>`
            : `<button class="modal-btn modal-btn--ghost"   id="modal-cerrar">Cancelar</button>
               <button class="modal-btn modal-btn--primary" id="modal-reintentar">Reintentar</button>`;

        const detalle = isSuccess
            ? `<span class="modal-detail">📧 Revisa tu correo electrónico</span>`
            : `<span class="modal-detail">📞 312 286 72 97 · escuelademusicaamimbre@gmail.com</span>`;

        const html = `
        <div class="modal-overlay" id="preins-modal" role="dialog" aria-modal="true" aria-labelledby="modal-titulo">
            <div class="modal-box modal-box--${type}">
                <div class="modal-icon modal-icon--${type}">${iconSVG}</div>
                <h2 class="modal-title" id="modal-titulo">${titulo}</h2>
                <p class="modal-msg">${mensaje}</p>
                ${detalle}
                <div class="modal-actions">${botones}</div>
            </div>
        </div>`;

        document.body.insertAdjacentHTML('beforeend', html);

        // Mostrar con animación (pequeño delay para que el CSS transition funcione)
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                document.getElementById('preins-modal').classList.add('modal-visible');
            });
        });

        // Eventos de cierre
        document.getElementById('modal-cerrar').addEventListener('click', cerrarModal);

        const btnReintentar = document.getElementById('modal-reintentar');
        if (btnReintentar) {
            btnReintentar.addEventListener('click', cerrarModal);
        }

        // Cerrar al hacer clic fuera del modal-box
        document.getElementById('preins-modal').addEventListener('click', function (e) {
            if (e.target === this) cerrarModal();
        });

        // Cerrar con Escape
        document.addEventListener('keydown', onEscape);
    }

    function cerrarModal() {
        const modal = document.getElementById('preins-modal');
        if (!modal) return;
        modal.classList.remove('modal-visible');
        document.removeEventListener('keydown', onEscape);
        modal.addEventListener('transitionend', () => modal.remove(), { once: true });
    }

    function onEscape(e) {
        if (e.key === 'Escape') cerrarModal();
    }

    /* ── 3. DETECCIÓN DE PARÁMETROS EN LA URL ───────────────── */
    // Si el servidor redirige a ?success o ?error, mostramos la modal
    // en lugar del alert inline del PHP.
    const params = new URLSearchParams(window.location.search);

    if (params.has('success')) {
        // Intenta leer el nombre del campo si quedó guardado en sessionStorage
        const nombre = sessionStorage.getItem('preins-nombre') || '';
        sessionStorage.removeItem('preins-nombre');
        // Pequeño delay para que el DOM esté listo
        setTimeout(() => crearModal('success', nombre), 200);
        // Limpiar la URL sin recargar
        history.replaceState(null, '', window.location.pathname);
    } else if (params.has('error')) {
        setTimeout(() => crearModal('error', ''), 200);
        history.replaceState(null, '', window.location.pathname);
    }

    /* ── 4. VALIDACIÓN Y ENVÍO DEL FORMULARIO ───────────────── */
    const form = document.getElementById('preregistrationForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        // Quitar errores previos
        this.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));

        const requeridos = this.querySelectorAll('[required]');
        let valido = true;

        requeridos.forEach(el => {
            const vacio =
                (el.type === 'checkbox' && !el.checked) ||
                (el.tagName === 'SELECT' && !el.value) ||
                (el.type !== 'checkbox' && el.tagName !== 'SELECT' && !el.value.trim());

            if (vacio) {
                el.classList.add('input-error');
                valido = false;
            }
        });

        if (!valido) {
            e.preventDefault();
            const primerError = this.querySelector('.input-error');
            primerError?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        // Guardar nombre en sessionStorage para personalizar el mensaje de éxito
        const nombreInput = document.getElementById('nombres_apellidos');
        if (nombreInput && nombreInput.value.trim()) {
            const partes = nombreInput.value.trim().split(' ');
            // Guardar solo el primer nombre
            sessionStorage.setItem('preins-nombre', partes[0]);
        }

        /* ── MODO AJAX (opcional) ─────────────────────────────
           Si el formulario tiene data-ajax="true", se envía via
           fetch y la modal aparece sin recargar la página.
           De lo contrario el form hace submit normal y el PHP
           redirige a ?success / ?error.
        ─────────────────────────────────────────────────────── */
        if (form.dataset.ajax === 'true') {
            e.preventDefault();

            const formData = new FormData(form);

            // Mostrar estado de carga en el botón
            const btnSubmit = form.querySelector('.btn-submit');
            const textoOriginal = btnSubmit.innerHTML;
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = `
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                </svg>
                Enviando…`;

            fetch(form.action, { method: 'POST', body: formData })
                .then(res => {
                    if (res.ok || res.redirected) {
                        const nombre = sessionStorage.getItem('preins-nombre') || '';
                        sessionStorage.removeItem('preins-nombre');
                        crearModal('success', nombre);
                        form.reset();
                    } else {
                        crearModal('error', '');
                    }
                })
                .catch(() => crearModal('error', ''))
                .finally(() => {
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = textoOriginal;
                });
        }
        // Si NO es ajax, el form hace submit normal → PHP redirige → JS detecta params arriba.
    });

})();