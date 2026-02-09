// ============================================
// HELPER PARA LLAMADAS A LA API
// ============================================

class API {
    static BASE_URL = '/api'; // Ajustar según tu configuración

    static async request(method, endpoint, data = null, params = null) {
        try {
            let url = `${this.BASE_URL}${endpoint}`;
            
            if (params) {
                const queryString = new URLSearchParams(params).toString();
                url += `?${queryString}`;
            }

            const options = {
                method: method,
                headers: {
                    'Content-Type': 'application/json'
                }
            };

            if (data && (method === 'POST' || method === 'PUT')) {
                options.body = JSON.stringify(data);
            }

            const response = await fetch(url, options);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            console.error('API Request Error:', error);
            throw error;
        }
    }

    static get(endpoint, params = null) {
        return this.request('GET', endpoint, null, params);
    }

    static post(endpoint, data) {
        return this.request('POST', endpoint, data);
    }

    static put(endpoint, data = null) {
        return this.request('PUT', endpoint, data);
    }

    static delete(endpoint) {
        return this.request('DELETE', endpoint);
    }
}