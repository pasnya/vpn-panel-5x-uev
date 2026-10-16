<?php
require_once __DIR__ . '/security.php';
require_admin(true);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['new_password'])) {
    $new_password = $_POST['new_password'];
    
    if (strlen($new_password) < 12) {
        die("Ошибка: Пароль должен быть не менее 12 символов.");
    }

    try {
        $db = new PDO('sqlite:/var/www/panel/db/panel.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->prepare("SELECT password FROM users WHERE username = :username AND role = 'admin'");
        $stmt->execute([':username' => $_SESSION['username']]);
        $old_hash = $stmt->fetchColumn();
        if (!$old_hash || !password_verify($_POST['current_password'] ?? '', $old_hash)) {
            http_response_code(403);
            exit('Неверный текущий пароль');
        }

        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Обновление пароля текущего залогиненного администратора
        $stmt = $db->prepare("UPDATE users SET password = :password WHERE username = :username AND role = 'admin'");
        $stmt->execute([
            ':password' => $password_hash,
            ':username' => $_SESSION['username']
        ]);

        header("Location: ../index.php?pw_success=1");
        exit;
    } catch (Exception $e) {
        die("Ошибка при изменении пароля: " . $e->getMessage());
    }
}
?>
