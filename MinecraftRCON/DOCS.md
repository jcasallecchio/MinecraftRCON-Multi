# MinecraftRCON Multi: instalação e configuração

Este add-on gerencia de uma a cinco instâncias Bedrock simultâneas. Cada mundo roda em um processo Bedrock separado, com sua própria pasta e sua própria API. O nome do projeto, o slug e o caminho de dados são diferentes do MinecraftRCON original.

## Requisitos

- Home Assistant OS com Supervisor e loja de aplicativos.
- Host x86-64 (amd64) compatível com o servidor dedicado oficial Bedrock para Linux.
- Memória, CPU e espaço em disco para os mundos e respectivos backups. Cada servidor consome recursos mesmo sem jogadores conectados.
- Minecraft Bedrock em versão compatível com a versão mais recente do servidor.

## Instalar

1. Adicione `https://github.com/jcasallecchio/MinecraftRCON-Multi` em **Configurações → Aplicativos → ⋮ → Repositórios**.
2. Instale **MinecraftRCON Multi**.
3. Na configuração do add-on, defina `rcon_token` com um segredo aleatório de pelo menos 32 caracteres. Guarde-o em um gerenciador de senhas.
4. Em **Mundos/instâncias**, configure de 1 a 5 entradas usando os campos do formulário. A lista começa vazia; para adicionar um mundo, use a ação de adicionar item. Não é necessário escrever JSON.
5. Confira os logs. Na primeira inicialização, cada mundo baixa sua cópia do servidor Bedrock. Aguarde a mensagem de servidor pronto para cada instância.
6. Adicione manualmente no Minecraft o IP local do Home Assistant e a porta de conexão da instância.

## Configurar instâncias

Na aba de configuração do add-on, edite a lista **Mundos/instâncias**. Cada item tem estes campos:

| Campo | Obrigatório | Descrição |
| --- | --- | --- |
| `id` | Sim | Identificador exclusivo: letras minúsculas, números, `_` ou `-`, até 32 caracteres. Também define a pasta da instância. |
| `name` | Sim | Nome amigável mostrado nos logs e no MineDev. |
| `world_name` | Sim | Valor `level-name` do mundo Bedrock. Para usar um mundo já copiado, deve corresponder ao nome da pasta em `worlds/`. |
| `udp_port` | Sim | Porta de conexão do mundo, entre 1024 e 65533. O add-on usa esse número para sinalização TCP e tráfego UDP do NetherNet. |

O formulário começa com uma instância de exemplo:

- ID: `main`
- Nome exibido: `Abydos`
- Nome da pasta do mundo: `Abydos`
- Porta UDP: `19132`

Para criar outra, adicione uma nova entrada no formulário e preencha, por exemplo:

- ID: `creative`
- Nome exibido: `Criativo`
- Nome da pasta do mundo: `Criativo`
- Porta UDP: `19134`

Use uma porta de conexão distinta para cada instância. Com NetherNet, o add-on configura o número tanto para a sinalização TCP (`server-port`) quanto para o tráfego UDP do jogo (`server-udp-ports`). A antiga porta IPv6 seguinte não é usada pelo NetherNet. Os exemplos padrão são `19132`, `19134`, `19136`, `19138` e `19140`. A API RCON usa TCP `19233` para o primeiro item da lista, `19234` para o segundo e assim por diante, até `19237`.

Para acesso externo atrás de NAT, preencha **Hostname público dos mundos** com o seu DuckDNS ou outro DNS público, sem `http://` e sem porta. O add-on resolve o IPv4 ao iniciar e anuncia `IPv4:porta:porta` em `server-udp-ports` de cada instância. Reinicie o add-on se o IP público mudar. O campo é opcional para uso somente local.

Não configure portas de conexão repetidas. O add-on valida IDs, nomes, quantidade e colisões de portas antes de iniciar. A ordem da lista define a porta TCP RCON; mantenha a ordem sincronizada com o `.env` do MineDev. Depois de alterar a lista, salve e reinicie o add-on. IDs definem as pastas persistentes dos mundos; mantenha-os estáveis.

Se houver um proxy HTTPS, outro add-on ou qualquer serviço no mesmo host usando a porta TCP ou UDP escolhida, libere essa porta ou selecione outra antes de iniciar o mundo. Com NetherNet, o Bedrock pode registrar `Server started` mesmo sem conseguir abrir a porta de sinalização; o add-on verifica a disponibilidade e exige a confirmação `Accepting clients on` para declarar a instância pronta.

