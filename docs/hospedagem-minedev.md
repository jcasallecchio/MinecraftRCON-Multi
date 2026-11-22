# Hospedagem do MineDev

O MineDev é uma aplicação PHP servida pelo Apache. Para uma instalação nova no Home Assistant OS, use o [add-on MineDev Web](../MineDevWeb/DOCS.md) deste repositório. O procedimento abaixo serve para hospedagem manual ou em um servidor separado. As APIs `19233–19237/TCP` devem ser acessíveis **somente pela rede local ou VPN**.

## Home Assistant OS

O [MineDev Web](../MineDevWeb/DOCS.md) já fornece Apache com PHP, cURL e `mod_rewrite`, publica `/share/htdocs/minedev` e preserva `.env` e `storage/`. Siga o guia do add-on para inicializar o login. Se escolher outro servidor web, ele precisa publicar a mesma pasta `/share/htdocs`, respeitar `.htaccess` e permitir leitura do `.env` e gravação em `storage/` pelo processo PHP.

O add-on MinecraftRCON Multi vê o mesmo `/share`; deixe `seed_storage_path=/share/htdocs/minedev/storage`. A camada inicial e os setores da seed são gerados no Home Assistant. O snapshot dos chunks explorados usa o procedimento com uNmINeD descrito no guia do painel.

Antes do primeiro login, verifique que `/minedev/.env.example` e `/minedev/tools/initialize.php` retornam **403** pelo Apache. Se retornarem 200, corrija a configuração de `.htaccess` antes de usar o painel.

## Servidor Debian/Ubuntu separado

O procedimento abaixo instala o painel e a telemetria no disco local do servidor web. Para usar também a exploração pela seed, siga a seção **Compartilhar o storage com o Home Assistant** antes de copiar `console/`. Um painel instalado somente no disco local do servidor web não permite que o add-on calcule os setores.

Instale os pacotes e confirme que a versão do PHP é **8.2 ou superior**:

~~~sh
sudo apt update
sudo apt install apache2 php libapache2-mod-php php-cli php-curl
php -v
sudo a2enmod rewrite
~~~

Copie o conteúdo de `console/` do clone para `/var/www/html/minedev/`, incluindo arquivos ocultos. A partir da raiz do repositório:

~~~sh
sudo install -d /var/www/html/minedev
sudo cp -a console/. /var/www/html/minedev/
sudo chown www-data:www-data /var/www/html/minedev
sudo -u www-data php /var/www/html/minedev/tools/initialize.php
sudo chown root:root /var/www/html/minedev
sudo chmod 0755 /var/www/html/minedev
sudo chmod 0640 /var/www/html/minedev/.env
sudo sh /var/www/html/minedev/tools/fix-storage-permissions.sh www-data www-data
~~~

O instalador mostra uma senha aleatória uma vez. Guarde-a e preencha `BEDROCK_API_TOKEN` e `BEDROCK_INSTANCES` no `.env` conforme o guia do painel. Use o IP do Home Assistant alcançável pelo servidor PHP. Se a API do add-on restringir origens com `rcon_allowed_ips`, inclua o IP do servidor web.

Crie `/etc/apache2/conf-available/minedev.conf` com o conteúdo abaixo:

~~~apache
<Directory "/var/www/html/minedev">
    Options +FollowSymLinks
    AllowOverride AuthConfig FileInfo Options
    DirectoryIndex index.php
</Directory>
~~~

Depois habilite a configuração, confira a sintaxe e recarregue o Apache:

~~~sh
sudo a2enconf minedev
sudo apache2ctl configtest
sudo systemctl reload apache2
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/minedev/.env.example
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/minedev/tools/initialize.php
~~~

As duas últimas linhas devem imprimir `403`. Abra `/minedev/` no navegador e verifique o login e a instância selecionada. Para acesso fora da rede local, publique o painel por HTTPS com autenticação e restrição de origem ou use VPN; não exponha as portas da API RCON.

### Compartilhar o storage com o Home Assistant

Para o **mapa completo** com a hospedagem Debian/Ubuntu, instale o [app Samba share do Home Assistant OS](https://www.home-assistant.io/common-tasks/os/#installing-and-using-the-samba-share-app), configure suas credenciais no próprio app e habilite o compartilhamento `share`. No servidor Debian/Ubuntu, monte `//IP_DO_HA/share` em `/mnt/ha-share` **antes** de copiar o MineDev. A pasta `/mnt/ha-share/htdocs/minedev/storage` será a mesma que o add-on MinecraftRCON Multi lê em `/share/htdocs/minedev/storage`.

~~~sh
sudo apt install cifs-utils
id -u www-data
id -g www-data
sudo install -d /mnt/ha-share
sudoedit /root/.ha-samba-credentials
sudo chmod 0600 /root/.ha-samba-credentials
~~~

No arquivo privado de credenciais, coloque duas linhas, `username=SEU_USUARIO_SAMBA` e `password=SUA_SENHA_SAMBA`. Use o editor; não passe a senha na linha de comando. No exemplo abaixo, substitua `33` pelo UID e GID numéricos mostrados pelos dois comandos `id` e troque o IP pelo do Home Assistant:

~~~sh
sudo mount -t cifs //192.168.1.50/share /mnt/ha-share -o credentials=/root/.ha-samba-credentials,uid=33,gid=33,vers=3.0,file_mode=0640,dir_mode=0750
sudo install -d /mnt/ha-share/htdocs/minedev
sudo cp -a console/. /mnt/ha-share/htdocs/minedev/
sudo -u www-data php /mnt/ha-share/htdocs/minedev/tools/initialize.php
sudo ln -s /mnt/ha-share/htdocs/minedev /var/www/html/minedev
~~~

Execute os comandos a partir do clone do repositório. Esse caminho é **alternativo** à cópia local em `/var/www/html/minedev`; não instale as duas cópias. No bloco `<Directory>` do Apache mostrado acima, use o caminho real `/mnt/ha-share/htdocs/minedev` e mantenha `Options +FollowSymLinks`. Para montar no boot, inclua no `/etc/fstab` uma linha equivalente à abaixo, com IP, UID e GID ajustados, e teste com `sudo mount -a`:

~~~text
//192.168.1.50/share /mnt/ha-share cifs credentials=/root/.ha-samba-credentials,uid=33,gid=33,vers=3.0,file_mode=0640,dir_mode=0750,nofail,_netdev 0 0
~~~

Configure o `.env` e habilite `mod_rewrite` como nas seções anteriores. Verifique os dois retornos 403 **pela URL pública do painel**, o login e a gravação em `storage/`. No add-on MinecraftRCON Multi, deixe `seed_storage_path=/share/htdocs/minedev/storage`. Se montar o compartilhamento falhar, não gere a camada da seed nem habilite a fila até que os dois serviços vejam a mesma pasta.
