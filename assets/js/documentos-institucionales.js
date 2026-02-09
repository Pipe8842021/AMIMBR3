// Documentos Institucionales - JavaScript

document.addEventListener('DOMContentLoaded', function() {
    
    // Elementos del DOM
    const searchInput = document.getElementById('searchInput');
    const filterTipo = document.getElementById('filterTipo');
    const filterCategoria = document.getElementById('filterCategoria');
    const viewBtns = document.querySelectorAll('.view-btn');
    const documentsContainer = document.getElementById('documentsContainer');
    const btnNuevoArchivo = document.getElementById('btnNuevoArchivo');
    const btnNuevaBitacora = document.getElementById('btnNuevaBitacora');

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

    // Cambio de vista (grid/list)
    viewBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            viewBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            const view = this.dataset.view;
            if (view === 'list') {
                documentsContainer.style.gridTemplateColumns = '1fr';
            } else {
                documentsContainer.style.gridTemplateColumns = 'repeat(auto-fill, minmax(340px, 1fr))';
            }
        });
    });

    // Botón nuevo archivo
    if (btnNuevoArchivo) {
        btnNuevoArchivo.addEventListener('click', function() {
            window.location.href = 'crear.php';
        });
    }

    // Botón nueva bitácora
    if (btnNuevaBitacora) {
        btnNuevaBitacora.addEventListener('click', function() {
            window.location.href = '../bitacoras/crear.php';
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
});

// Función para aplicar filtros
function aplicarFiltros() {
    const busqueda = document.getElementById('searchInput').value;
    const tipo = document.getElementById('filterTipo').value;
    const categoria = document.getElementById('filterCategoria').value;

    const params = new URLSearchParams();
    if (busqueda) params.append('busqueda', busqueda);
    if (tipo) params.append('tipo', tipo);
    if (categoria) params.append('categoria', categoria);

    window.location.href = `index.php?${params.toString()}`;
}

// Función para ver documento
function verDocumento(id) {
    window.location.href = `ver.php?id=${id}`;
}

// Función para eliminar documento
function eliminarDocumento(id) {
    if (confirm('¿Estás seguro de eliminar este documento?')) {
        fetch(`eliminar.php?id=${id}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Documento eliminado correctamente');
                location.reload();
            } else {
                alert('Error al eliminar el documento');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al eliminar el documento');
        });
    }
}

// Función para mostrar menú de acciones
function mostrarMenuAcciones(btn, docId) {
    // Aquí puedes implementar un menú contextual
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
        <button onclick="descargarDocumento(${docId})">
            <span class="material-symbols-rounded">download</span>
            Descargar
        </button>
        <button onclick="eliminarDocumento(${docId})" style="color: #dc2626;">
            <span class="material-symbols-rounded">delete</span>
            Eliminar
        </button>
    `;
    
    // Posicionar y mostrar menú
    const rect = btn.getBoundingClientRect();
    menu.style.position = 'fixed';
    menu.style.top = `${rect.bottom + 5}px`;
    menu.style.left = `${rect.left}px`;
    
    document.body.appendChild(menu);
    
    // Cerrar menú al hacer clic fuera
    setTimeout(() => {
        document.addEventListener('click', function closeMenu() {
            menu.remove();
            document.removeEventListener('click', closeMenu);
        });
    }, 100);
}

// Función para editar documento
function editarDocumento(id) {
    window.location.href = `editar.php?id=${id}`;
}

// Función para descargar documento
function descargarDocumento(id) {
    window.location.href = `descargar.php?id=${id}`;
}