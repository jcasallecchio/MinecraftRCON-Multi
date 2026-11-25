# Acesso externo aos mundos Bedrock

Este guia trata somente das portas de jogo. Primeiro confirme que cada instância funciona dentro da rede local. Veja a [configuração do add-on](../MinecraftRCON/DOCS.md) e anote o `udp_port` de cada mundo.

## Antes de configurar o roteador

1. Reserve por DHCP um IP local fixo para o Home Assistant.
2. Compare o IPv4 WAN do roteador com o IPv4 público mostrado por um serviço como `https://api.ipify.org`. Se forem diferentes, procure outro roteador em cascata ou CGNAT. Em CGNAT, peça ao provedor um IPv4 público ou escolha uma solução de túnel/proxy UDP compatível.
3. Confirme que o firewall e as políticas do provedor permitem as portas necessárias.

## Portas por instância

Para cada item configurado, o campo `udp_port` identifica a porta do mundo. Com NetherNet, o add-on usa esse número tanto para a sinalização TCP (`server-port`) quanto para o tráfego UDP do jogo (`server-udp-ports`). A antiga configuração `server-portv6` não é necessária para o NetherNet.

O add-on não cria regras no roteador. Faça o encaminhamento manual, sempre para o IP local fixo do Home Assistant. Exemplo para uma instância na porta 19132:

| Protocolo | Porta externa | Destino | Porta interna |
| --- | ---: | --- | ---: |
| TCP | 19132 | IP local do Home Assistant | 19132 |
| UDP | 19132 | IP local do Home Assistant | 19132 |

Para permitir mais de uma instância, encaminhe TCP e UDP para a porta configurada em cada mundo. Exemplo com as portas padrão:

| Instância | TCP de entrada | UDP de entrada |
| ---: | ---: | ---: |
| 1 | 19132 | 19132 |
| 2 | 19134 | 19134 |
| 3 | 19136 | 19136 |
| 4 | 19138 | 19138 |
| 5 | 19140 | 19140 |

Ao criar uma regra, selecione TCP e UDP (ou crie duas regras com as mesmas portas). O add-on configura `server-udp-ports` automaticamente para acompanhar `udp_port`; não edite essa propriedade manualmente. Se o NAT exigir portas externas diferentes das internas, configure o mapeamento publicado pelo NetherNet conforme a documentação oficial do BDS.

Preencha **Hostname público dos mundos** nas opções do add-on com o nome DuckDNS ou outro DNS que resolva para o IPv4 WAN do roteador. Ao reiniciar, o add-on anuncia esse IPv4 nos candidatos UDP do NetherNet. Se o IP WAN mudar, atualize o DNS e reinicie o add-on para resolver o novo endereço.

Não encaminhe as APIs de gerenciamento TCP `19233–19237`. Restrinja-as à rede local ou VPN e use `rcon_allowed_ips` como filtro adicional.

## DuckDNS

1. Crie ou use um subdomínio em [DuckDNS](https://www.duckdns.org/) e configure o app Duck DNS do Home Assistant com o token da conta.
2. Confirme nos logs do app que o registro A aponta para o IPv4 público atual.
3. No Minecraft, adicione o hostname sem `http://` ou `https://`. Informe a porta TCP de jogo da instância, por exemplo `19132` para a primeira ou `19134` para a segunda.

Um nome DuckDNS aponta para o endereço IP; ele não cria encaminhamento de porta nem traduz o tráfego do Minecraft. Para serviços em portas diferentes, use o mesmo hostname e informe a porta do mundo na entrada do jogo.

## Cloudflare DNS

Se você já atualiza o IP público pelo DuckDNS, crie um registro **CNAME** para o nome do Minecraft apontando ao hostname DuckDNS (por exemplo, `minecraft.seudominio.com` → `seunome.duckdns.org`). Deixe **Proxy status: DNS only** (nuvem cinza). Assim, quando o DuckDNS atualizar o endereço, o nome da Cloudflare acompanhará a mudança. Confirme que o CNAME resolve para o IPv4 WAN atual.

Sem DuckDNS ou outro DDNS, use um registro **A** para o IPv4 público e atualize-o sempre que esse IP mudar. O proxy HTTP normal da Cloudflare não transporta o tráfego UDP do jogo. Em ambos os casos, mantenha o encaminhamento TCP/UDP da porta da instância no roteador. Um produto específico de proxy UDP é uma arquitetura separada.

## Testar

1. Em outro acesso à internet, desligue o Wi-Fi do celular e use 4G/5G.
2. No Minecraft, adicione o hostname como endereço e a porta TCP de jogo configurada como porta. Não inclua esquema HTTP.
3. Confirme a entrada no mundo e procure a entrada do jogador nos logs/eventos da instância.
4. Se o servidor aparecer na lista, mas a entrada falhar, verifique o encaminhamento UDP e o endereço anunciado. Uma resposta HTTP na porta TCP não comprova que o UDP funciona.

## Diagnóstico rápido

| Sintoma | Verificação |
| --- | --- |
| Nome resolve para IP antigo | Confira o A ou CNAME na Cloudflare, o atualizador DuckDNS e o IP WAN atual. Aguarde o cache DNS expirar ou limpe o cache do dispositivo antes de repetir o teste. |
| Funciona na rede local, não no 4G/5G | Revise IPv4 público, CGNAT, encaminhamento TCP/UDP, firewall e configuração de dados móveis do Minecraft. |
| A instância não inicia após escolher outra porta | Verifique se as portas de conexão das instâncias são distintas e se um proxy HTTPS ou outro serviço no host ocupa a porta TCP/UDP escolhida. |
| O mundo certo não abre | Confira ID, `world_name`, nome da pasta dentro de `worlds/` e versão Bedrock do cliente. |

O nome de menus e os campos variam por roteador. Para o encaminhamento, informe protocolo, porta externa, endereço local do Home Assistant e porta/faixa interna. Preserve regras existentes de outros serviços.
