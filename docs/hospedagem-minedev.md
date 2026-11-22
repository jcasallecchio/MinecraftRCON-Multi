# Hospedagem do MineDev

O MineDev é uma aplicação PHP servida pelo Apache. O add-on MinecraftRCON Multi e o painel podem ficar no mesmo Home Assistant ou em máquinas diferentes. As APIs `19233–19237/TCP` devem ser acessíveis **somente pela rede local ou VPN**.

## Home Assistant OS

Use um add-on de Apache com PHP 8.2 ou superior que publique `/share/htdocs` como raiz web e respeite `.htaccess`. Habilite `mod_rewrite` e a extensão PHP cURL nas opções desse add-on. Copie todo o conteúdo de `console/`, inclusive `.htaccess` e `storage/.htaccess`, para `/share/htdocs/minedev/`. Em um terminal que tenha PHP CLI e acesso de gravação aos arquivos, execute `php tools/initialize.php` na pasta instalada; o caminho visto dentro de cada add-on web pode ser diferente de `/share/htdocs/minedev`. O servidor web precisa ler o `.env` criado e gravar em `storage/`. Configure `BEDROCK_API_TOKEN` e `BEDROCK_INSTANCES` como no [guia do painel](../console/README.md#2-crie-o-login-e-conecte-ao-bedrock).

O add-on MinecraftRCON Multi vê o mesmo `/share`; deixe `seed_storage_path=/share/htdocs/minedev/storage`. A camada inicial e os setores da seed são gerados no Home Assistant. O snapshot dos chunks explorados usa o procedimento com uNmINeD descrito no guia do painel.

Antes do primeiro login, verifique que `/minedev/.env.example` e `/minedev/tools/initialize.php` retornam **403** pelo Apache. Se retornarem 200, corrija a configuração de `.htaccess` antes de usar o painel.

## Servidor Debian/Ubuntu separado

Este caminho instala o painel e a telemetria. Para usar também a exploração pela seed, disponibilize a pasta privada `storage/` ao add-on MinecraftRCON Multi dentro de `/share` no Home Assistant, com leitura e gravação, e aponte `seed_storage_path` para ela. Um painel instalado somente no disco local do servidor web não permite que o add-on calcule os setores.

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
