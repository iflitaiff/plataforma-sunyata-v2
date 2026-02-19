/**
 * Canvas SSE Client - Streaming Mode Integration
 *
 * Usage:
 *   import { CanvasSSEClient } from './canvas-sse-client.js';
 *
 *   const client = new CanvasSSEClient({
 *       onToken: (text) => console.log(text),
 *       onComplete: (result) => console.log(result),
 *       onError: (error) => console.error(error)
 *   });
 *
 *   await client.submit({
 *       vertical: 'iatr',
 *       template_id: 10,
 *       user_id: 5,
 *       data: formData
 *   });
 *
 * @version 1.0.0
 * @date 2026-02-18
 */

class CanvasSSEClient {
    /**
     * @param {Object} callbacks
     * @param {Function} callbacks.onToken - Called for each text chunk
     * @param {Function} callbacks.onDone - Called when LLM finishes (with metadata)
     * @param {Function} callbacks.onComplete - Called after save to DB (with history_id)
     * @param {Function} callbacks.onError - Called on error
     * @param {Function} callbacks.onProgress - Called with progress updates (optional)
     */
    constructor(callbacks = {}) {
        this.callbacks = {
            onToken: callbacks.onToken || (() => {}),
            onDone: callbacks.onDone || (() => {}),
            onComplete: callbacks.onComplete || (() => {}),
            onError: callbacks.onError || (() => {}),
            onProgress: callbacks.onProgress || (() => {}),
        };

        this.eventSource = null;
        this.fullText = '';
        this.result = null;
        this.historyId = null;
        this.aborted = false;
    }

    /**
     * Submit canvas form with streaming enabled
     *
     * @param {Object} payload
     * @param {string} payload.vertical - Vertical slug
     * @param {number} payload.template_id - Canvas template ID
     * @param {number} payload.user_id - User ID
     * @param {Object} payload.data - Form data (SurveyJS)
     * @param {string} baseUrl - Base URL (default: current origin)
     * @param {string} csrfToken - CSRF token
     * @param {string} internalKey - Internal API key
     * @returns {Promise<Object>} Final result with history_id
     */
    async submit(payload, baseUrl = '', csrfToken = '', internalKey = '') {
        this.reset();

        try {
            this.callbacks.onProgress({ stage: 'submitting', progress: 0 });

            // Step 1: Submit with stream=true
            const response = await fetch(`${baseUrl}/api/ai/canvas/submit`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                    'X-Internal-Key': internalKey,
                },
                body: JSON.stringify({
                    ...payload,
                    stream: true, // Enable streaming
                }),
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || `HTTP ${response.status}`);
            }

            const submitResult = await response.json();

            if (!submitResult.success) {
                throw new Error(submitResult.error || 'Submission failed');
            }

            this.callbacks.onProgress({ stage: 'connecting', progress: 10 });

            // Step 2: Connect to SSE stream
            const streamUrl = `${baseUrl}${submitResult.stream_url}`;

            // EventSource doesn't support custom headers, so append key to URL
            const streamUrlWithAuth = `${streamUrl}&X-Internal-Key=${encodeURIComponent(internalKey)}`;

            await this.connectStream(streamUrlWithAuth);

            return {
                success: true,
                response: this.fullText,
                result: this.result,
                history_id: this.historyId,
            };

        } catch (error) {
            this.callbacks.onError(error);
            throw error;
        }
    }

    /**
     * Connect to SSE stream and process events
     * @private
     */
    connectStream(url) {
        return new Promise((resolve, reject) => {
            this.eventSource = new EventSource(url);

            this.eventSource.onmessage = (event) => {
                if (this.aborted) {
                    this.eventSource.close();
                    reject(new Error('Aborted by user'));
                    return;
                }

                try {
                    const data = JSON.parse(event.data);

                    switch (data.type) {
                        case 'token':
                            // Real-time text chunk
                            this.fullText += data.text;
                            this.callbacks.onToken(data.text, this.fullText);
                            this.callbacks.onProgress({
                                stage: 'streaming',
                                progress: Math.min(90, 20 + this.fullText.length / 100)
                            });
                            break;

                        case 'done':
                            // Final result with metadata
                            this.result = data.result;
                            this.callbacks.onDone(data.result);
                            this.callbacks.onProgress({ stage: 'saving', progress: 95 });
                            break;

                        case 'complete':
                            // Save confirmed, history_id available
                            this.historyId = data.history_id;
                            this.callbacks.onComplete(data.history_id);
                            this.callbacks.onProgress({ stage: 'done', progress: 100 });
                            this.eventSource.close();
                            resolve({
                                fullText: this.fullText,
                                result: this.result,
                                historyId: this.historyId,
                            });
                            break;

                        case 'error':
                            // Server error during streaming
                            this.eventSource.close();
                            reject(new Error(data.error || 'Stream error'));
                            break;

                        default:
                            console.warn('Unknown SSE event type:', data.type);
                    }
                } catch (parseError) {
                    console.error('Error parsing SSE data:', parseError);
                }
            };

            this.eventSource.onerror = (error) => {
                this.eventSource.close();
                reject(new Error('SSE connection error'));
            };

            // Timeout after 5 minutes
            setTimeout(() => {
                if (this.eventSource && this.eventSource.readyState !== EventSource.CLOSED) {
                    this.eventSource.close();
                    reject(new Error('Stream timeout (5min)'));
                }
            }, 300000);
        });
    }

    /**
     * Abort ongoing stream
     */
    abort() {
        this.aborted = true;
        if (this.eventSource) {
            this.eventSource.close();
        }
    }

    /**
     * Reset internal state
     * @private
     */
    reset() {
        this.fullText = '';
        this.result = null;
        this.historyId = null;
        this.aborted = false;
    }

    /**
     * Check if browser supports EventSource
     */
    static isSupported() {
        return typeof EventSource !== 'undefined';
    }
}


