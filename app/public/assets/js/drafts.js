/**
 * DraftManager — Server-side draft management for SurveyJS forms
 *
 * Replaces localStorage auto-save with persistent server drafts.
 * Supports: auto-save (30s debounce), manual save, modal with list/load/rename/delete,
 * and one-time localStorage migration.
 */
class DraftManager {
    /**
     * @param {Object} opts
     * @param {number} opts.canvasTemplateId - Canvas template ID
     * @param {Object} opts.survey - SurveyJS Model instance
     * @param {string} opts.csrfToken - CSRF token
     * @param {string} opts.baseUrl - Base URL (e.g. /plataforma-sunyata)
     * @param {function} opts.onStatusChange - Callback for status text updates
     */
    constructor({ canvasTemplateId, survey, csrfToken, baseUrl, onStatusChange }) {
        this.canvasTemplateId = canvasTemplateId;
        this.survey = survey;
        this.csrfToken = csrfToken;
        this.baseUrl = baseUrl;
        this.onStatusChange = onStatusChange || (() => {});
        this.currentDraftId = null;
        this._autoSaveTimer = null;
        this._saving = false;
        this._modalEl = null;
    }

    /**
     * Schedule an auto-save with 30s debounce.
     */
    scheduleAutoSave() {
        if (this._autoSaveTimer) {
            clearTimeout(this._autoSaveTimer);
        }
        this._autoSaveTimer = setTimeout(() => this.saveDraft(), 30000);
    }

    /**
     * Save the current form state as a draft (create or update).
     */
    async saveDraft(label) {
        if (this._saving) return;
        this._saving = true;

        const data = this.survey.data;
        if (!data || Object.keys(data).length === 0) {
            this._saving = false;
            return;
        }

        this.onStatusChange('Salvando...');

        try {
            const body = {
                canvas_template_id: this.canvasTemplateId,
                form_data: data,
                page_no: this.survey.currentPageNo || 0,
            };

            if (this.currentDraftId) {
                body.draft_id = this.currentDraftId;
            }

            if (label) {
                body.label = label;
            }

            const resp = await fetch(`${this.baseUrl}/api/drafts/save.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken,
                },
                body: JSON.stringify(body),
            });

            const result = await resp.json();

            if (result.success) {
                this.currentDraftId = result.draft_id;
                const now = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
                this.onStatusChange(`Salvo as ${now}`);
            } else {
                this.onStatusChange('Erro ao salvar');
                console.error('Draft save error:', result.error);
            }
        } catch (err) {
            this.onStatusChange('Erro ao salvar');
            console.error('Draft save fetch error:', err);
            // Fallback: save to localStorage
            this._saveLocalFallback();
        } finally {
            this._saving = false;
        }
    }

    /**
     * Open the "Meus Rascunhos" modal with list of drafts.
     */
    async openDraftModal() {
        this.onStatusChange('Carregando rascunhos...');

        try {
            const resp = await fetch(
                `${this.baseUrl}/api/drafts/list.php?template_id=${this.canvasTemplateId}`
            );
            const result = await resp.json();

            if (!result.success) {
                this.onStatusChange('Erro ao carregar');
                return;
            }

            this.onStatusChange('');
            this._showModal(result.drafts, result.count);
        } catch (err) {
            this.onStatusChange('Erro de conexao');
            console.error('Draft list error:', err);
        }
    }

    /**
     * Load a draft into the survey.
     */
    async loadDraft(draftId) {
        try {
            const resp = await fetch(`${this.baseUrl}/api/drafts/load.php?id=${draftId}`);
            const result = await resp.json();

            if (!result.success) {
                alert(result.error || 'Erro ao carregar rascunho');
                return;
            }

            const draft = result.draft;
            this.survey.data = draft.form_data;
            if (draft.page_no !== undefined && draft.page_no > 0) {
                this.survey.currentPageNo = draft.page_no;
            }
            this.currentDraftId = draft.id;

            this._closeModal();
            this.onStatusChange(`Rascunho "${draft.label}" carregado`);

            // Show save button
            const saveBtn = document.getElementById('saveDraftBtn');
            if (saveBtn) saveBtn.style.display = 'inline-block';
        } catch (err) {
            console.error('Draft load error:', err);
            alert('Erro ao carregar rascunho');
        }
    }

    /**
     * Delete a draft.
     */
    async deleteDraft(draftId) {
        try {
            const resp = await fetch(`${this.baseUrl}/api/drafts/delete.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken,
                },
                body: JSON.stringify({ draft_id: draftId }),
            });

