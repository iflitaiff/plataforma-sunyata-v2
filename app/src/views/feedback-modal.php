<!-- Modal de Feedback Opcional -->
<div class="modal fade" id="feedbackModal" tabindex="-1" aria-labelledby="feedbackModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="feedbackModalLabel">
                    <i class="bi bi-star-fill"></i> Como foi sua experiência?
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">
                    Sua opinião é muito importante para melhorarmos nossos serviços!
                </p>

                <!-- Rating com Estrelas -->
                <div class="mb-4">
                    <label class="form-label fw-bold">Avalie a resposta recebida:</label>
                    <div class="rating-stars d-flex justify-content-center gap-2 mb-2" id="ratingStars">
                        <i class="bi bi-star star-icon" data-rating="1"></i>
                        <i class="bi bi-star star-icon" data-rating="2"></i>
                        <i class="bi bi-star star-icon" data-rating="3"></i>
                        <i class="bi bi-star star-icon" data-rating="4"></i>
                        <i class="bi bi-star star-icon" data-rating="5"></i>
                    </div>
                    <div class="text-center">
                        <small class="text-muted" id="ratingLabel">Clique nas estrelas para avaliar</small>
                    </div>
                    <input type="hidden" id="feedbackRating" value="0">
                </div>

                <!-- Comentário Opcional -->
                <div class="mb-3">
                    <label for="feedbackComentario" class="form-label">Comentários (opcional):</label>
                    <textarea class="form-control" id="feedbackComentario" rows="3"
                              placeholder="Conte-nos mais sobre sua experiência..."></textarea>
                </div>

                <!-- Alert de erro/sucesso -->
                <div id="feedbackAlert" class="alert d-none" role="alert"></div>

                <!-- Hidden fields -->
                <input type="hidden" id="feedbackCanvasId" value="">
                <input type="hidden" id="feedbackPromptHistoryId" value="">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Pular
                </button>
                <button type="button" class="btn btn-primary" id="submitFeedbackBtn" disabled>
                    <i class="bi bi-send-fill"></i> Enviar Feedback
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    .star-icon {
        font-size: 2rem;
        color: #ddd;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .star-icon:hover,
    .star-icon.active {
        color: #ffc107;
        transform: scale(1.1);
    }

    .star-icon.bi-star-fill {
        color: #ffc107;
    }
</style>

<script>
// Sistema de Rating com Estrelas
(function() {
    const stars = document.querySelectorAll('.star-icon');
    const ratingInput = document.getElementById('feedbackRating');
    const ratingLabel = document.getElementById('ratingLabel');
    const submitBtn = document.getElementById('submitFeedbackBtn');
    const alertDiv = document.getElementById('feedbackAlert');

    const ratingLabels = {
        1: 'Muito insatisfeito',
        2: 'Insatisfeito',
        3: 'Neutro',
        4: 'Satisfeito',
        5: 'Muito satisfeito'
    };

    // Evento de hover
    stars.forEach(star => {
        star.addEventListener('mouseenter', function() {
            const rating = this.getAttribute('data-rating');
            highlightStars(rating);
        });

        star.addEventListener('click', function() {
            const rating = this.getAttribute('data-rating');
            ratingInput.value = rating;
            ratingLabel.textContent = ratingLabels[rating];
            highlightStars(rating);
            submitBtn.disabled = false;

            // Remover active de todas e adicionar apenas nas selecionadas
            stars.forEach(s => {
                s.classList.remove('active');
                s.classList.remove('bi-star-fill');
                s.classList.add('bi-star');
            });

            for (let i = 0; i < rating; i++) {
                stars[i].classList.add('active', 'bi-star-fill');
                stars[i].classList.remove('bi-star');
            }
        });
    });

    // Reset ao sair do hover
    document.getElementById('ratingStars').addEventListener('mouseleave', function() {
        const currentRating = ratingInput.value;
        if (currentRating > 0) {
            highlightStars(currentRating);
        } else {
            resetStars();
        }
    });

    function highlightStars(rating) {
        stars.forEach((star, index) => {
            if (index < rating) {
                star.classList.remove('bi-star');
                star.classList.add('bi-star-fill');
            } else {
                star.classList.remove('bi-star-fill');
                star.classList.add('bi-star');
            }
        });
    }

    function resetStars() {
        stars.forEach(star => {
            star.classList.remove('bi-star-fill', 'active');
            star.classList.add('bi-star');
        });
    }

    // Submit Feedback
    document.getElementById('submitFeedbackBtn').addEventListener('click', async function() {
        const rating = parseInt(ratingInput.value);
        const comentario = document.getElementById('feedbackComentario').value.trim();
        const canvasId = document.getElementById('feedbackCanvasId').value;
        const promptHistoryId = document.getElementById('feedbackPromptHistoryId').value;

        if (rating < 1 || rating > 5) {
            showAlert('Por favor, selecione uma avaliação', 'danger');
            return;
        }

        if (!canvasId) {
            showAlert('Erro: Canvas ID não encontrado', 'danger');
            return;
        }

        // Desabilitar botão durante envio
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Enviando...';

        try {
            const formData = new FormData();
            formData.append('csrf_token', '<?= csrf_token() ?>');
            formData.append('canvas_id', canvasId);
            formData.append('rating', rating);
            if (comentario) formData.append('comentario', comentario);
            if (promptHistoryId) formData.append('prompt_history_id', promptHistoryId);

            const response = await fetch('<?= BASE_URL ?>/api/feedback/submit.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                showAlert(data.message || 'Feedback enviado com sucesso!', 'success');

                // Fechar modal após 1.5 segundos
                setTimeout(() => {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('feedbackModal'));
                    modal.hide();
                    resetForm();
                }, 1500);
            } else {
                showAlert(data.error || 'Erro ao enviar feedback', 'danger');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-send-fill"></i> Enviar Feedback';
            }
        } catch (error) {
            showAlert('Erro de conexão. Tente novamente.', 'danger');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-send-fill"></i> Enviar Feedback';
            console.error('Feedback error:', error);
        }
    });

    function showAlert(message, type) {
        alertDiv.className = `alert alert-${type}`;
        alertDiv.textContent = message;
        alertDiv.classList.remove('d-none');
    }

    function resetForm() {
        ratingInput.value = 0;
        document.getElementById('feedbackComentario').value = '';
        document.getElementById('feedbackCanvasId').value = '';
        document.getElementById('feedbackPromptHistoryId').value = '';
        resetStars();
        ratingLabel.textContent = 'Clique nas estrelas para avaliar';
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-send-fill"></i> Enviar Feedback';
        alertDiv.classList.add('d-none');
    }

    // Reset form ao fechar modal
    document.getElementById('feedbackModal').addEventListener('hidden.bs.modal', resetForm);
})();

// Função global para abrir modal de feedback
function openFeedbackModal(canvasId, promptHistoryId = null) {
    document.getElementById('feedbackCanvasId').value = canvasId;
    document.getElementById('feedbackPromptHistoryId').value = promptHistoryId || '';

    const modal = new bootstrap.Modal(document.getElementById('feedbackModal'));
    modal.show();
}
</script>
