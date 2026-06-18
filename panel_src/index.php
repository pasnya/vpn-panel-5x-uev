<?php
session_start();

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION['logged_in'])) {
    if (isset($_POST['login']) && isset($_POST['password'])) {
        $db = new PDO('sqlite:/var/www/panel/db/panel.db');
        $stmt = $db->prepare("SELECT * FROM users WHERE username = :username AND role = 'admin'");
        $stmt->execute([':username' => $_POST['login']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($_POST['password'], $user['password'])) {
            $_SESSION['logged_in'] = true;
            $_SESSION['username'] = $user['username'];
            header("Location: index.php");
            exit;
        } else {
            $error = "Неверный логин или пароль";
        }
    }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход — VPN Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body">
    <div class="login-card">
        <img src="assets/logo.png" alt="Logo" class="logo" id="loginLogo">
        <h2>Вход в панель</h2>
        <?php if (isset($error)): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST" id="loginForm">
            <div class="fg">
                <label for="login">Логин администратора</label>
                <input type="text" id="login" name="login" required autocomplete="username" autofocus placeholder="admin">
            </div>
            <div class="fg">
                <label for="password">Пароль</label>
                <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="••••••••">
            </div>
            <button type="submit" class="btn" id="btnLoginSubmit">Войти в панель</button>
        </form>
    </div>
</body>
</html>
<?php exit; }

/* ── Data ── */
$db = new PDO('sqlite:/var/www/panel/db/panel.db');
$domains = $db->query("SELECT * FROM domains ORDER BY domain_name")->fetchAll(PDO::FETCH_ASSOC);
$users = $db->query("
    SELECT u.id, u.username, u.uuid, u.status, u.traffic_limit, u.traffic_used, u.created_at, u.expires_at,
           cc.domain_id
    FROM users u
    LEFT JOIN client_credentials cc ON u.id = cc.user_id AND cc.domain_id IS NOT NULL
    WHERE u.role = 'client'
    GROUP BY u.id
")->fetchAll(PDO::FETCH_ASSOC);

$public_key = trim(@file_get_contents('/etc/xray/public.key'));
if (empty($public_key)) $public_key = '—';

$hysteria_config = @file_get_contents('/etc/hysteria/config.yaml');
$obfs_password = '';
if ($hysteria_config && preg_match('/password:\s*"([^"]+)"/', $hysteria_config, $m)) $obfs_password = $m[1];

$reality_sni = 'yahoo.com';
$reality_sid = '';
$xray_content = @file_get_contents('/etc/xray/config.json');
if ($xray_content) {
    $xray_cfg = json_decode($xray_content, true);
    if (isset($xray_cfg['inbounds'])) {
        foreach ($xray_cfg['inbounds'] as $inb) {
            if (isset($inb['streamSettings']['realitySettings']['serverNames'][0]))
                $reality_sni = $inb['streamSettings']['realitySettings']['serverNames'][0];
            if (isset($inb['streamSettings']['realitySettings']['shortIds'][0]))
                $reality_sid = $inb['streamSettings']['realitySettings']['shortIds'][0];
        }
    }
}

$ip_server = '127.0.0.1';
$ip_cache_file = '/tmp/server_public_ip.txt';
if (file_exists($ip_cache_file) && (time() - filemtime($ip_cache_file) < 86400)) {
    $ip_server = trim(file_get_contents($ip_cache_file));
} else {
    $ch = curl_init('https://api.ipify.org');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $ext_ip = curl_exec($ch);
    curl_close($ch);
    
    if ($ext_ip && filter_var(trim($ext_ip), FILTER_VALIDATE_IP)) {
        $ip_server = trim($ext_ip);
        @file_put_contents($ip_cache_file, $ip_server);
    } else {
        $ip_server = $_SERVER['SERVER_ADDR'] ?? $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        if (strpos($ip_server, ':') !== false) {
            $ip_server = explode(':', $ip_server)[0];
        }
        if ($ip_server !== '127.0.0.1' && filter_var($ip_server, FILTER_VALIDATE_IP)) {
            @file_put_contents($ip_cache_file, $ip_server);
        }
    }
}
$has_domain = !empty($domains);

/* ── Service status ── */
$services = ['nginx', 'caddy', 'xray', 'mita', 'hysteria-server'];
$statuses = [];
foreach ($services as $srv) {
    $sub = trim(shell_exec("systemctl show -p SubState --value " . escapeshellarg($srv) . " 2>/dev/null"));
    $statuses[$srv] = ($sub === 'running') ? 'on' : (($sub === 'reloading') ? 'warn' : 'off');
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>VPN Dashboard — Панель управления</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="wrap">

    <?php if (isset($_GET['pw_success'])): ?>
    <div class="alert alert-info" id="successPasswordAlert" style="margin-top: 15px; margin-bottom: 0;">
        <strong>Успешно!</strong> Пароль администратора успешно изменен.
    </div>
    <?php endif; ?>

    <!-- Topbar -->
    <div class="topbar" id="mainTopbar">
        <div class="topbar-left">
            <img src="assets/logo.png" alt="VPN Logo" id="dashboardLogo">
            <div>
                <div class="topbar-title">VPN Panel</div>
                <div class="topbar-sub" id="serverIpInfo">IP: <?= htmlspecialchars($ip_server) ?></div>
            </div>
        </div>
        <div class="topbar-right">
            <span class="tag tag-info" id="badgeClientsCount"><?= count($users) ?> клиентов</span>
            <span class="tag tag-info" id="badgeDomainsCount"><?= count($domains) ?> доменов</span>
            <a href="?logout=1" class="btn btn-outline btn-sm" id="btnDashboardLogout">Выйти</a>
        </div>
    </div>

    <!-- Interactive Setup Wizard -->
    <div class="card wizard-card" id="wizardCard">
        <div class="wizard-header">
            <div class="wizard-title">
                <span class="icon">🚀</span> Интерактивный помощник настройки
            </div>
            <button class="wizard-collapse-btn" id="wizardCollapseBtn">Свернуть</button>
        </div>
        <div id="wizardBody">
            <div class="stepper">
                <div class="stepper-progress" id="stepperProgress"></div>
                <div class="step-node active" id="stepNode-1" onclick="goToStep(1)">
                    <div class="step-circle">1</div>
                    <div class="step-label">DNS записи</div>
                </div>
                <div class="step-node" id="stepNode-2" onclick="goToStep(2)">
                    <div class="step-circle">2</div>
                    <div class="step-label">Домен</div>
                </div>
                <div class="step-node" id="stepNode-3" onclick="goToStep(3)">
                    <div class="step-circle">3</div>
                    <div class="step-label">Клиент</div>
                </div>
                <div class="step-node" id="stepNode-4" onclick="goToStep(4)">
                    <div class="step-circle">4</div>
                    <div class="step-label">Конфиги</div>
                </div>
            </div>
            
            <div class="wizard-content">
                <!-- Slide 1: DNS -->
                <div class="wizard-slide active" id="wizardSlide-1">
                    <h4>Шаг 1: Настройка DNS А-записей</h4>
                    <p>Перед началом добавления домена перейдите на сайт вашего DNS-провайдера (Cloudflare, Reg.ru и др.) и создайте следующие записи:</p>
                    <div class="alert alert-info" style="margin-bottom: 0;">
                        1. <strong>A-запись</strong> для основного домена (например, <code>example.com</code>) → направьте на IP <code><?= htmlspecialchars($ip_server) ?></code><br>
                        2. <strong>A-запись</strong> для Naiveподдомена (например, <code>n.example.com</code>) → направьте на IP <code><?= htmlspecialchars($ip_server) ?></code>
                    </div>
                </div>
                
                <!-- Slide 2: Domain -->
                <div class="wizard-slide" id="wizardSlide-2">
                    <h4>Шаг 2: Привязка домена к серверу</h4>
                    <p>После настройки DNS прокрутите вниз к разделу <strong>"Добавить домен"</strong> и введите данные:</p>
                    <p>• Введите <em>Основной домен</em> (тот, что вы привязали на шаге 1, например, <code>example.com</code>).<br>
                    • Укажите <em>Поддомен NaiveProxy</em> (например, <code>n.example.com</code>).<br>
                    • Нажмите <strong>"Добавить и выпустить SSL"</strong>. Система автоматически получит сертификаты от Let's Encrypt и перезапустит веб-сервер.</p>
                </div>
                
                <!-- Slide 3: Client -->
                <div class="wizard-slide" id="wizardSlide-3">
                    <h4>Шаг 3: Создание пользователя (клиента)</h4>
                    <p>Для создания пользователя перейдите к форме <strong>"Новый клиент"</strong>:</p>
                    <p>• Введите уникальное имя пользователя (латинские буквы и цифры).<br>
                    • Выберите добавленный домен из выпадающего списка (если домен не добавлен, будет доступен только протокол <em>VLESS + Reality</em>, который работает напрямую по IP).<br>
                    • Нажмите кнопку <strong>"Создать"</strong>.</p>
                </div>
                
                <!-- Slide 4: Configs -->
                <div class="wizard-slide" id="wizardSlide-4">
                    <h4>Шаг 4: Получение и импорт конфигураций</h4>
                    <p>Поздравляем, конфигурация создана!</p>
                    <p>• Найдите пользователя в таблице <strong>"Клиенты"</strong>.<br>
                    • Нажмите кнопку <strong>"Конфиги"</strong> напротив его имени.<br>
                    • В открывшейся панели скопируйте нужную ссылку или откройте **QR-код** для быстрого сканирования телефоном.<br>
                    • Импортируйте ссылку в ваше VPN-приложение (Nekobox, v2rayN, Shadowrocket или Sing-box).</p>
                </div>
            </div>
            
            <div class="wizard-actions">
                <button class="btn btn-sm btn-outline" id="wizardBack" onclick="prevStep()">Назад</button>
                <button class="btn btn-sm" id="wizardNext" onclick="nextStep()">Далее</button>
            </div>
        </div>
    </div>

    <!-- Services -->
    <div class="card" id="servicesCard">
        <div class="section-title">
            <span class="icon">⚙️</span> Статус системных служб
        </div>
        <div class="svc-grid" id="servicesGrid">
            <?php foreach ($statuses as $srv => $s): ?>
            <div class="svc-chip" id="svcChip-<?= $srv ?>">
                <div class="svc-left">
                    <span class="svc-dot <?= $s ?>" id="svcDot-<?= $srv ?>"></span>
                    <span class="svc-name"><?= strtoupper($srv === 'hysteria-server' ? 'Hysteria 2' : $srv) ?></span>
                </div>
                <button class="svc-btn" id="btnRestart-<?= $srv ?>" onclick="restartService('<?= $srv ?>')">Рестарт</button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Users + Add Client -->
    <div class="cols">
        <div class="card" id="clientsCard">
            <div class="section-title">
                <span class="icon">👥</span> Управление клиентами
            </div>
            
            <!-- Search bar -->
            <div class="search-box">
                <input type="text" id="clientSearch" placeholder="Поиск клиента по имени...">
            </div>

            <?php if (empty($users)): ?>
                <div class="empty" id="clientsEmpty"><div class="empty-icon">👥</div>Список клиентов пуст</div>
            <?php else: ?>
            <div class="table-wrapper">
                <table id="clientsTable">
                    <thead>
                        <tr>
                            <th>Пользователь</th>
                            <th>Сокращённый UUID</th>
                            <th>Статус</th>
                            <th style="text-align: right;">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr id="clientRow-<?= htmlspecialchars($u['username']) ?>">
                        <td><strong class="client-name"><?= htmlspecialchars($u['username']) ?></strong></td>
                        <td><code class="mono"><?= htmlspecialchars(mb_substr($u['uuid'], 0, 8)) ?>…</code></td>
                        <td><span class="tag <?= $u['status'] === 'active' ? 'tag-ok' : 'tag-warn' ?>"><?= $u['status'] ?></span></td>
                        <td style="text-align: right;">
                            <button class="btn btn-sm" id="btnConfigs-<?= htmlspecialchars($u['username']) ?>" onclick="showConfigs('<?= htmlspecialchars($u['username']) ?>','<?= htmlspecialchars($u['uuid']) ?>','<?= $u['domain_id'] ?>',<?= htmlspecialchars(json_encode($domains), ENT_QUOTES, 'UTF-8') ?>,'<?= $public_key ?>','<?= $obfs_password ?>','<?= $reality_sni ?>','<?= $reality_sid ?>')">Конфиги</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="card" id="addClientCard">
            <div class="section-title">
                <span class="icon">👤</span> Новый клиент
            </div>
            <form action="api/add_client.php" method="POST" id="formAddClient">
                <div class="fg">
                    <label for="inputClientName">Имя пользователя (латиница, цифры, подчёркивание)</label>
                    <input type="text" id="inputClientName" name="username" required pattern="[a-zA-Z0-9_-]+" placeholder="например, alex_vpn">
                </div>
                <div class="fg">
                    <label for="selectClientDomain">Привязать домен (для xhttp, Naive, Hysteria)</label>
                    <select id="selectClientDomain" name="domain_id">
                        <option value="">— Без домена (только Reality по IP) —</option>
                        <?php foreach ($domains as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['domain_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!$has_domain): ?>
                <div class="alert alert-warn" style="font-size:12px; margin-bottom: 14px; padding: 10px 14px;" id="noDomainWarning">
                    Домен не добавлен. Будет доступен только протокол <strong>VLESS + Reality</strong>.<br>
                    Чтобы использовать остальные протоколы, сначала привяжите домен ниже.
                </div>
                <?php endif; ?>
                <button type="submit" class="btn" id="btnSubmitAddClient" style="width:100%; justify-content:center;">Создать клиента</button>
            </form>
        </div>
    </div>

    <!-- Configs panel (slides out/down when client clicked) -->
    <div id="configsPanel" class="configs-panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
            <div class="section-title" style="margin:0" id="configTitle">
                <span class="icon">🔑</span> Конфигурации
            </div>
            <button class="btn btn-outline btn-sm" id="btnHideConfigs" onclick="document.getElementById('configsPanel').classList.remove('open')">Закрыть</button>
        </div>
        <div id="configsContent"></div>
    </div>

    <!-- Domains + Add Domain -->
    <div class="cols">
        <div class="card" id="domainsCard">
            <div class="section-title">
                <span class="icon">🌐</span> Подключённые домены
            </div>
            <?php if (empty($domains)): ?>
                <div class="empty" id="domainsEmpty"><div class="empty-icon">🌐</div>Домены отсутствуют</div>
            <?php else: ?>
            <div class="table-wrapper">
                <table id="domainsTable">
                    <thead>
                        <tr>
                            <th>Домен</th>
                            <th>Naive поддомен</th>
                            <th>SSL Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($domains as $d): ?>
                    <tr id="domainRow-<?= htmlspecialchars($d['domain_name']) ?>">
                        <td><strong><?= htmlspecialchars($d['domain_name']) ?></strong></td>
                        <td><code class="mono"><?= htmlspecialchars($d['naive_sub']) ?></code></td>
                        <td><span class="tag tag-ok">Активен</span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="card" id="addDomainCard">
            <div class="section-title">
                <span class="icon">➕</span> Добавить домен
            </div>
            <div class="alert alert-warn" id="dnsAlert">
                <strong>Важно!</strong> Перед добавлением обязательно создайте A-записи домена и поддомена, указав в качестве значения внешний IP сервера: <code><?= htmlspecialchars($ip_server) ?></code>.
            </div>
            <form action="api/add_domain.php" method="POST" id="formAddDomain">
                <div class="fg">
                    <label for="inputDomainName">Основной домен</label>
                    <input type="text" id="inputDomainName" name="domain" required placeholder="например, mysite.com">
                </div>
                <div class="fg">
                    <label for="inputNaiveSub">Поддомен для NaiveProxy</label>
                    <input type="text" id="inputNaiveSub" name="naive_sub" required placeholder="например, n.mysite.com">
                </div>
                <div class="fg">
                    <label for="selectDecoy">Тип сайта-заглушки (для маскировки)</label>
                    <select id="selectDecoy" name="decoy">
                        <option value="blog">Блог / Статьи</option>
                        <option value="portfolio">Портфолио</option>
                        <option value="landing">Простой лендинг</option>
                    </select>
                </div>
                <button type="submit" class="btn" id="btnSubmitAddDomain" style="width:100%; justify-content:center;">Добавить и выпустить SSL</button>
            </form>
        </div>
    </div>

    <!-- Cores & Security Settings -->
    <div class="cols">
        <!-- Cores -->
        <div class="card" id="coresCard" style="margin-top: 0;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <div class="section-title" style="margin:0">
                    <span class="icon">⚡</span> Ядра и протоколы
                </div>
                <button class="btn btn-sm" id="btn-check-cores" onclick="checkCores()">Проверить обновления</button>
            </div>
            <div id="coresList">
                <?php
                $cores = [
                    ['key'=>'xray',    'name'=>'Xray Core',    'desc'=>'Служба VLESS + Reality + xhttp'],
                    ['key'=>'hysteria','name'=>'Hysteria 2',   'desc'=>'Служба QUIC (UDP) с высокой скоростью'],
                    ['key'=>'mita',    'name'=>'Mita (Mieru)', 'desc'=>'Служба TCP мультиплексирования'],
                    ['key'=>'caddy',   'name'=>'Caddy',        'desc'=>'Служба NaiveProxy + forward_proxy'],
                ];
                foreach ($cores as $c): ?>
                <div class="cores-row" id="coreRow-<?= $c['key'] ?>">
                    <div class="cores-info">
                        <div class="cores-name"><?= $c['name'] ?></div>
                        <div class="cores-desc"><?= $c['desc'] ?></div>
                    </div>
                    <span class="cores-ver" id="ver-<?= $c['key'] ?>">—</span>
                    <button class="btn btn-sm btn-outline" id="btn-update-<?= $c['key'] ?>" disabled onclick="updateCore('<?= $c['key'] ?>')">Сначала проверьте</button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card" id="changePasswordCard">
            <div class="section-title">
                <span class="icon">🔒</span> Безопасность
            </div>
            <form action="api/change_password.php" method="POST" id="formChangePassword">
                <div class="fg">
                    <label for="inputNewPassword">Новый пароль администратора</label>
                    <input type="password" id="inputNewPassword" name="new_password" required minlength="6" placeholder="минимум 6 символов">
                </div>
                <button type="submit" class="btn" id="btnSubmitChangePassword" style="width:100%; justify-content:center;">Изменить пароль</button>
            </form>
        </div>
    </div>

</div>
<script src="assets/js/main.js"></script>
</body>
</html>