// ========================================
// EXAMPLE USAGE IN FORMULARIO.PHP
// ========================================

/**
 * Example integration in canvas form
 */
function exampleIntegration() {
    // DOM elements
    const submitBtn = document.getElementById('submitBtn');
    const resultContainer = document.getElementById('resultContainer');
    const progressBar = document.getElementById('progressBar');
    const debugMetadata = document.getElementById('debugMetadata');

    // Configuration
    const BASE_URL = '<?= BASE_URL ?>';
    const CSRF_TOKEN = '<?= csrf_token() ?>';
    const INTERNAL_KEY = '<?= getenv("INTERNAL_API_KEY") ?>';
    const TEMPLATE_ID = <?= $canvas['id'] ?>;
    const USER_ID = <?= $_SESSION['user']['id'] ?? 0 ?>;

    // Create SSE client
    const sseClient = new CanvasSSEClient({
        onToken: (chunk, fullText) => {
            // Update UI in real-time
            resultContainer.innerHTML = marked.parse(fullText);
        },

        onDone: (result) => {
            // Show metadata
            debugMetadata.innerHTML = `
                <div class="debug-badge"><strong>Modelo:</strong> ${result.model}</div>
                <div class="debug-badge"><strong>Tokens:</strong> ${result.usage.total_tokens}</div>
                <div class="debug-badge"><strong>Custo:</strong> $${result.cost_usd.toFixed(4)}</div>
                <div class="debug-badge"><strong>Tempo:</strong> ${result.response_time_ms}ms</div>
            `;
        },

        onComplete: (historyId) => {
            console.log('Saved to history:', historyId);
            debugMetadata.innerHTML += `
                <div class="debug-badge"><strong>History ID:</strong> ${historyId}</div>
            `;
        },

        onError: (error) => {
            alert('Erro: ' + error.message);
            console.error(error);
        },

        onProgress: ({ stage, progress }) => {
            progressBar.style.width = `${progress}%`;
            progressBar.setAttribute('aria-valuenow', progress);
        },
    });

    // Submit handler
    submitBtn.addEventListener('click', async () => {
        // Validate form (SurveyJS)
        if (!survey.isCurrentPageHasErrors) {
            survey.nextPage();
            return;
        }

        if (!survey.completeLastPage()) {
            return;
        }

        const formData = survey.data;

        // Disable button
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="spinner-border spinner-border-sm"></i> Gerando...';

        try {
            // Check if streaming is supported
            if (!CanvasSSEClient.isSupported()) {
                console.warn('EventSource not supported, falling back to sync mode');
                await submitSync(formData); // Fallback
                return;
            }

            // Submit with streaming
            const result = await sseClient.submit(
                {
                    vertical: 'iatr',
                    template_id: TEMPLATE_ID,
                    user_id: USER_ID,
                    data: formData,
                },
                BASE_URL,
                CSRF_TOKEN,
                INTERNAL_KEY
            );

            console.log('Streaming completed:', result);

        } catch (error) {
            console.error('Streaming failed, falling back to sync:', error);

            // Fallback to sync mode on error
            await submitSync(formData);

        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Enviar Análise';
        }
    });

    // Abort button (optional)
    const abortBtn = document.getElementById('abortBtn');
    if (abortBtn) {
        abortBtn.addEventListener('click', () => {
            sseClient.abort();
            submitBtn.disabled = false;
        });
    }
}

/**
 * Fallback: Sync mode submission (existing code)
 */
async function submitSync(formData) {
    const response = await fetch(`${BASE_URL}/api/ai/canvas/submit`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': CSRF_TOKEN,
            'X-Internal-Key': INTERNAL_KEY,
        },
        body: JSON.stringify({
            vertical: 'iatr',
            template_id: TEMPLATE_ID,
            user_id: USER_ID,
            data: formData,
            stream: false, // Sync mode
        }),
    });

    const result = await response.json();

    if (!result.success) {
        throw new Error(result.error);
    }

    // Display result immediately
    document.getElementById('resultContainer').innerHTML = marked.parse(result.response);

    // Show metadata
    document.getElementById('debugMetadata').innerHTML = `
        <div class="debug-badge"><strong>Modelo:</strong> ${result.model}</div>
        <div class="debug-badge"><strong>History ID:</strong> ${result.history_id}</div>
    `;
}


// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { CanvasSSEClient };
}
