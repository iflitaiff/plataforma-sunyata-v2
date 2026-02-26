# send-message.ps1 — Envia mensagem ai-comm do Claude Chat para o servidor Hostinger
# Uso: .\send-message.ps1 [-Destination claude] [-Subject "arquitetura-pncp"] [-File mensagem.md]
# Ou:  .\send-message.ps1 (interativo)

param(
    [string]$Destination,
    [string]$Subject,
    [string]$File,
    [string]$Content
)

$ServerUser = "u202164171"
$ServerHost = "82.25.72.226"
$ServerPort = 65002
$RemotePath = "/home/u202164171/ai-comm"

# Interactive mode if no parameters
if (-not $Destination) {
    Write-Host "=== ai-comm: Enviar mensagem do Claude Chat ===" -ForegroundColor Red
    Write-Host ""
    Write-Host "Destinatarios disponiveis:"
    Write-Host "  claude    - Claude Code (executor principal)"
    Write-Host "  gemini    - QA Infra/Codigo"
    Write-Host "  codex     - QA Dados/Templates"
    Write-Host "  copilot   - QA Frontend & Testes"
    Write-Host "  filipe    - Product Owner"
    Write-Host ""
    $Destination = Read-Host "Para quem"
}

if (-not $Subject) {
    $Subject = Read-Host "Assunto (max 4 palavras, separar com hifen)"
}

# Generate filename
$timestamp = Get-Date -Format "yyyyMMdd-HHmm"
$filename = "$timestamp-de-claude-chat-para-$Destination-$Subject.md"

# Get content
if ($File -and (Test-Path $File)) {
    $messageContent = Get-Content $File -Raw -Encoding UTF8
} elseif ($Content) {
    $messageContent = $Content
} else {
    Write-Host ""
    Write-Host "Cole o conteudo da mensagem abaixo (Ctrl+Z + Enter para finalizar):" -ForegroundColor Cyan
    $lines = @()
    try {
        while ($true) {
            $line = Read-Host
            if ($null -eq $line) { break }
            $lines += $line
        }
    } catch {}
    $messageContent = $lines -join "`n"
}

if (-not $messageContent -or $messageContent.Trim() -eq "") {
    Write-Host "[ERRO] Mensagem vazia. Abortando." -ForegroundColor Red
    exit 1
}

# Validate header
if ($messageContent -notmatch '\*\*De:\*\*') {
    Write-Host "[!] AVISO: Mensagem nao contem cabecalho '**De:**'. O monitor pode nao detectar corretamente." -ForegroundColor Yellow
}

# Save locally
$localDir = "$env:USERPROFILE\sunyata-ai-comm"
if (-not (Test-Path $localDir)) {
    New-Item -ItemType Directory -Path $localDir | Out-Null
    Write-Host "[+] Criado diretorio local: $localDir" -ForegroundColor Green
}

$localFile = Join-Path $localDir $filename
$messageContent | Out-File -FilePath $localFile -Encoding utf8NoBOM
Write-Host "[+] Salvo localmente: $localFile" -ForegroundColor Green

# Upload to Hostinger server via SCP
Write-Host "[*] Enviando para Hostinger..." -ForegroundColor Cyan

try {
    & scp -P $ServerPort $localFile "${ServerUser}@${ServerHost}:${RemotePath}/${filename}"
    if ($LASTEXITCODE -eq 0) {
        Write-Host "[OK] Mensagem enviada: $filename" -ForegroundColor Green
        Write-Host "[OK] O monitor-aicomm.sh detectara e enviara email." -ForegroundColor DarkGray
    } else {
        Write-Host "[ERRO] Falha ao enviar via SCP." -ForegroundColor Red
        Write-Host "[ALT] Tente via WSL:" -ForegroundColor Yellow
        Write-Host "  wsl bash -c 'scp -P $ServerPort `"$localFile`" ${ServerUser}@${ServerHost}:${RemotePath}/${filename}'" -ForegroundColor Yellow
    }
} catch {
    Write-Host "[ERRO] $($_.Exception.Message)" -ForegroundColor Red
}
