/* The view owns optimistic DOM changes. This client owns confirmed server state. */
(function (root) {
    class GetmeClient {
        constructor(url, snapshot) {
            this.url = url;
            this.snapshot = snapshot;
        }
        async request(body) {
            // A retry reuses the exact operation IDs and base revision.
            for (let attempt = 0; attempt < 2; attempt++) {
                const controller = new AbortController();
                const timeout = setTimeout(() => controller.abort(), 15000);
                try {
                    const response = await fetch(this.url, {
                        method: 'POST', signal: controller.signal, headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ version: 1, ...body })
                    });
                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        const error = new Error(data.error || 'Request failed');
                        error.definitive = response.status >= 400 && response.status < 500 && response.status !== 429;
                        error.code = data.code;
                        throw error;
                    }
                    if (data.version !== 1) throw Object.assign(new Error('Update the getme server'), { definitive: true });
                    return data;
                } catch (error) {
                    if (error.definitive || attempt === 1) throw error;
                } finally {
                    clearTimeout(timeout);
                }
            }
        }
        async action(action, payload = {}) {
            if (action === 'fetch') {
                const data = await this.request({ action: 'read', revision: this.snapshot.revision });
                if (data.revision >= this.snapshot.revision) this.snapshot = data;
                return data;
            }
            const id = payload.request_id || (root.crypto && root.crypto.randomUUID ? root.crypto.randomUUID() : Date.now() + '-' + Math.random().toString(36).slice(2));
            const op = { id, type: action };
            if (action === 'add') op.name = payload.name;
            if (action === 'edit') Object.assign(op, { item_id: Number(payload.id), name: payload.name });
            if (action === 'toggle') Object.assign(op, { type: 'set_checked', item_id: Number(payload.id), checked: Number(payload.checked) });
            if (action === 'delete') op.item_id = Number(payload.id);
            if (action === 'reorder') op.item_ids = payload.items.map(Number);
            if (action === 'clear_checked' || action === 'clear_all') {
                op.type = 'delete_items';
                op.item_ids = this.snapshot.items.filter(item => action === 'clear_all' || Number(item.checked) === 1).map(item => item.id);
            }
            try {
                const data = await this.request({ action: 'apply', revision: this.snapshot.revision, operations: [op] });
                if (data.revision >= this.snapshot.revision) this.snapshot = data;
                const result = data.results.find(result => result.id === id);
                return { ...data, item: result && data.items.find(item => item.id === result.item_id) };
            } catch (error) {
                // Refresh the baseline after rejection, but never silently replay a conflicting edit.
                if (error.code === 'conflict') {
                    this.snapshot = await this.request({ action: 'read' });
                }
                throw error;
            }
        }
    }
    root.GetmeClient = GetmeClient;
    if (typeof module !== 'undefined') module.exports = GetmeClient;
})(typeof window === 'undefined' ? globalThis : window);