Se você instalou a versão `0.1.0`, converta cada objeto que estava em `instances_json` em um item da lista visual. Preserve `id`, `name`, `world_name` e `udp_port`; o código ainda lê a opção antiga se a nova lista não tiver sido criada.

Por padrão, **Exigir allowlist de jogadores** fica desligado e qualquer jogador pode entrar. A allowlist é de gamertags, não de IPs. Se ativar, use o console RCON para adicionar cada jogador, por exemplo `allowlist add JCasallecchio`. A opção `rcon_allowed_ips` é separada e restringe quem pode chamar a API do console.

**Enviar telemetria do servidor Bedrock** vem ligado; isso permite o envio opcional de dados do servidor à Mojang/Microsoft. Essa opção é independente da telemetria local do MineDev e pode ser desligada aqui.

## Arquivos de cada mundo

Os dados ficam isolados em:

~~~text
/share/minecraftRCON-Multi/instances/<id>/
├── worlds/
├── server.properties
├── server.log
├── player_events.jsonl
├── player_stats.json
├── rcon_commands.log
└── backups/
~~~

As APIs, backups, logs, estatísticas e eventos de um ID não se misturam aos de outro. O add-on define as portas e o `level-name` de cada instância na inicialização. Ele usa `transport=nethernet` e desativa `enable-lan-visibility`, para evitar conflito entre anúncios LAN; conecte pelo IP e porta manualmente.

## Migrar um mundo do MinecraftRCON original

O novo repositório usa outro slug e outra pasta. Instalar este add-on não atualiza o add-on antigo nem move os dados automaticamente.

1. Faça backup do mundo e desligue o add-on antigo. Não copie arquivos de um mundo enquanto ele estiver sendo gravado.
2. Inicie MinecraftRCON Multi uma vez para ele criar `/share/minecraftRCON-Multi/instances/<id>/` e depois pare o novo add-on.
3. Copie a pasta do mundo antigo de `/share/minecraftRCON/worlds/<mundo>` para `/share/minecraftRCON-Multi/instances/<id>/worlds/<mundo>`.
4. Defina `world_name` para o nome exato dessa pasta e configure `server.properties` conforme necessário. Não copie binários do Bedrock, arquivos de atualização ou arquivos de transação do add-on antigo.
5. Inicie o novo add-on e verifique os logs e o acesso local. Preserve o backup original até confirmar o mundo migrado.

Você pode copiar também `permissions.json`, `allowlist.json`, `structures/`, `behavior_packs/` e `resource_packs/` somente se esses dados forem necessários ao mundo. Revise os caminhos de pacotes e faça uma cópia de segurança antes. Os backups do add-on antigo não são automaticamente listados pelos novos endpoints.

## Portas e API RCON

| Instância na lista | Jogo TCP e UDP | API HTTP RCON |
| ---: | ---: | ---: |
| 1 | `udp_port` (padrão 19132) | TCP `19233` |
| 2 | `udp_port` (exemplo 19134) | TCP `19234` |
| 3 | `udp_port` (exemplo 19136) | TCP `19235` |
| 4 | `udp_port` (exemplo 19138) | TCP `19236` |
| 5 | `udp_port` (exemplo 19140) | TCP `19237` |

As APIs usam `19233–19237/TCP` para não disputar portas de jogo. **Não encaminhe nenhuma porta TCP da API RCON para a internet.** Restrinja o acesso à rede de confiança ou a uma VPN e, se necessário, informe os IPs em `rcon_allowed_ips` separados por vírgulas.

Cada porta TCP RCON serve apenas a instância na mesma posição da lista. A API mantém o contrato v1 existente: `/health`, `/api/v1/info`, `/api/v1/telemetry`, `/api/v1/events`, `/api/v1/player-stats`, `/api/v1/commands`, `/api/v1/control` e endpoints de backups. A telemetria também informa `instance_id`, `game_port` e `game_portv6`. Use o `rcon_token` como Bearer token. `/api/v1/control` aceita `start`, `stop` e `restart` e desconecta jogadores quando para/reinicia um mundo.

Para iniciar, parar ou reiniciar um mundo individual pelo MineDev, use o seletor da instância e os botões no cabeçalho do terminal. A ação é autenticada e exige confirmação no navegador. Reiniciar o add-on também reinicia todas as instâncias configuradas.

## Configurar o MineDev

No arquivo `.env` privado da instalação do console, associe cada ID a URL e token da API de sua posição na lista:

