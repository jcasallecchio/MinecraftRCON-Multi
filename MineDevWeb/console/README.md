# MineDev Console

Painel web do MinecraftRCON Multi para administrar servidores **Minecraft Bedrock Edition**. Mostra jogadores, mundo, versão, memória do processo e tempo de atividade. Permite selecionar instâncias, acompanhar entradas, saídas e mortes, consultar estatísticas por mundo, enviar comandos, e iniciar, parar ou reiniciar o servidor selecionado. O painel funciona no computador e no celular.

O navegador acessa o painel pelo Apache. O PHP chama as APIs RCON na rede de confiança, em `19233–19237/TCP`, usando os tokens privados configurados no `.env`. Nunca encaminhe essas portas pela internet.

## Requisitos

- [MinecraftRCON Multi](../MinecraftRCON/DOCS.md), com as APIs v1 acessíveis a partir do servidor web.
- Apache 2.4 com PHP, `mod_rewrite` e suporte a `.htaccess` para a pasta do console. [Configuração de .htaccess no Apache](https://httpd.apache.org/docs/2.4/howto/htaccess.html)
- PHP 8.2 ou mais recente, em uma versão que ainda receba correções de segurança. Habilite `curl` no PHP usado pelo Apache e tenha o PHP de linha de comando disponível para a configuração inicial. [Versões mantidas do PHP](https://www.php.net/supported-versions.php) · [Extensão cURL](https://www.php.net/manual/en/book.curl.php)

No Home Assistant OS, use o [add-on MineDev Web](../MineDevWeb/DOCS.md) deste repositório. Ele já inclui Apache/PHP, cURL e `mod_rewrite` e copia o painel para `/share/htdocs/minedev`. Para instalar em um servidor Debian/Ubuntu separado, siga o [guia de hospedagem](../docs/hospedagem-minedev.md). Nesse caso, os cálculos da seed exigem que `storage/` também esteja acessível ao add-on MinecraftRCON Multi em `/share`.

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

   O catálogo busca comandos, aliases, opções de argumentos e identificadores de itens, blocos e entidades nos metadados versionados do repositório oficial [Mojang/bedrock-samples](https://github.com/Mojang/bedrock-samples). Quando o catálogo é consultado, o servidor web verifica se há uma nova versão estável; a cópia privada em `storage/bedrock_catalog.json` é atualizada no máximo uma vez a cada 24 horas. Os arquivos distribuídos em `assets/bedrock-items.json` e `assets/bedrock-command-data.json` são usados como fallback se o GitHub estiver indisponível. Para atualizar automaticamente, o PHP precisa ter cURL habilitado, acesso HTTPS de saída a `raw.githubusercontent.com` e permissão de escrita em `storage/`. O autocomplete sugere jogadores/seletor, itens, blocos, entidades e opções válidas para a posição do argumento; digitar filtra a lista. A tela mostra as versões do servidor e dos metadados, além da quantidade de itens carregados. O catálogo oficial cobre itens vanilla; itens personalizados de add-ons não são descobertos automaticamente.
5. Em **Histórico**, consulte os eventos mais recentes e use **Exportar CSV** para salvar o período disponível. **Limpar tela** limpa apenas o terminal. Os registros são salvos em `storage/`. Quando o painel é acessado, a limpeza periódica remove os eventos que passaram da retenção.
6. O quadro do **Console** abre em **Terminal**, com comandos e retornos. Clique em **Feed** para ver, no mesmo quadro, entradas, saídas e mortes do mundo selecionado; o botão passa a **Terminal** para voltar. O campo de comandos permanece na mesma posição nas duas visualizações; ao enviar um comando pelo Feed, o painel volta ao Terminal para mostrar o retorno. O Feed carrega até 20 eventos recentes e consulta novos eventos automaticamente a cada cinco segundos enquanto está aberto. **Limpar tela** limpa a visualização aberta, sem apagar o histórico persistente; os eventos do Feed reaparecem quando ele é carregado novamente. O add-on mantém os eventos do Feed por até sete dias.
7. Em **Mapa**, explore os chunks já visitados no mundo da instância selecionada. O mapa mostra a data do snapshot; **Recarregar mapa** atualiza a visualização do arquivo publicado, sem reler o mundo ativo. Para novas instâncias, publique um snapshot e, se quiser a exploração pela seed, gere também a camada inicial descrita abaixo.
8. Em **Estatísticas**, compare tempo jogado, sessões, saídas, mortes e última atividade por jogador. Os totais são persistidos pelo add-on sem expiração e continuam acumulando entre reinicializações.
9. Em **Configuração**, edite o `server.properties` da instância selecionada. **Salvar** grava o arquivo sem interromper jogadores; **Salvar e reiniciar** grava e reinicia somente essa instância para aplicar as opções. O editor detecta alterações simultâneas e pede para recarregar antes de sobrescrever. Nome do mundo, portas, transporte, allowlist e telemetria são controlados pela configuração do add-on e não podem ser alterados por esse editor. É necessário MinecraftRCON Multi **0.3.3** ou superior.

### Preparar uma instalação nova do mapa

O mapa usa dois conjuntos independentes. O snapshot de terreno mostra chunks gravados no mundo; a exploração pela seed usa um manifesto e imagens de biomas/slime, além de setores calculados sob demanda. Para um ID novo, gere o manifesto e as imagens-base com o comando abaixo. Sem esses arquivos, o mapa de terreno ainda pode funcionar, mas a API da seed informa que os dados da seed estão indisponíveis.

Para preparar o mapa de uma instância nova:

1. Configure o mundo no add-on e anote o ID e `world_name`. Para um mundo novo com seed conhecida, [grave `level-seed` antes da primeira inicialização](../MinecraftRCON/DOCS.md#definir-a-seed-de-um-mundo-novo). Em um mundo importado, use a seed real do mundo; alterar `server.properties` depois não muda o terreno existente.
2. Publique o snapshot de chunks conforme a seção seguinte.
3. Instale MinecraftRCON Multi **0.4.0 ou superior**. No terminal do Home Assistant com acesso ao Docker do Supervisor, execute o gerador incluído no add-on. Troque ID e seed pelos valores da sua instância. O exemplo usa o caminho padrão do MineDev e o raio máximo de 32.768 blocos:

~~~sh
docker exec NOME_DO_CONTAINER_DO_ADDON python3 /seed_bootstrap.py \
  --instance-id main --seed 757067671151835873 --radius 32768
~~~

Descubra o nome do contêiner com `docker ps --format '{{.Names}}'` (procure `minecraft_rcon_multi`) e substitua `NOME_DO_CONTAINER_DO_ADDON`. O comando pode levar vários minutos e grava o manifesto e as imagens-base em `storage/`. Ele calcula biomas e slime chunks **no Home Assistant**. O worker calcula estruturas, masmorras, cavernas, fósseis e veios nos setores solicitados quando os filtros são usados. Para reinstalar a camada de uma instância existente após alterar a seed, adicione `--replace`. Não use uma seed diferente da do mundo já criado. Se o MineDev estiver em outro caminho dentro de `/share`, informe `--storage /share/caminho/minedev/storage` e configure o mesmo caminho em `seed_storage_path` no add-on.

O terminal precisa ter permissão para executar `docker`. Em Home Assistant OS, use um terminal com acesso ao host. O comando só gera dados de mapa; não interrompe os mundos. A seed pode ser definida no mundo novo antes de sua criação ou obtida do mundo existente; `level-seed` em `server.properties` deve corresponder ao mundo real. O worker aguarda a pasta `storage` aparecer, mesmo quando o MineDev é instalado depois do add-on.

### Publicar ou atualizar um mapa

O mapa é um snapshot web privado por instância. Ele representa os chunks gravados no mundo na hora da cópia; não é um mapa de seed nem acompanha o jogo em tempo real. Os arquivos ficam dentro de `storage/`, que o `.htaccess` bloqueia para acesso direto. A rota autenticada `map/<id>/` entrega o visualizador e os tiles somente a uma sessão MineDev válida.

Para gerar ou atualizar os mapas do Overworld, Nether e End:

1. Na página [oficial de downloads do uNmINeD](https://unmined.net/downloads/), escolha **Command Line Interface (CLI) → Windows x86 64bit**. Baixe a variante maior, que não depende de um runtime .NET instalado, e extraia **todos** os arquivos do ZIP em uma pasta vazia, por exemplo `C:\Ferramentas\uNmINeD\`. Confirme que `unmined-cli.exe` está nessa pasta e execute `& 'C:\Ferramentas\uNmINeD\unmined-cli.exe' --help` no PowerShell antes de continuar. A [documentação oficial do CLI](https://unmined.net/docs/cli/getting-started/) descreve a extração e o comando `web render`. O repositório não distribui o executável; confira os termos da licença no site oficial.
2. Instale e configure o [app Samba share do Home Assistant](https://www.home-assistant.io/common-tasks/os/#installing-and-using-the-samba-share-app) para expor o compartilhamento `share` à rede local. No Windows, confira no Explorador que `\\IP_DO_HA\share\minecraftRCON-Multi` e `\\IP_DO_HA\share\htdocs\minedev` abrem com as credenciais configuradas.
3. No Windows que consegue acessar o compartilhamento do servidor, abra PowerShell na pasta instalada `minedev/` e execute o script abaixo. Ele lê os dados da instância no `.env`, aguarda a telemetria confirmar o encerramento da sessão Bedrock, copia o LevelDB fechado uma única vez, tenta renderizar as três dimensões em pastas separadas e publica os mapas por troca atômica. Dimensões ainda sem chunks acessíveis ao uNmINeD são ignoradas e aparecem como indisponíveis no MineDev até um novo snapshot. O script sempre solicita a inicialização da instância ao terminar. Ele aceita `index.html` e `unmined.index.html` na saída do uNmINeD CLI.

~~~powershell
& '.\tools\refresh-map.ps1' `
  -ServerShareRoot '\\192.168.1.50\share\minecraftRCON-Multi' `
  -InstanceId 'main' `
  -WorldName 'Abydos' `
  -RendererPath 'C:\Ferramentas\uNmINeD\unmined-cli.exe'
~~~

   Troque o compartilhamento, o nome e o ID pelos seus dados. O script usa o token do `.env` apenas em memória e não o imprime. Depois que `/health` confirmar a instância online, abra **Mapa** no MineDev e clique em **Recarregar mapa**. Os arquivos do mundo e os mapas ficam fora do GitHub.

Na instalação usada neste projeto, Abydos e Colinas têm exportações próprias. Os mapas representam somente os chunks salvos no momento de cada snapshot e não são distribuídos no GitHub. Novas instâncias mostram um estado vazio até que seu mapa seja publicado.

Cada instância precisa de uma exportação própria. O Overworld permanece em `storage/maps/<id>/`, e Nether e End, quando houver chunks salvos, ficam em `storage/maps/<id>/nether/` e `storage/maps/<id>/end/`. O seletor de dimensão do MineDev mostra o snapshot correspondente; somente jogadores na dimensão selecionada recebem marcadores clicáveis. O uNmINeD usa as coordenadas X/Z do jogo para o mapa e a grade. O script instala `assets/minedev-map-bridge.js` em cada exportação. Os ícones de Fortaleza, Bastião, Cidade do End e Portal do End são provisórios; seus filtros ficam indisponíveis até existir uma fonte de posições calculadas para essas estruturas. Personalizações devem ser feitas no código do MineDev; editar o HTML gerado não é persistente.

Para manter marcadores personalizados entre snapshots, edite `storage/map-overlays/<id>/custom.markers.js` na instalação do console. Na primeira atualização de um mapa existente, o script copia para esse local o arquivo de marcadores publicado anteriormente. Nas atualizações seguintes, essa cópia privada é a origem dos marcadores. O diretório `storage/` continua protegido contra acesso HTTP direto.

Na tela **Mapa**, mova o cursor ou toque no terreno para consultar X/Z. Digite `X, Z` e clique em **Ir** para centralizar. Estruturas usam os ícones correspondentes em `assets/icons`; clique em um ícone ou marcador de jogador para ver detalhes e copiar X/Z. Estruturas exibem a origem prevista, bioma estimado quando disponível e distância horizontal ao jogador online mais próximo; posição Y não é calculada para estruturas. O mapa de terreno abre de `-122.880` a `+122.880` em X/Z, com visão geral até `--zoomout=10`. O snapshot mostra somente chunks que existem no mundo salvo. As camadas calculadas pela seed permanecem limitadas ao raio publicado nos metadados (atualmente 32.768 blocos); a ampliação do mapa de terreno não estende automaticamente essas previsões.

O zoom vai de uma visão ampla até **8 pixels por bloco (8:1)**. Novos snapshots são gerados pelo uNmINeD com `--zoomin=3`. Para um mapa antigo publicado em 1:1, `python tools/expand-map-zoom.py storage/maps/<id>` cria os níveis 2:1, 4:1 e 8:1 a partir das cores já salvas, sem parar o jogo. Esse aumento preserva a aparência em pixels do snapshot antigo; o próximo snapshot renderizado pelo uNmINeD produzirá os tiles de alta resolução diretamente. O script exige Python 3 com Pillow (`python -m pip install Pillow`).

A previsão de biomas usa amostras de 16 em 16 blocos, exibidas sem desfoque por baixo dos chunks salvos. O terreno real do snapshot aparece por cima, sem transparência nem tintura. Fora dos chunks salvos, as cores continuam sendo previsões da seed, não detalhes reais do terreno.

### Exploração pela seed

O MineDev acrescenta ao snapshot camadas de **biomas, locais e slime chunks** para o Overworld, inclusive fora dos chunks já explorados. O botão de mapa no canto superior direito abre o modal de estruturas: Biomas aparece no grid, antes de Spawn Point, ligado por padrão. Ao desligá-lo, o mapa oculta tanto a camada de biomas quanto o snapshot do terreno explorado; os marcadores e a grade continuam disponíveis. Os demais ícones começam desligados e alternam seus marcadores. A grade tem cinco colunas e mostra Biomas e os 27 tipos sem barra de rolagem. Dungeon é um único filtro que agrupa todos os tipos de masmorra. Clique no mapa para consultar o bioma; a indicação de slime chunk aparece quando esse filtro estiver ligado. Os arquivos são separados por instância e entregues apenas após login. O navegador apenas solicita os setores visíveis; estruturas, masmorras e as camadas de cavernas, fósseis e veios são calculadas no Home Assistant pelo worker do add-on MinecraftRCON Multi, sem transferir esses cálculos para o PC ou celular e sem parar os mundos. É necessário o add-on **0.4.1 ou superior**, o worker habilitado e acesso à pasta `storage` do MineDev. A camada base de biomas/slime precisa ter sido gerada para a instância.

O catálogo mostra Spawn Point, Slime Chunk, Village, Ancient City, Dungeon, Mansion, Monument, Outpost, Mineshaft, Ruined Portal, Jungle Temple, Desert Temple, Witch Hut, Treasure, Shipwreck, Igloo, Ocean Ruins, Fossil, Cave, Ravine, Lava Pool, Geode, Ore Veins, Desert Well, Trail Ruins, Trial Chamber e Camp, nessa ordem. Fóssil, Caverna e Veio de minério são calculados por setor quando algum desses filtros está ativo. Veios de ferro e cobre têm nomes separados; fósseis e veios incluem a coordenada Y. Cavernas são representadas por pontos amostrados em bolsões subterrâneos, não por um contorno contínuo de toda a rede. Masmorras aparecem separadas pelo tipo de gerador: zumbi, esqueleto ou aranha, com X/Y/Z. O mapa divide o raio de 32.768 blocos em setores de 2.048 blocos e calcula as estruturas disponíveis e masmorras conforme cada setor é carregado; isso dispensa uma varredura monolítica de toda a área. O mapa desenha até 2.500 marcadores da área visível. A lista de posições próximas considera os setores carregados.

As estruturas e masmorras usam [cubiomes-bedrock](https://github.com/FragrantResult186/cubiomes-bedrock), sob licença MIT, fixado no commit `294c72ab0392dd818a91d293dbd5774d4874d64b`. As camadas de cavernas, fósseis e veios usam [helix-bedrock/worldgen](https://github.com/helix-bedrock/worldgen), fixado no commit `c7e981cd963482334e172e413dface334b1a7ffa`, com dados [helix-data](https://github.com/helix-bedrock/helix-data), commit `1c592be62d851b89752d0dd12c99dee022223983`. Esse motor modela Bedrock **1.26.60**; o servidor deste projeto usa **1.26.66.1**. O mapa de biomas é amostrado em **Y 64**. O cálculo dos novos marcadores pode levar vários minutos para cada setor de 2.048 blocos e ocorre apenas quando seus filtros estão ativos. Pacotes que alterem a geração e chunks criados em outra versão podem mudar posições. O terreno do snapshot mostra o que foi salvo no mundo.

As camadas de biomas e slime chunks das instâncias atuais já estão publicadas no armazenamento privado. Durante o uso normal do mapa, nenhuma ferramenta de desenvolvimento precisa ficar rodando em um PC.

#### Cálculo progressivo de setores

Quando a API recebe um setor que ainda não está no cache, grava um trabalho privado em `storage/seed-jobs/` e responde `202`. O mapa aguarda o resultado. O add-on **MinecraftRCON Multi 0.4.1 ou posterior** processa essa fila dentro do Home Assistant, usando o mesmo compartilhamento `/share` do MineDev. O PHP cria a pasta da fila com suas permissões; o worker aguarda essa pasta para evitar conflito de gravação entre Apache e Home Assistant. Nas opções do add-on, mantenha **Calcular setores da seed do MineDev no Home Assistant** ligado e `seed_storage_path` apontando para `/share/htdocs/minedev/storage` (ou para a pasta `storage` real da instalação). Os binários dos motores são compilados durante a construção da imagem do add-on, usando as revisões fixas descritas nesta seção.

O worker processa um setor de 2.048 blocos por vez, valida seed e revisão antes de publicar o resultado por troca atômica e recupera trabalhos interrompidos. Os filtros de estruturas usam o cache existente; masmorras têm uma camada separada, calculada no Home Assistant somente quando o filtro Dungeon é selecionado. O cálculo é feito em baixa prioridade e não reinicia o Bedrock. O browser e o computador que o usuário está usando apenas enviam a solicitação e mostram o resultado; eles não executam os cálculos.

### Jogadores ao vivo (etapa 4)

O painel **Jogadores ao vivo** usa a telemetria de nomes online e o comando Bedrock `querytarget` pela API RCON existente. O teste de `querytarget @a` retornou dimensões e coordenadas, mas não nomes; por isso o MineDev consulta cada nome com um seletor específico para associar a posição com segurança. A atualização é limitada por instância a aproximadamente **6–30 segundos**, conforme a quantidade de jogadores, para preservar a cota de comandos do servidor. O MineDev não instala pacote de script nem precisa reiniciar o mundo para mostrar posições.

Jogadores da **dimensão selecionada** aparecem no mapa; clique no marcador ou na lista para centralizar. A lista também informa jogadores das outras dimensões, sem centralizá-los no mapa aberto. Respostas incompletas, jogadores offline e amostras vencidas não geram marcadores. A localização é uma amostra, não um rastreamento contínuo. Os dados ficam em cache privado por instância em `storage/map-players-<id>.json`; o mapa exige login e não expõe o token RCON ao navegador.

O ponto no mapa conserva X/Z fracionários do RCON. Os números exibidos seguem as coordenadas de bloco do jogo: X/Z são arredondados para baixo, inclusive quando negativos. Em testes com jogadores em pé, o Y informado por `querytarget` ficou **1,62 bloco acima** do Y visto no jogo; o painel subtrai essa altura para exibir o bloco dos pés. Se o jogador estiver agachado, nadando ou em outra pose, o Y pode diferir até que a pose seja obtida por outra fonte.

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
