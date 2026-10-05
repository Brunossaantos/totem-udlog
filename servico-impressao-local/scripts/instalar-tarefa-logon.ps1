<#
.SYNOPSIS
  Registra a tarefa do Agendador de Tarefas que sobe o servico-impressao-local
  (node src\server.js, 127.0.0.1:4747) ao fazer logon, SOB A CONTA DO USUARIO ATUAL.

.DESCRIPTION
  - Tarefa: "UDLOG Servico Impressao Local (logon)"
  - Gatilho: ao fazer logon de $env:USERDOMAIN\$env:USERNAME
  - Conta: o proprio usuario, LogonType Interactive ("somente quando o usuario
    estiver logado"), RunLevel Limited, SEM senha armazenada. Herda as
    preferencias da EPSON e o papel "totem" 80x80 (nao e LocalSystem).
  - Sem janela: a acao e `wscript.exe //B //Nologo iniciar-oculto.vbs <node> <pasta>`.
    Motivo: node.exe e aplicativo de console; via powershell -WindowStyle Hidden a
    janela ainda pisca no inicio, e o VBS (Run com estilo 0) nao mostra nada.
    O VBS espera o node terminar e devolve o exit code, entao o Agendador
    consegue reiniciar em falha.
  - Reinicio em falha: 3 vezes, a cada 1 minuto. Sem limite de tempo de execucao.
    MultipleInstances = IgnoreNew. Inicia mesmo na bateria.
  - Nao inicia a tarefa ao final (so imprime como iniciar).

  ADMINISTRADOR: este script NAO exige PowerShell como Administrador por
  desenho (tarefa do proprio usuario, RunLevel Limited), mas o Windows pode negar
  o gatilho de logon sem elevacao em algumas politicas. Se isso ocorrer, o script
  detecta "acesso negado" e orienta a reabrir o PowerShell como Administrador
  (a tarefa continua rodando como o usuario atual, nao como admin).
  NAO foi executado/validado em runtime pelo autor; apenas parse de sintaxe.

.PARAMETER Force
  Substitui a tarefa existente sem perguntar.

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\instalar-tarefa-logon.ps1
  powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\instalar-tarefa-logon.ps1 -Force
#>
[CmdletBinding()]
param([switch]$Force)

$ErrorActionPreference = 'Stop'
$TaskName   = 'UDLOG Servico Impressao Local (logon)'
$Porta      = 4747
$ServiceDir = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Vbs        = Join-Path $PSScriptRoot 'iniciar-oculto.vbs'
$UserId     = "$env:USERDOMAIN\$env:USERNAME"

# 1. Pre-requisitos
if (-not (Test-Path (Join-Path $ServiceDir 'src\server.js'))) {
    Write-Error "src\server.js nao encontrado em $ServiceDir."
}
if (-not (Test-Path $Vbs)) { Write-Error "Lancador nao encontrado: $Vbs" }

$nodeCmd = Get-Command node -ErrorAction SilentlyContinue
if (-not $nodeCmd) {
    Write-Error "Node.js nao encontrado no PATH (Get-Command node falhou). Instale o Node.js (README secao 2.1) e abra um novo PowerShell."
}
$NodePath = $nodeCmd.Source

# 2. Porta 4747 ocupada? (apenas avisa, nao mata nada)
$listen = @(Get-NetTCPConnection -LocalPort $Porta -State Listen -ErrorAction SilentlyContinue)
if ($listen.Count -gt 0) {
    $pids = ($listen | Select-Object -ExpandProperty OwningProcess -Unique) -join ', '
    Write-Warning "Ja existe processo escutando na porta $Porta (PID: $pids). Provavelmente o 'npm start' manual."
    Write-Warning "Pare-o voce mesmo (Ctrl+C na janela dele, ou: taskkill /PID <pid> /T /F) antes de iniciar a tarefa. Este script NAO encerra processos."
}

# 3. Tarefa ja existe?
$existente = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($existente) {
    if (-not $Force) {
        $r = Read-Host "A tarefa '$TaskName' ja existe. Substituir? (s/N)"
        if ($r -notmatch '^(s|S|y|Y)') { Write-Host 'Cancelado. Nada foi alterado.'; return }
    }
}

# 4. Definicao da tarefa
$action = New-ScheduledTaskAction -Execute 'wscript.exe' `
    -Argument ('//B //Nologo "{0}" "{1}" "{2}"' -f $Vbs, $NodePath, $ServiceDir) `
    -WorkingDirectory $ServiceDir
$trigger   = New-ScheduledTaskTrigger -AtLogOn -User $UserId
$principal = New-ScheduledTaskPrincipal -UserId $UserId -LogonType Interactive -RunLevel Limited
$settings  = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit (New-TimeSpan -Seconds 0) `
    -MultipleInstances IgnoreNew -StartWhenAvailable

try {
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger `
        -Principal $principal -Settings $settings `
        -Description 'Sobe o servico-impressao-local (127.0.0.1:4747) no logon, sob a conta do usuario.' `
        -Force | Out-Null
} catch {
    $msg = $_.Exception.Message
    if ($_.Exception -is [System.UnauthorizedAccessException] -or $msg -match 'negad|denied|0x80070005') {
        Write-Error "Acesso negado ao registrar a tarefa. Abra o PowerShell como Administrador (botao direito > Executar como administrador) e rode de novo. A tarefa continuara executando como $UserId."
    }
    throw
}

Write-Host ''
Write-Host "Tarefa registrada: $TaskName" -ForegroundColor Green
Write-Host "  Usuario : $UserId (Interactive, sem senha)"
Write-Host "  Node    : $NodePath"
Write-Host "  Pasta   : $ServiceDir"
Write-Host ''
Write-Host 'A tarefa NAO foi iniciada. Para iniciar agora (ou faca logoff/logon):'
Write-Host "  Start-ScheduledTask -TaskName `"$TaskName`""
Write-Host 'Para verificar:'
Write-Host "  Get-ScheduledTask -TaskName `"$TaskName`""
Write-Host "  Get-ScheduledTaskInfo -TaskName `"$TaskName`""
Write-Host "  netstat -ano | findstr :$Porta"
