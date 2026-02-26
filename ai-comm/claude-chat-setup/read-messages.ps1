# read-messages.ps1 — Le mensagens ai-comm do servidor Hostinger
# Uso: .\read-messages.ps1           (lista ultimas 10)
#      .\read-messages.ps1 -Count 20 (lista ultimas 20)
#      .\read-messages.ps1 -Read "20260219-1500-de-claude-para-claude-chat-resposta.md"
#      .\read-messages.ps1 -For "claude-chat" (filtra mensagens para claude-chat)

param(
    [int]$Count = 10,
    [string]$Read,
    [string]$For
)

$ServerUser = "u202164171"
$ServerHost = "82.25.72.226"
$ServerPort = 65002
$RemotePath = "/home/u202164171/ai-comm"

function Invoke-SSH($cmd) {
    & ssh -p $ServerPort "${ServerUser}@${ServerHost}" $cmd
}

if ($Read) {
    # Read specific message
    Write-Host "=== Lendo: $Read ===" -ForegroundColor Cyan
    Write-Host ""
    Invoke-SSH "cat '$RemotePath/$Read'"
} elseif ($For) {
    # Filter messages for a specific agent
    Write-Host "=== Mensagens para: $For (ultimas $Count) ===" -ForegroundColor Cyan
    Write-Host ""
    Invoke-SSH "ls -t '$RemotePath' | grep 'para-$For' | head -$Count"
} else {
    # List recent messages
    Write-Host "=== Ultimas $Count mensagens ai-comm ===" -ForegroundColor Cyan
    Write-Host ""
    Invoke-SSH "ls -lt '$RemotePath' | head -$($Count + 1)"

    Write-Host ""
    Write-Host "--- Pendentes para claude-chat ---" -ForegroundColor Red
    $pending = Invoke-SSH "ls -t '$RemotePath' | grep 'para-claude-chat' | head -5"
    if ($pending) {
        $pending
    } else {
        Write-Host "(nenhuma)" -ForegroundColor DarkGray
    }
}
