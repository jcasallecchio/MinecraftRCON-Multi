# Instalação completa do zero

Este roteiro reúne os passos na ordem necessária para instalar o add-on, o MineDev e o mapa em um Home Assistant OS novo. O servidor web PHP/Apache, o uNmINeD CLI e os pacotes opcionais de conteúdo são dependências externas; seus binários e os mundos não são distribuídos neste repositório.

## 1. Preparar o Home Assistant

- Use um host **amd64**, com Supervisor e espaço para uma cópia do Bedrock por instância, mundos, backups e mapas.
- Garanta acesso HTTPS de saída para o download oficial do Bedrock e para as dependências compiladas durante a instalação.
- Para administrar arquivos em `/share` e gerar a camada da seed, tenha um terminal/SSH com acesso ao Docker do host. Para o snapshot de terreno, tenha também acesso aos arquivos por compartilhamento e um computador Windows com PowerShell e uNmINeD CLI.

## 2. Instalar o add-on e criar os mundos

1. Adicione este repositório à loja de add-ons do Home Assistant e instale **MinecraftRCON Multi**. Veja [instalação e configuração](../MinecraftRCON/DOCS.md).
2. Configure um `rcon_token` com pelo menos 32 caracteres e adicione de uma a cinco instâncias. Anote a ordem dos IDs, os nomes das pastas dos mundos e as portas de jogo. A primeira API é `19233/TCP`, a segunda `19234/TCP` e assim por diante.
3. **Se quiser uma seed específica**, configure-a antes de iniciar a instância pela primeira vez, conforme [seed de um mundo novo](../MinecraftRCON/DOCS.md#definir-a-seed-de-um-mundo-novo). Após o mundo ser criado, alterar `level-seed` não recria o terreno existente.
4. Inicie o add-on e aguarde `Accepting clients on` para cada porta. Teste o IP local e a porta de cada mundo no Minecraft.

## 3. Instalar o MineDev

1. Prepare Apache/PHP conforme o [guia de hospedagem](hospedagem-minedev.md). Em Home Assistant OS, é necessário um add-on web compatível que publique `/share/htdocs`; esse servidor web não faz parte do MinecraftRCON Multi.
2. Copie **todo** o conteúdo de `console/`, inclusive `.htaccess` e `storage/.htaccess`, para `minedev/` na hospedagem.
3. Execute `php tools/initialize.php` no ambiente com acesso de gravação à pasta. Guarde a senha exibida e defina `BEDROCK_API_TOKEN` e `BEDROCK_INSTANCES` no `.env`, seguindo [login e conexão](../console/README.md#2-crie-o-login-e-conecte-ao-bedrock).
4. Confirme HTTP **403** para `.env.example` e `tools/initialize.php`; depois abra `/minedev/`, entre e verifique cada instância no seletor. A pasta `storage/` precisa ser gravável pelo PHP e pelo worker do add-on.

## 4. Preparar o mapa

1. Para o terreno **já explorado**, baixe o [uNmINeD CLI](https://unmined.net/downloads/) do site oficial e siga [Publicar ou atualizar um mapa](../console/README.md#publicar-ou-atualizar-um-mapa) para cada instância. O snapshot é manual e exige uma parada breve apenas do mundo copiado.
2. Para biomas e locais calculados pela seed, siga [Preparar uma instalação nova do mapa](../console/README.md#preparar-uma-instalação-nova-do-mapa). O comando roda **dentro do Home Assistant** e precisa da seed verdadeira e da pasta `storage/` do MineDev no compartilhamento `/share`.
3. Ative um filtro de local no MineDev e aguarde o primeiro setor. O worker calcula e guarda os setores sob demanda; novos setores podem levar vários minutos.

Pacotes de comportamento e recursos são opcionais. Para instalá-los em cada mundo, veja [pacotes adicionais](pacotes-adicionais.md). Eles não são necessários para o add-on, o painel ou o mapa de seed funcionarem.

## 5. Acesso externo

Confirme primeiro a entrada pela rede local. Para acesso pela internet, siga [DuckDNS, Cloudflare DNS e portas](acesso-remoto.md). Encaminhe **TCP e UDP da porta de jogo de cada instância** para o Home Assistant. Nunca encaminhe `19233–19237/TCP`.

## Conferência final

- As instâncias configuradas mostram servidor pronto e aceitam jogadores pela rede local.
- `/health` de cada API responde `{"status":"ready"}` a partir do host PHP.
- O MineDev abre login, permite trocar de instância e exibe telemetria da instância correta.
- Os dois caminhos privados de teste do Apache retornam 403; `.env` e `storage/` não são públicos.
- Cada mapa mostra o snapshot publicado e/ou a camada da seed da **mesma** instância.
- Se houver acesso externo, cada porta de jogo foi testada fora do Wi-Fi; HTTP 200 na porta TCP não comprova a conexão UDP do Minecraft.

Uma instalação em Home Assistant vazio, com todos os possíveis add-ons web de terceiros, não pode ser validada apenas com os arquivos deste repositório. O projeto entrega o add-on e o código do painel; a escolha e a configuração do servidor web e o download do uNmINeD continuam passos explícitos do administrador.