~~~dotenv
BEDROCK_API_TOKEN=TOKEN_LONGO_DA_API
BEDROCK_INSTANCES='[{"id":"abydos","name":"Abydos","url":"http://192.168.1.50:19233","token":"${BEDROCK_API_TOKEN}"},{"id":"creative","name":"Criativo","url":"http://192.168.1.50:19234","token":"${BEDROCK_API_TOKEN}"}]'
~~~

O mesmo `rcon_token` pode ser usado em todas as APIs deste add-on. O MineDev valida a instância no servidor PHP e não envia tokens ao navegador. Se o console estiver no mesmo host, troque `192.168.1.50` pelo IP ou nome alcançável a partir do processo PHP.

As variáveis antigas `BEDROCK_API_URL` e `BEDROCK_API_TOKEN` continuam aceitas para uma instalação de instância única, identificada como `main`. Com `BEDROCK_INSTANCES`, a página mostra o seletor e disponibiliza telemetria, feed, estatísticas e comandos para a instância escolhida.

Veja [console/README.md](../console/README.md) para instalar o painel e configurar Apache, login, permissões e `.env`.

## Acesso pela internet

Primeiro conecte todos os mundos pela rede local. Para permitir acesso externo, encaminhe TCP e UDP da porta de jogo desejada no roteador para o IP local reservado do Home Assistant. Configure DDNS em DuckDNS ou DNS convencional. A Cloudflare Proxy HTTP não transporta o tráfego do jogo Bedrock; deixe o registro DNS como **DNS only** ou use uma solução específica de proxy UDP.

Exemplo: se a instância `creative` usa a porta 19134, o jogador entra pelo hostname `meu-servidor.example` e porta 19134. Consulte o [guia externo](../docs/acesso-remoto.md) antes de alterar o roteador.

## Retenção, backups e erros

- Configure `backup_interval_hours` em horas (0 desativa o agendamento) e `backup_keep_count` de 1 a 90.
- Para backup de mundo em execução, a cópia usa `save hold`/`save resume`, que requer `allow-cheats=true`; avalie impacto em conquistas.
- Os IDs têm dados persistidos sem expiração para estatísticas, até sete dias/5.000 eventos para o feed e rotação de logs.
- Se uma instância não iniciar, consulte o log do add-on e a pasta correspondente. Erros de JSON, IDs duplicados e portas sobrepostas encerram a validação antes de abrir processos Bedrock.
- Se a lista estiver vazia, o add-on registra que não há instâncias configuradas e encerra normalmente. Adicione um mundo na configuração e inicie o add-on.
- Se uma instância falhar, o add-on é encerrado para que o Supervisor recupere o conjunto em estado consistente.

## Opções

| Opção | Uso |
| --- | --- |
| `rcon_token` | Segredo Bearer compartilhado pelas APIs. Mínimo 32 caracteres. |
| `rcon_allowed_ips` | IPs/CIDRs permitidos para as APIs, separados por vírgula. Vazio permite qualquer origem que consiga alcançar as portas. |
| `allow_list` | Exige jogadores cadastrados na allowlist de cada mundo. Desligado por padrão. |
| `emit_server_telemetry` | Habilita o envio da telemetria oficial do Bedrock à Mojang/Microsoft. Ligado por padrão. |
| `instances` | Lista de 1 a 5 instâncias editada diretamente no formulário do Home Assistant. |
| `backup_interval_hours` | Frequência de backup por instância, em horas. |
| `backup_keep_count` | Quantidade de backups retidos por instância. |
| `seed_worker_enabled` | Executa dentro do add-on os cálculos de setores solicitados pelo mapa MineDev. Ligado por padrão; não inicia cálculos sem tarefas na fila. |
| `seed_storage_path` | Caminho absoluto para `storage` do MineDev visto pelo Home Assistant. O padrão é `/share/htdocs/minedev/storage`. |

### Worker de mapas MineDev

Desde a versão 0.3.7, os setores pendentes são calculados pelo processo auxiliar dentro do add-on, usando CPU e armazenamento do Home Assistant. O navegador e o computador que acessa o MineDev apenas enviam a solicitação ao PHP e exibem o resultado. O add-on já mapeia `/share` com leitura e gravação; ajuste `seed_storage_path` se a pasta privada do MineDev estiver em outro local dentro desse compartilhamento.

O worker só calcula quando há solicitações em `seed-jobs/`. Cálculos de masmorras são isolados dos demais setores e só são enfileirados quando o filtro Dungeon é usado. A prioridade do processo é reduzida para deixar o Bedrock em primeiro plano. O add-on compila o binário durante a construção da imagem, usando uma revisão fixa do cubiomes-bedrock; reiniciar o add-on ou o Home Assistant não exige WSL nem processo auxiliar em outro computador.
