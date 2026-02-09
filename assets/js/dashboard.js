// ============================================
// GESTOR DE DASHBOARD
// ============================================

class DashboardManager {
    constructor() {
        this.charts = {};
        this.init();
    }

    init() {
        this.refreshBtn = document.getElementById('refreshBtn');
        this.enrollmentMonthsSelect = document.getElementById('enrollmentMonths');
        this.preinscriptionMonthsSelect = document.getElementById('preinscriptionMonths');
        
        this.refreshBtn.addEventListener('click', () => this.loadAllData());
        this.enrollmentMonthsSelect.addEventListener('change', (e) => {
            this.loadEnrollmentChart(e.target.value);
        });
        this.preinscriptionMonthsSelect.addEventListener('change', (e) => {
            this.loadPreinscriptionChart(e.target.value);
        });
        
        this.loadAllData();
    }

    async loadAllData() {
        this.setLoading(true);
        
        try {
            await Promise.all([
                this.loadMetrics(),
                this.loadEnrollmentChart(this.enrollmentMonthsSelect.value),
                this.loadPreinscriptionChart(this.preinscriptionMonthsSelect.value),
                this.loadTopCourses()
            ]);
        } catch (error) {
            console.error('Error loading dashboard data:', error);
            alert('Error al cargar los datos del dashboard');
        } finally {
            this.setLoading(false);
        }
    }

    setLoading(isLoading) {
        if (isLoading) {
            this.refreshBtn.classList.add('loading');
            this.refreshBtn.disabled = true;
        } else {
            this.refreshBtn.classList.remove('loading');
            this.refreshBtn.disabled = false;
        }
    }

    async loadMetrics() {
        try {
            const response = await API.get('/reports/dashboard');
            if (response.success) {
                const data = response.data;
                
                document.getElementById('totalEstudiantes').textContent = data.estudiantes;
                document.getElementById('totalMatriculas').textContent = data.matriculas;
                document.getElementById('totalPendientes').textContent = data.preinscripciones.pendientes;
                document.getElementById('totalPreinscripciones').textContent = 'Total: ' + data.preinscripciones.total;
                document.getElementById('tasaConversion').textContent = data.conversion_rate.tasa_conversion + '%';
                document.getElementById('conversionDetalle').textContent = 
                    data.conversion_rate.matriculadas + ' de ' + data.conversion_rate.total;
                
                document.getElementById('statusPendientes').textContent = data.preinscripciones.pendientes;
                document.getElementById('statusContactadas').textContent = data.preinscripciones.contactadas;
                document.getElementById('statusMatriculadas').textContent = data.preinscripciones.matriculadas;
                document.getElementById('statusRechazadas').textContent = data.preinscripciones.rechazadas;
                
                document.getElementById('totalPagos').textContent = data.pagos_mes_actual.total_pagos;
                document.getElementById('montoPagado').textContent = this.formatCurrency(data.pagos_mes_actual.monto_pagado);
                document.getElementById('montoPendiente').textContent = this.formatCurrency(data.pagos_mes_actual.monto_pendiente);
                document.getElementById('montoVencido').textContent = this.formatCurrency(data.pagos_mes_actual.monto_vencido);
            }
        } catch (error) {
            console.error('Error loading metrics:', error);
        }
    }

    async loadEnrollmentChart(months = 6) {
        try {
            const response = await API.get(`/reports/enrollments-chart?months=${months}`);
            if (response.success) {
                const data = response.data;
                
                const labels = data.map(item => item.mes_nombre);
                const values = data.map(item => item.total_matriculas);
                
                this.renderLineChart('enrollmentChart', {
                    labels: labels,
                    datasets: [{
                        label: 'Matrículas',
                        data: values,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                });
            }
        } catch (error) {
            console.error('Error loading enrollment chart:', error);
        }
    }

    async loadPreinscriptionChart(months = 6) {
        try {
            const response = await API.get(`/reports/preinscriptions-chart?months=${months}`);
            if (response.success) {
                const data = response.data;
                
                const labels = data.map(item => item.mes_nombre);
                const total = data.map(item => item.total_preinscripciones);
                const convertidas = data.map(item => item.convertidas);
                
                this.renderBarChart('preinscriptionChart', {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Total',
                            data: total,
                            backgroundColor: '#fbbf24'
                        },
                        {
                            label: 'Convertidas',
                            data: convertidas,
                            backgroundColor: '#10b981'
                        }
                    ]
                });
            }
        } catch (error) {
            console.error('Error loading preinscription chart:', error);
        }
    }

    async loadTopCourses() {
        try {
            const response = await API.get('/reports/top-courses?limit=5');
            if (response.success) {
                const data = response.data;
                const tbody = document.getElementById('topCoursesTable');
                
                if (data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="3" class="text-center">No hay datos disponibles</td></tr>';
                    return;
                }
                
                tbody.innerHTML = data.map((course, index) => `
                    <tr>
                        <td>${index + 1}</td>
                        <td><strong>${course.curso}</strong></td>
                        <td class="text-right">${course.total_matriculas}</td>
                    </tr>
                `).join('');
            }
        } catch (error) {
            console.error('Error loading top courses:', error);
        }
    }

    renderLineChart(canvasId, data) {
        const ctx = document.getElementById(canvasId).getContext('2d');
        
        if (this.charts[canvasId]) {
            this.charts[canvasId].destroy();
        }
        
        this.charts[canvasId] = new Chart(ctx, {
            type: 'line',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    }

    renderBarChart(canvasId, data) {
        const ctx = document.getElementById(canvasId).getContext('2d');
        
        if (this.charts[canvasId]) {
            this.charts[canvasId].destroy();
        }
        
        this.charts[canvasId] = new Chart(ctx, {
            type: 'bar',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    }

    formatCurrency(amount) {
        return '$' + Number(amount).toLocaleString('es-CO');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    window.dashboardManager = new DashboardManager();
});