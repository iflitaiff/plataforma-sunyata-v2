/**
 * Canvas Stream — SSE handler for streaming Claude responses.
 *
 * Flow:
 * 1. POST form data to /api/canvas/submit.php?mode=stream
 * 2. PHP validates & returns { stream_url, params, internal_key }
 * 3. JS POSTs to stream_url with params
 * 4. SSE events arrive: {type:"token",text:"..."} and {type:"done",result:{...}}
 * 5. Tokens rendered incrementally via marked.js
 *
 * Falls back to sync mode if streaming unavailable.
 */

class CanvasStream {
    constructor(options = {}) {
        this.resultContainer = options.resultContainer || document.getElementById('stream-result');
        this.statusContainer = options.statusContainer || document.getElementById('stream-status');
        this.actionsContainer = options.actionsContainer || document.getElementById('stream-actions');
        this.onComplete = options.onComplete || null;
        this.onError = options.onError || null;

        this.fullText = '';
        this.isStreaming = false;
        this.abortController = null;
    }

    /**
     * Submit form and start streaming response.
     * @param {string} submitUrl - The /api/canvas/submit.php URL
     * @param {Object} formPayload - { canvas_id, form_data, plain_data }
     */
    async start(submitUrl, formPayload) {
        this.reset();
        this.isStreaming = true;
        this.showStatus('Processando formulario...');

        try {
            // Step 1: POST to PHP for validation + stream params
            const initResponse = await fetch(submitUrl + '?mode=stream', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formPayload),
            });

            const initData = await initResponse.json();

            if (!initData.success) {
                throw new Error(initData.error || 'Erro ao processar formulario');
            }

            // If PHP returned a sync response (no stream_url), render directly
            if (!initData.stream_url) {
                this.renderSync(initData);
                return;
            }

            // Step 2: Connect to SSE stream
            this.showStatus('Gerando resposta...');
            this.showCursor();

            this.abortController = new AbortController();

