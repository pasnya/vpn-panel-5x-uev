<?php
session_start();
if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    exit("Forbidden");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['new_password'])) {
    $new_password = $_POST['new_password'];
    
    if (strlen($new_password) < 6) {
        die("Ошибка: Пароль должен быть не менее 6 символов.");
    }

    try {
        $db = new PDO('sqlite:/var/www/panel/db/panel.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
