# MinecraftRCON Multi

Gerencie até cinco servidores dedicados Minecraft Bedrock simultâneos no Home Assistant. Cada instância mantém seu mundo, processo, API RCON, eventos, estatísticas e backups isolados.

1. Defina um `rcon_token` aleatório de pelo menos 32 caracteres.
2. Configure a lista **Mundos/instâncias** no formulário do add-on, informando ID, nome, pasta do mundo e porta de conexão para cada servidor.
3. Inicie o add-on e conecte cada jogador ao IP do Home Assistant e à porta de jogo correspondente. Para NetherNet, permita TCP e UDP nessa porta.

O painel web MineDev é opcional. No add-on **0.4.0 ou superior**, a camada inicial da seed e os setores solicitados pelo mapa são calculados dentro do Home Assistant. O snapshot dos chunks explorados usa o uNmINeD CLI. Consulte a aba **Documentação**, [Instalação e configuração](DOCS.md), [Acesso externo](../docs/acesso-remoto.md) e [MineDev Console](../console/README.md). As APIs RCON ficam em `19233–19237/TCP`; restrinja-as à rede local ou VPN e não as encaminhe pela internet.
