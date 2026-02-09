// Documentos Administrativos - JavaScript

document.addEventListener('DOMContentLoaded', function() {
    
    // Elementos del DOM
    const searchInput = document.getElementById('searchInput');
    const filterTipo = document.getElementById('filterTipo');
    const filterCategoria = document.getElementById('filterCategoria');
    const filterPermisos = document.getElementById('filterPermisos');
    const viewBtns = document.querySelectorAll('.view-btn');
    const documentsContainer = document.getElementById('documentsContainer');
    const btnNuevoArchivo = document.getElementById('btnNuevoArchivo');

    // Búsqueda en tiempo real
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                aplicarFiltros();
            }, 500);
        });
    }

    // Filtros
    if (filterTipo) {
        filterTipo.addEventListener('change', aplicarFiltros);
    }

    if (filterCategoria) {
        filterCategoria.addEventListener('change', aplicarFiltros);
    }

    if (filterPermisos) {
        filterPermisos.addEventListener('change', aplicarFiltros);
    }

    // Cambio de vista (grid/list)
    viewBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            viewBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            const view = this.dataset.view;
            if (view === 'list') {
                documentsContainer.style.gridTemplateColumns = '1fr';
            } else {
                documentsContainer.style.gridTemplateColumns = 'repeat(auto-fill, minmax(320px, 1fr))';
            }
        });
    });

    // Botón nuevo archivo
    if (btnNuevoArchivo) {
        btnNuevoArchivo.addEventListener('click', function() {
            window.location.href = 'crear.php';
        });
    }

    // Menú de acciones de cada documento
    const actionBtns = document.querySelectorAll('.action-btn');
    actionBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const docId = this.dataset.id;
            mostrarMenuAcciones(this, docId);
        });
    });

    // Click en tarjeta para ver documento
    const documentCards = document.querySelectorAll('.document-card');
    documentCards.forEach(card => {
        card.addEventListener('click', function(e) {
            // No hacer nada si se clickeó un botón de acción
            if (e.target.closest('.action-btn') || e.target.closest('.card-actions')) {
                return;
            }
            // Aquí podrías abrir un modal o redirigir a la vista del documento
            console.log('Ver documento');
        });
    });
});

// Función para aplicar filtros
function aplicarFiltros() {
    const busqueda = document.getElementById('searchInput').value;
    const tipo = document.getElementById('filterTipo').value;
    const categoria = document.getElementById('filterCategoria').value;
    const permisos = document.getElementById('filterPermisos').value;

    const params = new URLSearchParams();
    if (busqueda) params.append('busqueda', busqueda);
    if (tipo) params.append('tipo', tipo);
    if (categoria) params.append('categoria', categoria);
    if (permisos) params.append('permisos', permisos);

    window.location.href = `index.php?${params.toString()}`;
}

// Función para ver documento
function verDocumento(id) {
    window.location.href = `ver.php?id=${id}`;
}

// Función para eliminar documento
function eliminarDocumento(id) {
    if (confirm('¿Estás seguro de eliminar este documento? Esta acción no se puede deshacer.')) {
        fetch(`eliminar.php?id=${id}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                mostrarNotificacion('Documento eliminado correctamente', 'success');
                location.reload();
            } else {
                mostrarNotificacion('Error al eliminar el documento', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            mostrarNotificacion('Error al eliminar el documento', 'error');
        });
    }
}

// Función para mostrar menú de acciones
function mostrarMenuAcciones(btn, docId) {
    // Remover menús existentes
    const existingMenus = document.querySelectorAll('.context-menu');
    existingMenus.forEach(menu => menu.remove());

    // Crear nuevo menú
    const menu = document.createElement('div');
    menu.className = 'context-menu';
    menu.innerHTML = `
        <button onclick="verDocumento(${docId})">
            <span class="material-symbols-rounded">visibility</span>
            Ver documento
        </button>
        <button onclick="editarDocumento(${docId})">
            <span class="material-symbols-rounded">edit</span>
            Editar
        </button>
        <button onclick="compartirDocumento(${docId})">
            <span class="material-symbols-rounded">share</span>
            Compartir
        </button>
        <button onclick="descargarDocumento(${docId})">
            <span class="material-symbols-rounded">download</span>
            Descargar
        </button>
        <hr>
        <button onclick="eliminarDocumento(${docId})" style="color: #dc2626;">
            <span class="material-symbols-rounded">delete</span>
            Eliminar
        </button>
    `;
    
    // Posicionar menú
    const rect = btn.getBoundingClientRect();
    menu.style.position = 'fixed';
    menu.style.top = `${rect.bottom + 5}px`;
    menu.style.left = `${rect.left - 150}px`; // Ajustar para que se vea completo
    menu.style.zIndex = '1000';
    
    document.body.appendChild(menu);
    
    // Cerrar menú al hacer clic fuera
    setTimeout(() => {
        document.addEventListener('click', function closeMenu(e) {
            if (!menu.contains(e.target)) {
                menu.remove();
                document.removeEventListener('click', closeMenu);
            }
        });
    }, 100);
}

// Función para editar documento
function editarDocumento(id) {
    window.location.href = `editar.php?id=${id}`;
}

// Función para compartir documento
function compartirDocumento(id) {
    // Aquí implementarías la lógica para compartir
    alert('Función de compartir documento en desarrollo');
}

// Función para descargar documento
function descargarDocumento(id) {
    window.location.href = `descargar.php?id=${id}`;
}

// Función para mostrar notificaciones
function mostrarNotificacion(mensaje, tipo = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${tipo}`;
    notification.innerHTML = `
        <span class="material-symbols-rounded">
            ${tipo === 'success' ? 'check_circle' : tipo === 'error' ? 'error' : 'info'}
        </span>
        <span>${mensaje}</span>
    `;
    
    document.body.appendChild(notification);
    
    // Mostrar notificación
    setTimeout(() => notification.classList.add('show'), 100);
    
    // Ocultar y remover después de 3 segundos
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Estilos para el menú contextual (agregar al CSS)
const style = document.createElement('style');
style.textContent = `
    .context-menu {
        background: var(--dark-bg);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 8px;
        box-shadow: var(--shadow-lg);
        min-width: 200px;
        animation: fadeIn 0.2s ease;
    }

    .context-menu button {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 12px;
        border: none;
        background: transparent;
        color: var(--text-primary);
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.9rem;
        transition: all 0.2s ease;
        text-align: left;
    }

    .context-menu button:hover {
        background: var(--hover-bg);
    }

    .context-menu hr {
        border: none;
        border-top: 1px solid var(--border-color);
        margin: 4px 0;
    }

    .notification {
        position: fixed;
        top: 20px;
        right: 20px;
        background: var(--dark-bg);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 16px 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        box-shadow: var(--shadow-lg);
        transform: translateX(400px);
        transition: transform 0.3s ease;
        z-index: 2000;
        min-width: 300px;
    }

    .notification.show {
        transform: translateX(0);
    }

    .notification-success {
        border-left: 4px solid var(--primary-green);
    }

    .notification-error {
        border-left: 4px solid #dc2626;
    }

    .notification-info {
        border-left: 4px solid var(--primary-blue);
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
`;
document.head.appendChild(style);