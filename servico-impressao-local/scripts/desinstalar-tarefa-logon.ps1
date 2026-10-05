<#
.SYNOPSIS
  Remove a tarefa "UDLOG Servico Impressao Local (logon)".

.DESCRIPTION
  Para a tarefa se estiver rodando (Stop-ScheduledTask) e a remove
  (Unregister-ScheduledTask -Confirm:$false). NAO mata processos node por nome.
  Se algo ainda escutar na 4747, informa o PID e como encerra-lo por PID.
  Remover a tarefa do proprio usuario normalmente nao exige Administrador;
  se o Windows negar, reabra o PowerShell como Administrador.
  NAO executado pelo autor; apenas parse de sintaxe.

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\desinstalar-tarefa-logon.ps1
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$TaskName = 'UDLOG Servico Impressao Local (logon)'
$Porta    = 4747

$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if (-not $task) {
    Write-Host "Tarefa '$TaskName' nao existe. Nada a remover."
} else {
    if ($task.State -eq 'Running') {
        Stop-ScheduledTask -TaskName $TaskName
        Write-Host 'Tarefa parada.'
    }
    try {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    } catch {
        if ($_.Exception -is [System.UnauthorizedAccessException] -or $_.Exception.Message -match 'negad|denied|0x80070005') {
            Write-Error 'Acesso negado. Abra o PowerShell como Administrador e rode de novo.'
        }
        throw
    }
    Write-Host "Tarefa removida: $TaskName" -ForegroundColor Green
}

$listen = @(Get-NetTCPConnection -LocalPort $Porta -State Listen -ErrorAction SilentlyContinue)
if ($listen.Count -gt 0) {
    $pids = ($listen | Select-Object -ExpandProperty OwningProcess -Unique)
    Write-Warning "Ainda ha processo escutando na porta $Porta (PID: $($pids -join ', ')). Nenhum processo foi encerrado por este script."
    foreach ($p in $pids) { Write-Host "  Para encerrar: taskkill /PID $p /T /F" }
} else {
    Write-Host "Nada escutando na porta $Porta."
}
