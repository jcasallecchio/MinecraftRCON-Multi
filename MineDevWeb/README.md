# MineDev Web

Add-on opcional para hospedar o MineDev Console no mesmo Home Assistant OS que executa o MinecraftRCON Multi. Apache/PHP com cURL e `mod_rewrite` são incluídos na imagem. Os arquivos privados `.env`, mapas e histórico persistem em `/share/htdocs/minedev/`, fora da imagem.

Instale primeiro MinecraftRCON Multi e ao menos uma instância. Depois instale este add-on, abra o terminal com acesso ao Docker do host e execute o procedimento de [primeiro acesso](DOCS.md). A porta local padrão do painel é `8099/TCP` e pode ser alterada nas opções de rede do add-on. Use HTTPS ou VPN para acesso externo.
