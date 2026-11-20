# Acesso externo aos mundos Bedrock

Este guia trata somente das portas de jogo. Primeiro confirme que cada instância funciona dentro da rede local. Veja a [configuração do add-on](../MinecraftRCON/DOCS.md) e anote o `udp_port` de cada mundo.

## Antes de configurar o roteador

1. Reserve por DHCP um IP local fixo para o Home Assistant.
2. Compare o IPv4 WAN do roteador com o IPv4 público mostrado por um serviço como `https://api.ipify.org`. Se forem diferentes, procure outro roteador em cascata ou CGNAT. Em CGNAT, peça ao provedor um IPv4 público ou escolha uma solução de túnel/proxy UDP compatível.
3. Confirme que o firewall e as políticas do provedor permitem as portas necessárias.

## Portas por instância

Para cada item configurado, o `udp_port` identifica a porta de entrada do jogo. A porta seguinte (`udp_port + 1`) é reservada pelo add-on para o servidor IPv6. O cliente Bedrock também inicia a sinalização no `server-port` TCP e negocia o tráfego do jogo em UDP.

O add-on não cria regras no roteador. Faça o encaminhamento manual, sempre para o IP local fixo do Home Assistant. Exemplo para uma instância na porta 19132:

| Protocolo | Porta externa | Destino | Porta interna |
| --- | ---: | --- | ---: |
| TCP | 19132 | IP local do Home Assistant | 19132 |
| UDP | Faixa de jogo configurada | IP local do Home Assistant | Mesma faixa, mapeamento um a um |

Para permitir mais de uma instância, encaminhe a porta TCP de jogo de cada uma. Defina também uma faixa UDP distinta por instância em seu próprio `server.properties`, na chave `server-udp-ports`, e encaminhe essa faixa para o mesmo intervalo interno. Uma divisão possível é:

| Instância | TCP de entrada | Faixa UDP de exemplo |
| ---: | ---: | ---: |
| 1 | 19132 | 19140–19155 |
| 2 | 19134 | 19156–19171 |
| 3 | 19136 | 19172–19187 |
| 4 | 19138 | 19188–19203 |
| 5 | 19140 | 19204–19219 |

Com a instância parada, edite `/share/minecraftRCON-Multi/instances/<id>/server.properties` e reinicie o add-on. Um formato fixo aceito pelo servidor é `server-udp-ports=<início>-<fim>:<início>-<fim>`. Por exemplo, para a primeira faixa:

~~~properties
server-udp-ports=19140-19155:19140-19155
~~~

Não copie esse exemplo sem reservar as faixas para as demais instâncias. Se sua conexão exige um IP público anunciado no arquivo, use o formato oficial `IP_PUBLICO:FAIXA_EXTERNA:FAIXA_INTERNA` e atualize esse IP quando ele mudar. Não use o hostname DuckDNS nesse campo. Revise o manual do roteador: uma faixa precisa manter o mapeamento externo→interno porta a porta.

Não encaminhe as APIs de gerenciamento TCP `19133–19137`. Restrinja-as à rede local ou VPN e use `rcon_allowed_ips` como filtro adicional.

## DuckDNS

1. Crie ou use um subdomínio em [DuckDNS](https://www.duckdns.org/) e configure o app Duck DNS do Home Assistant com o token da conta.
2. Confirme nos logs do app que o registro A aponta para o IPv4 público atual.
3. No Minecraft, adicione o hostname sem `http://` ou `https://`. Informe a porta TCP de jogo da instância, por exemplo `19132` para a primeira ou `19134` para a segunda.

Um nome DuckDNS aponta para o endereço IP; ele não cria encaminhamento de porta nem traduz o tráfego do Minecraft. Para serviços em portas diferentes, use o mesmo hostname e informe a porta do mundo na entrada do jogo.

## Cloudflare DNS

Crie um registro **A** para o IPv4 público e deixe **Proxy status: DNS only** (nuvem cinza). O proxy HTTP normal da Cloudflare não transporta o tráfego UDP do jogo. A porta encaminhada no roteador continua necessária. Um plano/produto específico que ofereça proxy UDP é uma arquitetura separada.

DuckDNS e Cloudflare DNS podem coexistir; cada nome precisa apontar ao IP público atual. Se escolher apontar um CNAME de DuckDNS para seu domínio Cloudflare, mantenha a política DNS-only e confira a atualização do endereço.

## Testar

1. Em outro acesso à internet, desligue o Wi-Fi do celular e use 4G/5G.
2. No Minecraft, adicione o hostname como endereço e a porta TCP de jogo configurada como porta. Não inclua esquema HTTP.
3. Confirme a entrada no mundo e procure a entrada do jogador nos logs/eventos da instância.
4. Se o servidor aparecer na lista, mas a entrada falhar, verifique a faixa UDP e o endereço anunciado. Uma resposta HTTP na porta TCP não comprova que o UDP funciona.

## Diagnóstico rápido

| Sintoma | Verificação |
| --- | --- |
| Nome resolve para IP antigo | Confira o registro A e o atualizador DuckDNS/Cloudflare. |
| Funciona na rede local, não no 4G/5G | Revise IPv4 público, CGNAT, encaminhamento TCP, faixa UDP, firewall e configuração de dados móveis do Minecraft. |
| A instância não inicia após escolher outra porta | Verifique se os pares UDP não se sobrepõem e se o host não usa essas portas em outro serviço. |
| O mundo certo não abre | Confira ID, `world_name`, nome da pasta dentro de `worlds/` e versão Bedrock do cliente. |

O nome de menus e os campos variam por roteador. Para o encaminhamento, informe protocolo, porta externa, endereço local do Home Assistant e porta/faixa interna. Preserve regras existentes de outros serviços.
