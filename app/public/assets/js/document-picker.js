/**
 * Document Picker — integration between SurveyJS file fields and user_documents library.
 *
 * Injects "Escolher dos Meus Documentos" button next to SurveyJS file upload fields.
 * Opens a modal with the user's document library, filtered by accepted MIME types.
 */

(function() {
    'use strict';

    const BASE_URL = window.SUNYATA_BASE_URL || '';

    /**
     * Initialize document picker buttons for all file-type questions.
     * Call this after SurveyJS renders the form.
     *
     * @param {Survey.Model} survey — the SurveyJS model instance
     */
    function initDocumentPicker(survey) {
        if (!survey) return;

        // Find all file-type questions
        survey.getAllQuestions().forEach(function(question) {
            if (question.getType() !== 'file') return;

            // Wait for DOM to be ready
            setTimeout(function() {
                injectPickerButton(question, survey);
            }, 100);
        });
    }

    /**
     * Inject the picker button next to a file question's upload area.
     */
    function injectPickerButton(question, survey) {
        const container = question.contentPanel
            ? document.getElementById(question.contentPanel.id)
            : document.querySelector('[data-name="' + question.name + '"]');

        if (!container) return;

        // Don't add twice
        if (container.querySelector('.doc-picker-btn')) return;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-outline-primary mt-2 doc-picker-btn';
        btn.innerHTML = '<i class="ti ti-folder me-1"></i> Escolher dos Meus Documentos';

        btn.addEventListener('click', function(e) {
            e.preventDefault();
            openPickerModal(question, survey);
        });

        // Append after the file upload area
        const fileArea = container.querySelector('.sd-file') || container.querySelector('.sv_q_file');
        if (fileArea) {
            fileArea.parentNode.insertBefore(btn, fileArea.nextSibling);
        } else {
            container.appendChild(btn);
        }
    }

    /**
     * Open the document picker modal.
     */
    function openPickerModal(question, survey) {
        // Determine accepted MIME types
        const acceptedTypes = question.acceptedTypes || '';

        // Remove existing modal if any
        const existing = document.getElementById('docPickerModal');
        if (existing) existing.remove();

        // Create modal
        const modal = document.createElement('div');
        modal.id = 'docPickerModal';
        modal.className = 'modal modal-blur fade';
        modal.setAttribute('tabindex', '-1');
        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Escolher Documento</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-0" id="docPickerContent"
                         hx-get="${BASE_URL}/api/documents/picker.php?field=${encodeURIComponent(question.name)}&accept=${encodeURIComponent(acceptedTypes)}"
                         hx-trigger="load"
                         hx-swap="innerHTML">
                        <div class="text-center p-4 text-secondary">
                            <span class="spinner-border spinner-border-sm"></span> Carregando...
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="${BASE_URL}/meus-documentos/" target="_blank" class="btn btn-ghost-primary btn-sm">
                            <i class="ti ti-external-link me-1"></i> Gerenciar Documentos
                        </a>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Process HTMX in the new content
        if (typeof htmx !== 'undefined') {
            htmx.process(modal);
        }

        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();

        // Clean up on close
        modal.addEventListener('hidden.bs.modal', function() {
            modal.remove();
        });

        // Global handler for document selection
        window.selectDocument = function(docId, filename, fieldName) {
            // Set the value on the SurveyJS question
            // Format matches SurveyJS file question expected value
            const fileValue = [{
                name: filename,
                content: String(docId), // Document ID as string, same as upload-file.php
                type: 'document-library'
            }];

            question.value = fileValue;
            bsModal.hide();

            if (typeof SunyataApp !== 'undefined') {
                SunyataApp.showToast('Documento selecionado: ' + filename, 'success');
            }
        };
    }

    // Export
    window.SunyataDocPicker = {
        init: initDocumentPicker
    };
})();
