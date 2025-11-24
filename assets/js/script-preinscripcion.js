// Validación y envío del formulario de preinscripción
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('preregistrationForm');
    
    if (!form) return;
    
    // Validación en tiempo real
    const inputs = form.querySelectorAll('input, select, textarea');
    
    inputs.forEach(input => {
        input.addEventListener('blur', function() {
            validateField(this);
        });
        
        input.addEventListener('input', function() {
            if (this.classList.contains('error')) {
                validateField(this);
            }
        });
    });
    
    // Envío del formulario
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // Validar todos los campos
        let isValid = true;
        inputs.forEach(input => {
            if (!validateField(input)) {
                isValid = false;
            }
        });
        
        if (!isValid) {
            showMessage('Por favor, corrige los errores en el formulario', 'error');
            return;
        }
        
        // Deshabilitar botón y mostrar loading
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Enviando...';
        
        try {
            const formData = new FormData(form);
            
            const response = await fetch('../includes/procesar_preinscripcion.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                showMessage(data.message, 'success');
                form.reset();
                
                // Redirigir después de 2 segundos
                setTimeout(() => {
                    window.location.href = '../public/index.html';
                }, 2000);
            } else {
                showMessage(data.message, 'error');
            }
            
        } catch (error) {
            console.error('Error:', error);
            showMessage('Error al enviar el formulario. Por favor, intenta nuevamente.', 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    });
    
    // Función para validar campos individuales
    function validateField(field) {
        const value = field.value.trim();
        const fieldName = field.name;
        let isValid = true;
        let errorMessage = '';
        
        // Limpiar error previo
        removeError(field);
        
        // Validaciones específicas
        switch(fieldName) {
            case 'nombre':
                if (value.length < 3) {
                    errorMessage = 'El nombre debe tener al menos 3 caracteres';
                    isValid = false;
                } else if (!/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/.test(value)) {
                    errorMessage = 'El nombre solo puede contener letras';
                    isValid = false;
                }
                break;
                
            case 'email':
                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailPattern.test(value)) {
                    errorMessage = 'Email inválido';
                    isValid = false;
                }
                break;
                
            case 'telefono':
                if (!/^[0-9]{7,15}$/.test(value)) {
                    errorMessage = 'Teléfono inválido (7-15 dígitos)';
                    isValid = false;
                }
                break;
                
            case 'documento':
                if (!/^[0-9]{6,15}$/.test(value)) {
                    errorMessage = 'Documento inválido (6-15 dígitos)';
                    isValid = false;
                }
                break;
                
            case 'curso':
                if (value === '') {
                    errorMessage = 'Debe seleccionar un curso';
                    isValid = false;
                }
                break;
                
            case 'terminos':
                if (!field.checked) {
                    errorMessage = 'Debe aceptar los términos';
                    isValid = false;
                }
                break;
        }
        
        if (!isValid) {
            showFieldError(field, errorMessage);
        }
        
        return isValid;
    }
    
    // Función para mostrar error en campo
    function showFieldError(field, message) {
        field.classList.add('error');
        
        let errorDiv = field.parentElement.querySelector('.error-message');
        if (!errorDiv) {
            errorDiv = document.createElement('div');
            errorDiv.className = 'error-message';
            field.parentElement.appendChild(errorDiv);
        }
        errorDiv.textContent = message;
    }
    
    // Función para remover error
    function removeError(field) {
        field.classList.remove('error');
        const errorDiv = field.parentElement.querySelector('.error-message');
        if (errorDiv) {
            errorDiv.remove();
        }
    }
    
    // Función para mostrar mensajes generales
    function showMessage(message, type) {
        // Remover mensaje anterior si existe
        const existingAlert = document.querySelector('.alert-message');
        if (existingAlert) {
            existingAlert.remove();
        }
        
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert-message alert-${type}`;
        alertDiv.innerHTML = `
            <span>${message}</span>
            <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; font-size: 1.2em; cursor: pointer;">&times;</button>
        `;
        
        const container = document.querySelector('.container');
        container.insertBefore(alertDiv, container.firstChild);
        
        // Auto-remover después de 5 segundos
        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }
    
    // Formatear teléfono mientras escribe
    const telefonoInput = document.getElementById('telefono');
    if (telefonoInput) {
        telefonoInput.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    }
    
    // Formatear documento mientras escribe
    const documentoInput = document.getElementById('documento');
    if (documentoInput) {
        documentoInput.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    }
});

// Agregar estilos CSS para los errores
const style = document.createElement('style');
style.textContent = `
    .error {
        border-color: #ff6d00 !important;
        box-shadow: 0 0 0 3px rgba(255, 109, 0, 0.1) !important;
    }
    
    .error-message {
        color: #ff6d00;
        font-size: 0.85em;
        margin-top: 4px;
        display: block;
    }
    
    .alert-message {
        padding: 15px 20px;
        margin-bottom: 20px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        animation: slideDown 0.3s ease;
    }
    
    .alert-success {
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
    }
    
    .alert-error {
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }
    
    @keyframes slideDown {
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