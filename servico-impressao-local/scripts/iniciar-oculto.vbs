' Lancador sem janela do servico-impressao-local (usado pela tarefa de logon).
' Uso: wscript.exe //B //Nologo iniciar-oculto.vbs "<caminho node.exe>" "<pasta do servico>"
' Executa `node src\server.js` com janela oculta e ESPERA o processo terminar,
' devolvendo o codigo de saida dele (assim o Agendador reinicia em falha).
Option Explicit
Dim args, sh, cmd, rc
Set args = WScript.Arguments
If args.Count < 2 Then WScript.Quit 2
Set sh = CreateObject("WScript.Shell")
sh.CurrentDirectory = args(1)
cmd = """" & args(0) & """ src\server.js"
rc = sh.Run(cmd, 0, True)
WScript.Quit rc
