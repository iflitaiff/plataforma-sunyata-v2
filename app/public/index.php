<?php
/**
 * Landing Page / Login
 *
 * @package Sunyata
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Auth\GoogleAuth;

$auth = new GoogleAuth();

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    redirect(BASE_URL . '/dashboard.php');
}

$loginUrl = $auth->getAuthUrl();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row min-vh-100 align-items-center justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow-lg border-0">
                    <div class="card-body p-5 text-center">
                        <div class="mb-4">
                            <h1 class="display-4 fw-bold text-primary mb-3"><?= APP_NAME ?></h1>
                            <p class="lead text-muted">Ensino e Consultoria em IA Generativa</p>
                        </div>

                        <div class="my-5">
                            <h2 class="h4 mb-4">Transforme seu trabalho com IA</h2>
                            <div class="row g-3 text-start">
                                <div class="col-12">
                                    <div class="d-flex align-items-start">
                                        <div class="flex-shrink-0">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-check-circle-fill text-success" viewBox="0 0 16 16">
                                                <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                                            </svg>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h6 class="mb-1">Dicionário de Prompts</h6>
                                            <p class="mb-0 text-muted small">Templates prontos para Vendas, Marketing, Atendimento e RH</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="d-flex align-items-start">
                                        <div class="flex-shrink-0">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-check-circle-fill text-success" viewBox="0 0 16 16">
                                                <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                                            </svg>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h6 class="mb-1">Conteúdo Especializado</h6>
                                            <p class="mb-0 text-muted small">Cursos e consultoria personalizados por vertical</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="d-flex align-items-start">
                                        <div class="flex-shrink-0">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-check-circle-fill text-success" viewBox="0 0 16 16">
                                                <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                                            </svg>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <h6 class="mb-1">100% LGPD Compliant</h6>
                                            <p class="mb-0 text-muted small">Seus dados protegidos com total conformidade legal</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid gap-2 mb-4">
                            <a href="<?= sanitize_output($loginUrl) ?>" class="btn btn-primary btn-lg">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-google me-2" viewBox="0 0 16 16">
                                    <path d="M15.545 6.558a9.4 9.4 0 0 1 .139 1.626c0 2.434-.87 4.492-2.384 5.885h.002C11.978 15.292 10.158 16 8 16A8 8 0 1 1 8 0a7.7 7.7 0 0 1 5.352 2.082l-2.284 2.284A4.35 4.35 0 0 0 8 3.166c-2.087 0-3.86 1.408-4.492 3.304a4.8 4.8 0 0 0 0 3.063h.003c.635 1.893 2.405 3.301 4.492 3.301 1.078 0 2.004-.276 2.722-.764h-.003a3.7 3.7 0 0 0 1.599-2.431H8v-3.08z"/>
                                </svg>
                                Entrar com Google
                            </a>
                        </div>

                        <p class="text-muted small mb-0">
                            Ao entrar, você concorda com nossos
                            <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Termos de Uso</a>
                            e
                            <a href="#" data-bs-toggle="modal" data-bs-target="#privacyModal">Política de Privacidade</a>
                        </p>
                    </div>
                </div>

                <div class="text-center mt-4 text-muted small">
                    <p>&copy; <?= date('Y') ?> <?= COMPANY_NAME ?>. Todos os direitos reservados.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Terms Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Termos de Uso</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>1. Aceitação dos Termos</h6>
                    <p>Ao acessar e utilizar a Plataforma Sunyata, você concorda com estes termos.</p>

                    <h6>2. Uso da Plataforma</h6>
                    <p>A plataforma oferece conteúdo educacional e de consultoria sobre IA generativa. Todo conteúdo gerado é para fins educacionais.</p>

                    <h6>3. Responsabilidades do Usuário</h6>
                    <p>Você é responsável pelo uso adequado dos conteúdos fornecidos, incluindo verificação de conformidade legal nas suas aplicações.</p>

                    <h6>4. Propriedade Intelectual</h6>
                    <p>Os conteúdos da plataforma são de propriedade da Sunyata Consulting. O uso é autorizado apenas para fins pessoais ou corporativos internos.</p>

                    <h6>5. Limitação de Responsabilidade</h6>
                    <p>A Sunyata Consulting não se responsabiliza por resultados obtidos através da aplicação dos conteúdos fornecidos.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Privacy Modal -->
    <div class="modal fade" id="privacyModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Política de Privacidade</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>1. Dados Coletados</h6>
                    <p>Coletamos: nome, email, foto (via Google OAuth), histórico de uso da plataforma.</p>

                    <h6>2. Finalidade</h6>
                    <p>Os dados são utilizados para: autenticação, personalização da experiência, análise de uso e comunicação sobre serviços.</p>

                    <h6>3. Compartilhamento</h6>
                    <p>Não compartilhamos seus dados com terceiros, exceto quando exigido por lei.</p>

                    <h6>4. Seus Direitos (LGPD)</h6>
                    <ul>
                        <li>Acessar seus dados pessoais</li>
                        <li>Solicitar correção de dados</li>
                        <li>Solicitar exclusão (anonimização)</li>
                        <li>Exportar seus dados (portabilidade)</li>
                        <li>Revogar consentimentos</li>
                    </ul>

                    <h6>5. Retenção de Dados</h6>
                    <p>Dados são mantidos por até <?= DATA_RETENTION_DAYS ?> dias após inatividade, sendo posteriormente anonimizados.</p>

                    <h6>6. Segurança</h6>
                    <p>Utilizamos medidas técnicas e administrativas para proteção dos seus dados.</p>

                    <h6>7. Contato DPO</h6>
                    <p>Para exercer seus direitos: <a href="mailto:<?= DPO_EMAIL ?>"><?= DPO_EMAIL ?></a></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