            const result = await resp.json();

            if (result.success) {
                if (this.currentDraftId === draftId) {
                    this.currentDraftId = null;
                }
                return true;
            }

            console.error('Draft delete error:', result.error);
            return false;
        } catch (err) {
            console.error('Draft delete fetch error:', err);
            return false;
        }
    }

    /**
     * Rename a draft.
     */
    async renameDraft(draftId, newLabel) {
        try {
            const resp = await fetch(`${this.baseUrl}/api/drafts/rename.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken,
                },
                body: JSON.stringify({ draft_id: draftId, label: newLabel }),
            });

            const result = await resp.json();
            return result.success;
        } catch (err) {
            console.error('Draft rename error:', err);
            return false;
        }
    }

    /**
     * One-time migration: convert existing localStorage draft to a server draft.
     */
    async migrateLocalStorage(localStorageKey) {
        try {
            const stored = localStorage.getItem(localStorageKey);
            if (!stored) return;

            const draft = JSON.parse(stored);
            if (!draft.data || Object.keys(draft.data).length === 0) {
                localStorage.removeItem(localStorageKey);
                return;
            }

            // Check if expired (7 days, old TTL)
            const maxAge = 7 * 24 * 60 * 60 * 1000;
            if (draft.timestamp && (Date.now() - draft.timestamp > maxAge)) {
                localStorage.removeItem(localStorageKey);
                return;
            }

            // Save to server
            const body = {
                canvas_template_id: this.canvasTemplateId,
                form_data: draft.data,
                page_no: draft.pageNo || 0,
                label: 'Migrado do navegador',
            };

            const resp = await fetch(`${this.baseUrl}/api/drafts/save.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken,
                },
                body: JSON.stringify(body),
            });

            const result = await resp.json();

            if (result.success) {
                localStorage.removeItem(localStorageKey);
                this.currentDraftId = result.draft_id;
                this.onStatusChange('Rascunho local migrado para o servidor');

                // Offer to restore
                const draftDate = draft.timestamp
                    ? new Date(draft.timestamp).toLocaleString('pt-BR')
                    : 'data desconhecida';

                if (confirm(`Encontramos um rascunho salvo em ${draftDate}.\n\nDeseja continuar de onde parou?`)) {
                    this.survey.data = draft.data;
                    if (draft.pageNo !== undefined) {
                        this.survey.currentPageNo = draft.pageNo;
                    }
                    // Show save button
                    const saveBtn = document.getElementById('saveDraftBtn');
                    if (saveBtn) saveBtn.style.display = 'inline-block';
                }
            }
        } catch (err) {
            console.error('Draft migration error:', err);
            // Keep localStorage as fallback if migration fails
        }
    }

    // ─── Private helpers ────────────────────────────────────────────

    _saveLocalFallback() {
        try {
            const key = `canvas_draft_fallback_${this.canvasTemplateId}`;
            localStorage.setItem(key, JSON.stringify({
                data: this.survey.data,
                pageNo: this.survey.currentPageNo,
                timestamp: Date.now(),
            }));
        } catch (e) {
            // localStorage might be full or unavailable
        }
    }

    _showModal(drafts, count) {
        // Remove existing modal if any
        this._closeModal();

        const maxDrafts = 10;
        const modalId = 'draftsModal';

        let listHTML = '';
        if (drafts.length === 0) {
            listHTML = '<div class="text-center text-muted p-4">Nenhum rascunho salvo</div>';
        } else {
            listHTML = drafts.map(d => {
                const updatedAt = new Date(d.updated_at).toLocaleString('pt-BR');
                const preview = d.preview
                    ? `<small class="text-muted d-block mt-1">${this._escapeHtml(d.preview)}</small>`
                    : '';

                return `
                <div class="card mb-2 draft-card" data-draft-id="${d.id}">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <span class="draft-label fw-semibold" data-draft-id="${d.id}"
                                      style="cursor: pointer" title="Clique para renomear">${this._escapeHtml(d.label)}</span>
                                <small class="text-muted d-block">${updatedAt} &middot; Pag. ${d.page_no + 1}</small>
                                ${preview}
                            </div>
                            <div class="btn-group btn-group-sm ms-2">
                                <button class="btn btn-primary btn-load-draft" data-draft-id="${d.id}" title="Carregar">
                                    Carregar
                                </button>
                                <button class="btn btn-outline-danger btn-delete-draft" data-draft-id="${d.id}" title="Excluir">
                                    &times;
                                </button>
                            </div>
                        </div>
                    </div>
                </div>`;
            }).join('');
        }

        const modalHTML = `
        <div class="modal fade" id="${modalId}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Meus Rascunhos
                            <span class="badge bg-secondary ms-2">${count}/${maxDrafts}</span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body" id="draftsModalBody">
                        ${listHTML}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    </div>
                </div>
            </div>
        </div>`;

        document.body.insertAdjacentHTML('beforeend', modalHTML);
        this._modalEl = document.getElementById(modalId);
        const bsModal = new bootstrap.Modal(this._modalEl);

        // Event delegation for buttons
        const body = document.getElementById('draftsModalBody');

        body.addEventListener('click', async (e) => {
            const loadBtn = e.target.closest('.btn-load-draft');
            const deleteBtn = e.target.closest('.btn-delete-draft');
            const labelEl = e.target.closest('.draft-label');

            if (loadBtn) {
                const id = parseInt(loadBtn.dataset.draftId);
                await this.loadDraft(id);
            } else if (deleteBtn) {
                const id = parseInt(deleteBtn.dataset.draftId);
                if (confirm('Excluir este rascunho?')) {
                    const ok = await this.deleteDraft(id);
                    if (ok) {
                        const card = deleteBtn.closest('.draft-card');
                        if (card) card.remove();
                        // Update badge count
                        const badge = this._modalEl.querySelector('.badge');
                        if (badge) {
                            const current = parseInt(badge.textContent) - 1;
                            badge.textContent = `${Math.max(0, current)}/${maxDrafts}`;
                        }
                    }
                }
            } else if (labelEl) {
                this._startInlineRename(labelEl);
            }
        });

        bsModal.show();

        // Cleanup on hide
        this._modalEl.addEventListener('hidden.bs.modal', () => {
            this._modalEl.remove();
            this._modalEl = null;
        }, { once: true });
    }

    _startInlineRename(labelEl) {
        const draftId = parseInt(labelEl.dataset.draftId);
        const currentLabel = labelEl.textContent;

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-sm d-inline-block';
        input.style.width = '200px';
        input.value = currentLabel;

        labelEl.replaceWith(input);
        input.focus();
        input.select();

        const doRename = async () => {
            const newLabel = input.value.trim();
            if (newLabel && newLabel !== currentLabel) {
                const ok = await this.renameDraft(draftId, newLabel);
                if (ok) {
                    labelEl.textContent = newLabel;
                } else {
                    labelEl.textContent = currentLabel;
                }
            } else {
                labelEl.textContent = currentLabel;
            }
            input.replaceWith(labelEl);
        };

        input.addEventListener('blur', doRename, { once: true });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                input.blur();
            } else if (e.key === 'Escape') {
                labelEl.textContent = currentLabel;
                input.replaceWith(labelEl);
            }
        });
    }

    _closeModal() {
        if (this._modalEl) {
            const existing = bootstrap.Modal.getInstance(this._modalEl);
            if (existing) existing.hide();
            this._modalEl.remove();
            this._modalEl = null;
        }
        // Also remove any leftover modal element
        const old = document.getElementById('draftsModal');
        if (old) old.remove();

        // Remove backdrops
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }

    _escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}
