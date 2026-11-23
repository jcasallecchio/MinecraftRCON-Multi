<?php
declare(strict_types=1);
require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
cleanup_expired_logs();
$isAuthenticated = !empty($_SESSION['authenticated']);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="<?= htmlspecialchars((string)$_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
  <meta name="theme-color" content="#10140f">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="MineDev">
  <title>MineDev Console</title>
  <link rel="manifest" href="manifest.json">
  <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
  <link rel="apple-touch-icon" href="assets/icon-180.png">
  <link rel="stylesheet" href="assets/app.css?v=35">
</head>
<body class="<?= $isAuthenticated ? 'authenticated' : 'guest' ?>">
  <div class="ambient ambient-one"></div>
  <div class="ambient ambient-two"></div>

  <section class="login-screen" id="loginScreen" <?= $isAuthenticated ? 'hidden' : '' ?>>
    <div class="login-card">
      <div class="brand-mark large"><span class="pixel-cube">▦</span><span>MINE<span class="accent">DEV</span></span></div>
      <div class="eyebrow">SERVER CONTROL · BEDROCK</div>
      <h1>Seu mundo,<br><span>sob controle.</span></h1>
      <p class="login-copy">Entre no console para acompanhar o servidor e executar comandos com segurança.</p>
      <form id="loginForm" method="post" autocomplete="on">
        <label for="username">Usuário</label>
        <input id="username" name="username" autocomplete="username" required maxlength="64" autofocus>
        <label for="password">Senha</label>
        <div class="password-wrap">
          <input id="password" type="password" name="password" autocomplete="current-password" required>
          <button class="password-toggle" type="button" id="togglePassword" aria-label="Mostrar senha">◉</button>
        </div>
        <div class="form-error" id="loginError" role="alert"></div>
        <button class="button primary login-submit" type="submit"><span>Entrar no console</span><span aria-hidden="true">↗</span></button>
      </form>
      <div class="login-foot"><span class="secure-dot"></span> Acesso protegido · sessão privada</div>
    </div>
    <div class="login-aside">
      <div class="floating-cube cube-a"></div><div class="floating-cube cube-b"></div>
      <div class="aside-content">
        <div class="aside-chip"><span class="pulse"></span> MINECRAFT BEDROCK</div>
        <div class="block-art" aria-hidden="true"><div class="block-top"></div><div class="block-front"><span>MC</span></div><div class="block-side"></div></div>
        <h2>Ferramentas precisas<br>para mundos vivos.</h2>
        <p>Console, telemetria e histórico reunidos em um só lugar.</p>
        <div class="aside-meta"><span>BUILD 01</span><span>LOCAL SERVER</span></div>
      </div>
    </div>
  </section>

  <div class="app-shell" id="appShell" <?= !$isAuthenticated ? 'hidden' : '' ?>>
    <header class="topbar">
      <a class="brand-mark" href="#" aria-label="MineDev Console"><span class="pixel-cube">▦</span><span>MINE<span class="accent">DEV</span></span></a>
      <div class="topbar-center"><span class="crumb-muted">SERVIDORES</span><span class="crumb-sep">/</span><label class="instance-picker-label" for="instancePicker">INSTÂNCIA</label><select class="instance-picker" id="instancePicker" aria-label="Selecionar instância Minecraft"><option value="main">Carregando…</option></select></div>
      <div class="topbar-actions">
        <div class="connection-pill" id="connectionPill"><span class="status-dot"></span><span id="connectionText">Conectando</span></div>
        <span class="user-badge" id="userBadge">AD</span>
        <button class="icon-button" id="logoutButton" type="button" title="Sair" aria-label="Sair"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10 17v3H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h5v3"/><path d="m15 16 5-4-5-4"/><path d="M20 12H9"/></svg></button>
      </div>
    </header>

    <div class="mobile-server-strip">
      <span class="status-dot" id="mobileStatusDot"></span><span id="mobileServerName">Bedrock</span><span class="mobile-strip-divider"></span><span id="mobilePlayerCount">— / — online</span><span class="mobile-memory"><span class="mobile-strip-divider"></span><span>RAM</span><strong id="mobileMemoryValue">—</strong></span>
    </div>
    <div class="mobile-shortcuts" aria-label="Atalhos rápidos">
      <button class="quick-command" data-command="list" type="button"><span class="quick-symbol">⌁</span> Jogadores</button>
      <button class="quick-command" data-command="help" type="button"><span class="quick-symbol">?</span> Ajuda</button>
      <button class="quick-command" data-command="time query daytime" type="button"><span class="quick-symbol">◷</span> Horário</button>
    </div>

    <div class="app-layout">
      <aside class="server-rail">
        <div class="rail-label">INSTÂNCIA ATIVA</div>
        <div class="server-card">
          <div class="server-card-head"><div class="server-icon">B</div><div><div class="server-title">Servidor Bedrock</div><div class="server-edition">BEDROCK EDITION</div></div><span class="status-dot" id="railStatusDot"></span></div>
          <div class="server-address">
            <div class="server-address-row"><span class="tiny-label">MUNDO</span><span id="worldName">Carregando…</span></div>
            <div class="server-address-row"><span class="tiny-label">PORTA</span><span id="worldPort">UDP —</span></div>
          </div>
          <div class="server-version-row"><span class="tiny-label">VERSÃO</span><span class="version-value" id="serverVersion">—</span></div>
          <div class="server-player-row"><div><span class="tiny-label">JOGADORES</span><div class="player-total"><strong id="onlineCount">—</strong><span> / <span id="maxCount">—</span></span></div></div><span class="player-orbit"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M8 1.5v13M1.5 8h13M3.4 3.4l9.2 9.2m0-9.2-9.2 9.2"/></svg></span></div>
          <div class="player-list" id="playerList"><span class="muted">Aguardando telemetria</span></div>
          <div class="server-details"><span id="gameMode">Modo —</span><span id="difficulty">Dificuldade —</span></div>
        </div>
        <article class="rail-memory-card" aria-label="Memória do servidor">
          <div class="rail-memory-head"><div class="card-icon">▤</div><div><span class="tiny-label">MEMÓRIA DO SERVIDOR</span><strong id="memoryValue">—</strong></div></div>
          <small>RSS do processo Bedrock</small>
          <div class="memory-bars"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
        </article>
        <div class="rail-section">
          <div class="rail-label">ATALHOS</div>
          <button class="quick-command" data-command="list"><span class="quick-symbol">⌁</span> Listar jogadores <kbd>↵</kbd></button>
          <button class="quick-command" data-command="help"><span class="quick-symbol">?</span> Ajuda Bedrock <kbd>↵</kbd></button>
          <button class="quick-command" data-command="time query daytime"><span class="quick-symbol">◷</span> Consultar horário <kbd>↵</kbd></button>
        </div>
        <div class="rail-bottom"><span class="rail-live"><span class="pulse"></span> TELEMETRIA ATIVA</span><span class="retention-note">AUDITORIA · <?= $LOG_RETENTION_DAYS ?>D</span></div>
      </aside>

      <main class="main-content">
        <div class="page-heading">
          <div><h1>Console <span class="heading-slash">/</span> <span id="pageTitle">Visão geral</span></h1></div>
          <div class="heading-right"><div class="uptime-box"><span class="tiny-label">TEMPO ONLINE</span><strong id="uptimeValue">—</strong></div><button class="icon-button refresh-button" type="button" id="refreshButton" aria-label="Atualizar telemetria" title="Atualizar">⟳</button></div>
        </div>

        <nav class="tabs" aria-label="Seções do console">
          <button class="tab active" data-tab="console" type="button"><span>⌘</span> Console</button>
          <button class="tab" data-tab="map" type="button"><span>⌖</span> Mapa</button>
          <button class="tab" data-tab="stats" type="button"><span>▤</span> Estatísticas</button>
          <button class="tab" data-tab="commands" type="button"><span>⌕</span> Comandos & glossário</button>
          <button class="tab" data-tab="properties" type="button"><span>⚙</span> Configuração</button>
          <button class="tab" data-tab="history" type="button"><span>◷</span> Histórico <i><?= $LOG_RETENTION_DAYS ?>d</i></button>
        </nav>

        <section class="view active" id="view-console" data-view="console">
          <div class="terminal-card">
            <div class="terminal-toolbar">
              <div class="terminal-heading"><span class="terminal-lamp"></span><div><strong id="outputHeading">Terminal do servidor</strong></div></div>
              <div class="terminal-actions"><span class="terminal-live"><span class="pulse"></span> LIVE</span><button type="button" class="ghost-button" id="instanceControlButton">Parar instância</button><button type="button" class="ghost-button" id="instanceRestartButton">Reiniciar</button><button type="button" class="ghost-button" id="clearTerminal">Limpar tela</button><button type="button" class="ghost-button" id="outputToggle" aria-pressed="false">Feed</button></div>
            </div>
            <div class="terminal-output" id="terminalOutput" role="log" aria-live="polite" aria-label="Saída do console">
              <div class="terminal-line system"><span class="line-time">--:--:--</span><span class="line-marker">◆</span><span>MineDev Console inicializado. Aguardando conexão com Bedrock.</span></div>
              <div class="terminal-line hint"><span class="line-time">INFO</span><span class="line-marker">›</span><span>Digite <code>help</code> para ajuda ou comece a escrever para ver sugestões.</span></div>
            </div>
            <div class="terminal-output" id="feedOutput" role="log" aria-live="polite" aria-label="Feed de jogadores" hidden></div>
            <div class="suggestion-wrap"><div class="suggestions" id="suggestions" hidden></div></div>
            <form id="commandForm" class="command-form" autocomplete="off">
              <span class="command-prefix">›</span><input id="commandInput" name="command" type="text" maxlength="1024" spellcheck="false" placeholder="Digite um comando Bedrock…" aria-label="Comando Minecraft" disabled>
              <kbd class="enter-hint">ENTER</kbd><button type="submit" class="send-button" id="sendButton" aria-label="Enviar comando" disabled>↑</button>
            </form>
          </div>
        </section>

        <section class="view" id="view-map" data-view="map">
          <div class="world-map-card">
            <div class="world-map-toolbar">
              <div class="world-map-title"><strong>Mapa do mundo</strong><span id="mapStatus" role="status">Verificando mapa…</span></div>
              <div class="world-map-actions"><label class="world-map-dimension-label" for="mapDimension">Dimensão</label><select id="mapDimension" aria-label="Dimensão do mapa"><option value="overworld">Overworld</option><option value="nether">Nether</option><option value="end">End</option></select><span class="world-map-updated" id="mapUpdated"></span><button class="ghost-button" id="mapRefresh" type="button">Recarregar mapa ⟳</button><a class="ghost-button" id="mapOpen" href="#" target="_blank" rel="noopener" hidden>Abrir em outra aba ↗</a></div>
            </div>
            <div class="world-map-empty" id="mapEmpty" hidden><span class="world-map-empty-icon">⌖</span><strong>Mapa ainda não disponível</strong><p>Gere um mapa web a partir de uma cópia consistente do mundo e coloque os arquivos exportados em <code>storage/maps/&lt;id-da-instância&gt;/</code>. Consulte o guia de instalação do MineDev.</p></div>
            <div class="world-map-frame-wrap" id="mapFrameWrap" hidden><iframe id="worldMapFrame" title="Mapa interativo do mundo Minecraft" loading="lazy" referrerpolicy="same-origin" allowfullscreen></iframe></div>
          </div>
        </section>

        <section class="view" id="view-properties" data-view="properties">
          <div class="properties-card">
            <textarea id="propertiesEditor" class="properties-editor" spellcheck="false" autocomplete="off" autocapitalize="off" aria-label="Editar server.properties da instância selecionada" disabled></textarea>
            <div class="properties-actions"><button class="ghost-button" type="button" id="propertiesReload">Recarregar arquivo</button><button class="button secondary" type="button" id="propertiesSave" disabled>Salvar</button><button class="button primary" type="button" id="propertiesSaveRestart" disabled>Salvar e reiniciar</button></div>
          </div>
        </section>

        <section class="view" id="view-stats" data-view="stats">
          <div class="stats-tools"><label class="search-box"><span>⌕</span><input id="statsSearch" type="search" placeholder="Filtrar jogador…" autocomplete="off"></label><button class="ghost-button" id="statsRefresh" type="button">Atualizar estatísticas ⟳</button></div>
          <div class="player-stats-grid" id="playerStatsList" aria-live="polite" aria-label="Estatísticas de jogadores"></div>
          <div class="stats-status-row"><span class="activity-updated" id="statsUpdated">Aguardando dados…</span><span class="terminal-live"><span class="pulse"></span> ATUALIZAÇÃO AUTOMÁTICA</span></div>
        </section>

        <section class="view" id="view-commands" data-view="commands">
          <div class="catalog-tools"><label class="search-box"><span>⌕</span><input id="catalogSearch" type="search" placeholder="Buscar comando ou descrição…" autocomplete="off"></label><div class="category-filters" id="categoryFilters"></div></div>
          <div class="command-grid" id="commandGrid"></div>
          <div class="catalog-note"><span>ⓘ</span><span>As sugestões seguem os metadados oficiais do Bedrock. A lista e a sintaxe efetiva podem variar por versão, permissões, cheats e pacotes. O console aceita comandos nativos Bedrock mesmo quando não aparecem aqui.<br><small id="catalogStatus">Carregando catálogo oficial…</small></span></div>
          <div class="glossary-section"><div class="section-title-row"><div><h2>Glossário rápido</h2></div><label class="search-box glossary-search"><span>⌕</span><input id="glossarySearch" type="search" placeholder="Buscar termo…" autocomplete="off"></label></div><div class="glossary-grid" id="glossaryGrid"></div></div>
        </section>

        <section class="view" id="view-history" data-view="history">
          <div class="history-tools"><label class="search-box"><span>⌕</span><input id="historySearch" type="search" placeholder="Filtrar histórico…" autocomplete="off"></label><button class="ghost-button" id="historyRefresh" type="button">Atualizar histórico ⟳</button><a class="button secondary export-button" href="api.php?action=export">↓ Exportar CSV</a></div>
          <div class="history-list" id="historyList"></div>
          <div class="history-more-wrap"><button class="ghost-button" id="historyMore" type="button" hidden>Carregar registros anteriores</button></div>
          <div class="catalog-note"><span>🔒</span><span>Senha, token e cabeçalhos de autenticação nunca são registrados. Os comandos e retornos do servidor são mantidos para auditoria por até <?= $LOG_RETENTION_DAYS ?> dia(s).</span></div>
        </section>

        <footer class="page-footer"><span>MINEDEV <b>·</b> CONTROLE LOCAL DO SERVIDOR</span><span>BEDROCK API <b>V1</b></span></footer>
      </main>
    </div>
  </div>
  <div class="toast-region" id="toastRegion" aria-live="polite"></div>
<script src="assets/app.js?v=30" defer></script>
</body>
</html>
