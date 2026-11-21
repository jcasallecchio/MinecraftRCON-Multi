# MineDev Console

Painel web do MinecraftRCON Multi para administrar servidores **Minecraft Bedrock Edition**. Mostra jogadores, mundo, versão, memória do processo e tempo de atividade. Permite selecionar instâncias, acompanhar entradas, saídas e mortes, consultar estatísticas por mundo, enviar comandos, e iniciar, parar ou reiniciar o servidor selecionado. O painel funciona no computador e no celular.

O navegador acessa o painel pelo Apache. O PHP chama as APIs RCON na rede de confiança, em `19233–19237/TCP`, usando os tokens privados configurados no `.env`. Nunca encaminhe essas portas pela internet.

## Requisitos

- [MinecraftRCON Multi](../MinecraftRCON/DOCS.md), com as APIs v1 acessíveis a partir do servidor web.
- Apache 2.4 com PHP, `mod_rewrite` e suporte a `.htaccess` para a pasta do console. [Configuração de .htaccess no Apache](https://httpd.apache.org/docs/2.4/howto/htaccess.html)
- PHP 8.2 ou mais recente, em uma versão que ainda receba correções de segurança. Habilite `curl` no PHP usado pelo Apache e tenha o PHP de linha de comando disponível para a configuração inicial. [Versões mantidas do PHP](https://www.php.net/supported-versions.php) · [Extensão cURL](https://www.php.net/manual/en/book.curl.php)

## 1. Instale os arquivos do console

Baixe ou clone o repositório fora da raiz pública do Apache. Copie **o conteúdo da pasta `console/`**, incluindo os arquivos ocultos, para a pasta `minedev/` da hospedagem. Em um servidor com `/var/www/html` como raiz, a instalação terá esta estrutura.

~~~text
/var/www/html/minedev/
├── .env.example
├── .htaccess
├── api.php
├── bootstrap.php
├── index.php
├── assets/
├── storage/
└── tools/
~~~

Configure o Apache para interpretar PHP e ler o `.htaccess` da pasta. Adicione um bloco como este à configuração do site no Apache.

~~~apache
<Directory "/var/www/html/minedev">
    Options +FollowSymLinks
    AllowOverride AuthConfig FileInfo Options
    DirectoryIndex index.php
</Directory>
~~~

Ajuste o caminho à sua instalação. No XAMPP, a pasta costuma ficar em `htdocs/minedev`. Habilite `mod_rewrite` e recarregue o Apache após editar a configuração. As regras fornecidas bloqueiam o acesso HTTP a configurações, logs e ferramentas. Antes de criar o `.env`, abra `/minedev/.env.example` e `/minedev/tools/initialize.php` a partir da rede que usará o painel. Confirme que o Apache retorna **403** nos dois endereços. [Requisitos do mod_rewrite em .htaccess](https://httpd.apache.org/docs/2.4/pt-br/rewrite/htaccess.html)

## 2. Crie o login e conecte ao Bedrock

Na pasta instalada do console, execute este comando no terminal.

~~~sh
php tools/initialize.php
~~~

O instalador cria o `.env` local e mostra o usuário inicial `admin` e uma senha aleatória. Guarde essa senha no seu gerenciador de senhas. O arquivo guarda somente o hash da senha e o instalador preserva um `.env` existente. No Windows, se `php` não estiver no PATH, use o caminho do executável, por exemplo `C:\xampp\php\php.exe`.

Abra o `.env` em um editor. Defina `BEDROCK_API_TOKEN` uma vez com o `rcon_token` do add-on. Para uma instalação Multi, associe cada ID da lista **Mundos/instâncias** à sua API. Use a mesma ordem da lista; a primeira API começa em `19233`, a segunda `19234` e assim por diante. Troque o endereço de exemplo pelo IP local do Home Assistant.

~~~dotenv
BEDROCK_API_TOKEN=SEU_TOKEN_DO_ADDON
BEDROCK_INSTANCES='[{"id":"abydos","name":"Abydos","url":"http://192.168.1.50:19233","token":"${BEDROCK_API_TOKEN}"},{"id":"creative","name":"Criativo","url":"http://192.168.1.50:19234","token":"${BEDROCK_API_TOKEN}"}]'
~~~

Mantenha os tokens somente em `.env`, fora da raiz pública sempre que possível. Se o add-on usar `rcon_allowed_ips`, inclua o **IP de origem do servidor PHP** que faz as chamadas, inclusive se ele estiver em um contêiner. Para manter um único servidor legado, `BEDROCK_API_URL` e `BEDROCK_API_TOKEN` continuam aceitos.

O processo PHP precisa conseguir ler `.env` e escrever em `storage/`. Em Linux com Apache/PHP usando `www-data`, use os comandos abaixo na pasta do console.

~~~sh
sudo chgrp www-data .env
sudo chmod 0640 .env
sudo sh tools/fix-storage-permissions.sh www-data www-data
~~~

Troque o usuário e o grupo se o seu serviço PHP usar outra conta. No Windows, aplique as permissões equivalentes na conta que executa o Apache. Preserve uma cópia privada do `.env` e dos logs se for migrar a instalação.

Para trocar o usuário ou a senha, execute `php tools/set-login.php`. Se o PHP lê o `.env` pelo grupo em Linux, reaplique `chmod 0640 .env` após a troca. Os demais parâmetros são `APP_TIMEZONE` para o fuso do PHP e `LOG_RETENTION_DAYS` para a retenção do histórico, com padrão de **7 dias** e intervalo de 1 a 30. O navegador exibe o horário dos eventos no fuso do dispositivo.

## 3. Use o painel

1. Abra **/minedev/** no endereço do seu servidor web e entre com o usuário e a senha.
2. Aguarde **Bedrock online**. Confira o mundo, o número de jogadores e a versão. Use o botão de atualização para consultar a telemetria imediatamente.
3. Em **Console**, digite `list` e pressione **Enter** ou toque em **Enviar comando** para listar jogadores. Use **Tab** para preencher uma sugestão selecionada ou toque na sugestão no celular. Quando a lista de sugestões estiver fechada, **↑/↓** percorrem os comandos digitados na sessão.
4. Em **Comandos & glossário**, pesquise comandos por nome, descrição ou categoria. Os comandos aceitos dependem da versão do Bedrock e das opções do mundo. [Referência de comandos do Bedrock](https://learn.microsoft.com/en-us/minecraft/creator/commands/commands?view=minecraft-bedrock-stable)

   O catálogo busca comandos, aliases e identificadores de itens nos metadados versionados do repositório oficial [Mojang/bedrock-samples](https://github.com/Mojang/bedrock-samples). Quando o catálogo é consultado, o servidor web verifica se há uma nova versão estável; a cópia privada em `storage/bedrock_catalog.json` é atualizada no máximo uma vez a cada 24 horas. O arquivo distribuído em `assets/bedrock-items.json` é usado como fallback se o GitHub estiver indisponível. Para atualizar automaticamente, o PHP precisa ter cURL habilitado, acesso HTTPS de saída a `raw.githubusercontent.com` e permissão de escrita em `storage/`. A tela mostra as versões do servidor e dos metadados, além da quantidade de itens carregados. O catálogo oficial cobre itens vanilla; itens personalizados de add-ons não são descobertos automaticamente.
5. Em **Histórico**, consulte os eventos mais recentes e use **Exportar CSV** para salvar o período disponível. **Limpar tela** limpa apenas o terminal. Os registros são salvos em `storage/`. Quando o painel é acessado, a limpeza periódica remove os eventos que passaram da retenção.
6. O quadro do **Console** abre em **Terminal**, com comandos e retornos. Clique em **Feed** para ver, no mesmo quadro, entradas, saídas e mortes do mundo selecionado; o botão passa a **Terminal** para voltar. O campo de comandos permanece na mesma posição nas duas visualizações; ao enviar um comando pelo Feed, o painel volta ao Terminal para mostrar o retorno. O Feed carrega até 20 eventos recentes e consulta novos eventos automaticamente a cada cinco segundos enquanto está aberto. **Limpar tela** limpa a visualização aberta, sem apagar o histórico persistente; os eventos do Feed reaparecem quando ele é carregado novamente. O add-on mantém os eventos do Feed por até sete dias.
7. Em **Estatísticas**, compare tempo jogado, sessões, saídas, mortes e última atividade por jogador. Os totais são persistidos pelo add-on sem expiração e continuam acumulando entre reinicializações.
8. Em **Configuração**, edite o `server.properties` da instância selecionada. **Salvar** grava o arquivo sem interromper jogadores; **Salvar e reiniciar** grava e reinicia somente essa instância para aplicar as opções. O editor detecta alterações simultâneas e pede para recarregar antes de sobrescrever. Nome do mundo, portas, transporte, allowlist e telemetria são controlados pela configuração do add-on e não podem ser alterados por esse editor. É necessário MinecraftRCON Multi **0.3.3** ou superior.

### No celular e como aplicativo

O seletor de instâncias aparece no centro da barra superior do celular quando há mais de um mundo configurado. Ao trocar de instância, telemetria, comandos, Feed e estatísticas passam a usar o mundo escolhido.

Para instalar, abra o MineDev por **HTTPS** no navegador do celular. No Chrome para Android, use o menu **⋮ → Instalar app** (ou **Adicionar à tela inicial**, conforme a versão). No Safari para iPhone, use **Compartilhar → Adicionar à Tela de Início**. O manifesto e os ícones estão em `manifest.json` e `assets/`. O aplicativo instalado continua exigindo login e conexão com o servidor; dados de autenticação e da API não são guardados para uso offline.

A API confirma o envio ao console do Bedrock e pode retornar linhas recentes. Confira o efeito de um comando no jogo ou nos logs do add-on.

## Acesso e solução de problemas

Acesse o painel por HTTPS e restrinja a hospedagem à rede local ou à VPN. Mantenha todas as portas RCON (`19233–19237/TCP`) fora do encaminhamento de portas da internet. Para uso com proxy HTTPS, configure o servidor PHP para reconhecer a conexão como HTTPS e emitir o cookie de sessão com `Secure`.

| Sintoma | O que conferir |
| --- | --- |
| Página retorna HTTP 500 | Veja o log do Apache/PHP. Confirme que o `.env` foi criado, é legível pelo PHP e preserva `APP_SECRET` e o hash da senha. Verifique as permissões de `storage/` e se o Apache aceita as diretivas do `.htaccess`. |
| A tela mostra **Token pendente** | Preencha `BEDROCK_API_TOKEN` com o token do add-on. |
| O add-on retorna HTTP 401 ou 403 | Confira se os tokens são iguais e se `rcon_allowed_ips` permite o IP de origem do PHP. |
| O painel mostra **Sem conexão** | A partir do host do PHP, consulte `curl http://192.168.1.50:19233/health` (troque para a porta da instância). O retorno `{"status":"ready"}` indica que a API responde e a sessão Bedrock correspondente está ativa. |
| Trocar a instância mostra o mesmo mundo ou erro de identidade | Confira `BEDROCK_INSTANCES` no `.env`: cada ID deve apontar à API da sua posição na lista do add-on (`19233`, `19234` etc.). O MineDev bloqueia dados e comandos quando a API responde com outro `instance_id`. |
