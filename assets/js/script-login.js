/* ============================================================
   script-login.js
   - Moneda 3D interactiva (Three.js) — código original
   - Modal de éxito / error en el login
   - Spinner en el botón al hacer submit
   ============================================================ */

/* ── THREE.JS — MONEDA 3D ───────────────────────────────────── */
const container = document.getElementById('three-canvas');
const scene = new THREE.Scene();
scene.background = null;

const camera = new THREE.PerspectiveCamera(50, 1, 0.1, 1000);
camera.position.z = 8;

const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
renderer.setSize(600, 600);
renderer.shadowMap.enabled = true;
container.appendChild(renderer.domElement);

// Lighting
const ambientLight = new THREE.AmbientLight(0xffffff, 0.6);
scene.add(ambientLight);

const spotLight = new THREE.SpotLight(0xffffff, 1.2);
spotLight.position.set(10, 10, 10);
spotLight.castShadow = true;
scene.add(spotLight);

const pointLight = new THREE.PointLight(0xffffff, 0.5);
pointLight.position.set(-10, -10, -10);
scene.add(pointLight);

const directionalLight = new THREE.DirectionalLight(0xffffff, 0.5);
directionalLight.position.set(0, 5, 5);
scene.add(directionalLight);

// Coin group
const coinGroup = new THREE.Group();
coinGroup.rotation.x = Math.PI / 2;

// Main cylinder (coin body)
const cylinderGeometry = new THREE.CylinderGeometry(2, 2, 0.4, 64);
const cylinderMaterial = new THREE.MeshStandardMaterial({
    color: 0x1a5570,
    metalness: 0.95,
    roughness: 0.1
});
const cylinder = new THREE.Mesh(cylinderGeometry, cylinderMaterial);
cylinder.castShadow = true;
cylinder.receiveShadow = true;
coinGroup.add(cylinder);

// Load logo texture
const textureLoader = new THREE.TextureLoader();
const logoTexture = textureLoader.load('../assets/img/3.png', function(texture) {
    console.log('Logo cargado correctamente');
}, undefined, function(error) {
    console.error('Error cargando el logo:', error);
});

// Top circle with logo
const circleGeometry = new THREE.CircleGeometry(2, 64);
const topCircleMaterial = new THREE.MeshBasicMaterial({
    map: logoTexture,
    transparent: true
});
const topCircle = new THREE.Mesh(circleGeometry, topCircleMaterial);
topCircle.position.y = 0.21;
topCircle.rotation.x = -Math.PI / 2;
topCircle.castShadow = true;
coinGroup.add(topCircle);

// Bottom circle with logo
const bottomCircleMaterial = new THREE.MeshBasicMaterial({
    map: logoTexture,
    transparent: true
});
const bottomCircle = new THREE.Mesh(circleGeometry, bottomCircleMaterial);
bottomCircle.position.y = -0.21;
bottomCircle.rotation.x = Math.PI / 2;
bottomCircle.rotation.z = Math.PI;
bottomCircle.castShadow = true;
coinGroup.add(bottomCircle);

// Edge ring
const torusGeometry = new THREE.TorusGeometry(2.05, 0.08, 16, 100);
const torusMaterial = new THREE.MeshStandardMaterial({
    color: 0x2B7A9B,
    metalness: 0.98,
    roughness: 0.05
});
const torus = new THREE.Mesh(torusGeometry, torusMaterial);
torus.rotation.x = Math.PI / 2;
coinGroup.add(torus);

scene.add(coinGroup);

// Mouse interaction variables
let isDragging = false;
let previousMousePosition = { x: 0, y: 0 };
let rotationVelocity = { x: 0, y: 0 };
let autoRotate = true;

// Mouse events
container.addEventListener('mousedown', (e) => {
    isDragging = true;
    autoRotate = false;
    previousMousePosition = { x: e.clientX, y: e.clientY };
});

document.addEventListener('mousemove', (e) => {
    if (!isDragging) return;
    const deltaX = e.clientX - previousMousePosition.x;
    const deltaY = e.clientY - previousMousePosition.y;
    coinGroup.rotation.z += deltaX * 0.01;
    coinGroup.rotation.y += deltaY * 0.01;
    rotationVelocity = { x: deltaX * 0.01, y: deltaY * 0.01 };
    previousMousePosition = { x: e.clientX, y: e.clientY };
});

document.addEventListener('mouseup', () => {
    isDragging = false;
});

// Touch events for mobile
container.addEventListener('touchstart', (e) => {
    isDragging = true;
    autoRotate = false;
    const touch = e.touches[0];
    previousMousePosition = { x: touch.clientX, y: touch.clientY };
});

container.addEventListener('touchmove', (e) => {
    if (!isDragging) return;
    e.preventDefault();
    const touch = e.touches[0];
    const deltaX = touch.clientX - previousMousePosition.x;
    const deltaY = touch.clientY - previousMousePosition.y;
    coinGroup.rotation.z += deltaX * 0.01;
    coinGroup.rotation.y += deltaY * 0.01;
    rotationVelocity = { x: deltaX * 0.01, y: deltaY * 0.01 };
    previousMousePosition = { x: touch.clientX, y: touch.clientY };
});