            const streamResponse = await fetch(initData.stream_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Internal-Key': initData.internal_key,
                },
                body: JSON.stringify(initData.params),
                signal: this.abortController.signal,
            });

            const reader = streamResponse.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop(); // Keep incomplete line in buffer

                for (const line of lines) {
                    if (line.startsWith('data: ')) {
                        const jsonStr = line.substring(6);
                        try {
                            const event = JSON.parse(jsonStr);
                            this.handleEvent(event);
                        } catch (e) {
                            // Skip malformed JSON
                        }
                    }
                }
            }

            // Process remaining buffer
            if (buffer.startsWith('data: ')) {
                try {
                    const event = JSON.parse(buffer.substring(6));
                    this.handleEvent(event);
                } catch (e) { /* skip */ }
            }

        } catch (error) {
            if (error.name === 'AbortError') {
                this.showStatus('Cancelado pelo usuario.');
                return;
            }
            this.handleError(error);
        } finally {
            this.isStreaming = false;
        }
    }

    /**
     * Handle a single SSE event.
     */
    handleEvent(event) {
        switch (event.type) {
            case 'token':
                this.fullText += event.text;
                this.renderMarkdown();
                break;

            case 'done':
                this.hideCursor();
                this.renderMarkdown();
                this.showActions(event.result);
                this.hideStatus();
                if (this.onComplete) this.onComplete(event.result);
                break;

            case 'error':
                this.handleError(new Error(event.error));
                break;
        }
    }

    /**
     * Render sync (non-streaming) response.
     */
    renderSync(data) {
        this.fullText = data.response || '';
        this.renderMarkdown();
        this.hideStatus();
        this.showActions({
            response: this.fullText,
            usage: data.tokens,
            cost_usd: data.cost_usd,
        });
        if (this.onComplete) this.onComplete(data);
    }

    /**
     * Render current fullText as markdown.
     */
    renderMarkdown() {
        if (this.resultContainer && typeof marked !== 'undefined') {
            this.resultContainer.innerHTML = marked.parse(this.fullText);
        }
    }

    /**
     * Show streaming cursor.
     */
    showCursor() {
        if (this.resultContainer) {
            this.resultContainer.classList.add('streaming');
            const cursor = document.createElement('span');
            cursor.className = 'cursor';
            cursor.id = 'stream-cursor';
            this.resultContainer.appendChild(cursor);
        }
    }

    hideCursor() {
        const cursor = document.getElementById('stream-cursor');
        if (cursor) cursor.remove();
        if (this.resultContainer) {
            this.resultContainer.classList.remove('streaming');
        }
    }

    showStatus(text) {
        if (this.statusContainer) {
            this.statusContainer.textContent = text;
            this.statusContainer.style.display = 'block';
        }
    }

    hideStatus() {
        if (this.statusContainer) {
            this.statusContainer.style.display = 'none';
        }
    }

    /**
     * Show export/action buttons after completion.
     */
    showActions(result) {
        if (!this.actionsContainer) return;

        const tokens = result?.usage || {};
        const cost = result?.cost_usd || 0;

        this.actionsContainer.innerHTML = `
            <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
                <button class="btn btn-primary btn-sm" onclick="canvasStream.copyResult()">
                    <i class="ti ti-copy me-1"></i> Copiar
                </button>
                <button class="btn btn-outline-primary btn-sm" onclick="canvasStream.exportPdf()">
                    <i class="ti ti-file-export me-1"></i> Exportar PDF
                </button>
                <span class="text-secondary ms-auto small">
                    ${tokens.input_tokens || 0} + ${tokens.output_tokens || 0} tokens |
                    $${cost.toFixed(4)}
                </span>
            </div>
        `;
        this.actionsContainer.style.display = 'block';
    }

    /**
     * Copy result to clipboard.
     */
    async copyResult() {
        try {
            await navigator.clipboard.writeText(this.fullText);
            this.showToast('Copiado para a area de transferencia!');
        } catch {
            // Fallback
            const ta = document.createElement('textarea');
            ta.value = this.fullText;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
            this.showToast('Copiado!');
        }
    }

    /**
     * Export result as PDF (delegates to server).
     */
    exportPdf() {
        // Open a new window with the result for printing
        const printWin = window.open('', '_blank');
        if (printWin) {
            printWin.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Resultado - Sunyata</title>
                    <style>body { font-family: Georgia, serif; max-width: 800px; margin: 2rem auto; line-height: 1.6; }</style>
                </head>
                <body>${marked.parse(this.fullText)}</body>
                </html>
            `);
            printWin.document.close();
            printWin.print();
        }
    }

    showToast(message) {
        const container = document.querySelector('.toast-container') || document.body;
        const toast = document.createElement('div');
        toast.className = 'toast show align-items-center text-bg-success border-0';
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        container.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }

    /**
     * Cancel an in-progress stream.
     */
    cancel() {
        if (this.abortController) {
            this.abortController.abort();
            this.abortController = null;
        }
        this.isStreaming = false;
    }

    /**
     * Reset state for new submission.
     */
    reset() {
        this.fullText = '';
        this.isStreaming = false;
        this.abortController = null;
        if (this.resultContainer) this.resultContainer.innerHTML = '';
        if (this.actionsContainer) {
            this.actionsContainer.innerHTML = '';
            this.actionsContainer.style.display = 'none';
        }
        this.hideStatus();
    }

    handleError(error) {
        this.hideCursor();
        this.hideStatus();
        const msg = error.message || 'Erro desconhecido';

        if (this.resultContainer) {
            this.resultContainer.innerHTML = `
                <div class="alert alert-danger">
                    <i class="ti ti-alert-circle me-2"></i>
                    <strong>Erro:</strong> ${msg}
                </div>
            `;
        }

        if (this.onError) this.onError(error);
    }
}

// Global instance
let canvasStream = null;