container.addEventListener('touchend', () => {
    isDragging = false;
});

// Animation loop
function animate() {
    requestAnimationFrame(animate);

    if (autoRotate) {
        const time = Date.now() * 0.001;
        coinGroup.rotation.z = time * 0.2;
        coinGroup.rotation.y = Math.sin(time * 0.15) * 0.05;
    } else {
        rotationVelocity.x *= 0.95;
        rotationVelocity.y *= 0.95;
        coinGroup.rotation.z += rotationVelocity.x;
        coinGroup.rotation.y += rotationVelocity.y;
        if (Math.abs(rotationVelocity.x) < 0.001 && Math.abs(rotationVelocity.y) < 0.001) {
            autoRotate = true;
        }
    }

    renderer.render(scene, camera);
}
animate();

// Responsive canvas
function handleResize() {
    if (window.innerWidth >= 1024) {
        const size = Math.min(600, window.innerHeight * 0.8);
        renderer.setSize(size, size);
        camera.aspect = 1;
        camera.updateProjectionMatrix();
    }
}
window.addEventListener('resize', handleResize);
handleResize();


/* ── MODAL + SPINNER ────────────────────────────────────────── */
(function () {
    'use strict';

    function crearModal(type, titulo, mensaje) {
        const existing = document.getElementById('login-modal');
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

        const extraHTML = isSuccess
            ? `<div class="modal-redirect-info">
                   <svg class="modal-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                       <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                   </svg>
                   <span>Redirigiendo al dashboard…</span>
               </div>`
            : '';

        const botonesHTML = isSuccess
            ? ''
            : `<div class="modal-actions">
                   <button class="modal-btn modal-btn--ghost"   id="modal-cerrar">Cancelar</button>
                   <button class="modal-btn modal-btn--primary" id="modal-reintentar">Intentar de nuevo</button>
               </div>`;

        document.body.insertAdjacentHTML('beforeend', `
        <div class="modal-overlay" id="login-modal" role="dialog" aria-modal="true" aria-labelledby="modal-titulo">
            <div class="modal-box modal-box--${type}">
                <div class="modal-icon modal-icon--${type}">${iconSVG}</div>
                <h2 class="modal-title" id="modal-titulo">${titulo}</h2>
                <p class="modal-msg">${mensaje}</p>
                ${extraHTML}
                ${botonesHTML}
            </div>
        </div>`);

        // Animación de entrada
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                const modal = document.getElementById('login-modal');
                if (modal) modal.classList.add('modal-visible');
            });
        });

        const btnCerrar     = document.getElementById('modal-cerrar');
        const btnReintentar = document.getElementById('modal-reintentar');

        if (btnCerrar) {
            btnCerrar.addEventListener('click', cerrarModal);
        }

        if (btnReintentar) {
            btnReintentar.addEventListener('click', () => {
                cerrarModal();
                setTimeout(() => {
                    const emailField = document.getElementById('email');
                    if (emailField) emailField.focus();
                }, 350);
            });
        }

        // Cerrar con clic fuera o Escape (solo en error)
        if (!isSuccess) {
            document.getElementById('login-modal').addEventListener('click', function (e) {
                if (e.target === this) cerrarModal();
            });
            document.addEventListener('keydown', onEscape);
        }
    }

    function cerrarModal() {
        const modal = document.getElementById('login-modal');
        if (!modal) return;
        modal.classList.remove('modal-visible');
        document.removeEventListener('keydown', onEscape);
        modal.addEventListener('transitionend', () => modal.remove(), { once: true });
    }

    function onEscape(e) {
        if (e.key === 'Escape') cerrarModal();
    }

    document.addEventListener('DOMContentLoaded', function () {
        const body        = document.body;
        const loginStatus = body.dataset.loginStatus   || '';
        const loginMsg    = body.dataset.loginMsg      || '';
        const redirectUrl = body.dataset.loginRedirect || '';

        if (loginStatus === 'error') {
            crearModal(
                'error',
                'Acceso denegado',
                loginMsg || 'Las credenciales ingresadas son incorrectas. Por favor verifica tu correo y contraseña.'
            );
        } else if (loginStatus === 'success') {
            crearModal(
                'success',
                '¡Bienvenido de nuevo!',
                loginMsg || 'Autenticación exitosa. Serás redirigido en un momento…'
            );
            if (redirectUrl) {
                setTimeout(() => { window.location.href = redirectUrl; }, 2200);
            }
        }

        // Spinner en el botón al hacer submit
        const form      = document.getElementById('loginForm');
        const btnSubmit = form ? form.querySelector('.btn-primary') : null;

        if (form && btnSubmit) {
            form.addEventListener('submit', function () {
                const email    = document.getElementById('email');
                const password = document.getElementById('password');
                if (!email?.value.trim() || !password?.value.trim()) return;
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = `<span class="btn-spinner"></span> Verificando…`;
            });
        }
    });

})();