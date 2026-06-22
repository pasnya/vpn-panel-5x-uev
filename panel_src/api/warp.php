<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit;
}

$warp_file = '/var/www/panel/db/warp.json';

// Utility function to trigger config rebuild
function trigger_rebuild() {
    require_once 'rebuild_configs.php';
    rebuild_configs();
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? $action;
}

try {
    if ($action === 'register') {
        // 1. Generate Curve25519 keypair
        $xray_keys = shell_exec('/usr/local/bin/xray x25519 2>&1');
        $private_key = '';
        $public_key = '';
        if ($xray_keys) {
            if (preg_match('/Private\s*key:\s*([^\s\r\n]+)/i', $xray_keys, $m1)) {
                $private_key = trim($m1[1]);
            }
            if (preg_match('/Public\s*key:\s*([^\s\r\n]+)/i', $xray_keys, $m2)) {
                $public_key = trim($m2[1]);
            } elseif (preg_match('/Password\s*\(PublicKey\):\s*([^\s\r\n]+)/i', $xray_keys, $m2)) {
                $public_key = trim($m2[1]);
            }
        }

        if (empty($private_key) || empty($public_key)) {
            throw new Exception("Не удалось сгенерировать или распарсить ключи с помощью xray. Вывод xray: " . $xray_keys);
        }

        $private_key = base64url_to_base64($private_key);
        $public_key = base64url_to_base64($public_key);

        // 2. Call Cloudflare WARP API
        $url = 'https://api.cloudflareclient.com/v0a4005/reg';
        $data = [
            'key' => $public_key,
            'tos' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'type' => 'PC',
            'model' => 'x-ui',
            'locale' => 'en_US'
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'CF-Client-Version: a-6.30-3596'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 15
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($http_code < 200 || $http_code >= 300) {
            $err_msg = "Ошибка API Cloudflare (HTTP $http_code): " . $response;
            if ($curl_error) {
                $err_msg .= " (Ошибка cURL: $curl_error)";
            }
            throw new Exception($err_msg);
        }
        
        $res_data = json_decode($response, true);
        if (!$res_data || !isset($res_data['id']) || !isset($res_data['token'])) {
            throw new Exception("Некорректный ответ от API Cloudflare: " . $response);
        }
        
        $device_id = $res_data['id'];
        $token = $res_data['token'];
        $license = $res_data['account']['license'] ?? '';
        $v4 = $res_data['config']['interface']['addresses']['v4'] ?? '';
        $v6 = $res_data['config']['interface']['addresses']['v6'] ?? '';
        $client_id_b64 = $res_data['config']['client_id'] ?? '';
        
        $reserved = [];
        if ($client_id_b64) {
            $raw_bytes = base64_decode($client_id_b64);
            if ($raw_bytes !== false && strlen($raw_bytes) >= 3) {
                $reserved = [
                    ord($raw_bytes[0]),
                    ord($raw_bytes[1]),
                    ord($raw_bytes[2])
                ];
            }
        }
        
        // Default mode is smart
        $mode = 'smart';
        
        // If there was an existing warp.json, keep its mode
        if (file_exists($warp_file)) {
            $existing = json_decode(file_get_contents($warp_file), true);
            if ($existing && isset($existing['mode'])) {
                $mode = $existing['mode'];
            }
        }
        
        $warp_json = [
            'status' => 'enabled',
            'mode' => $mode,
            'data' => [
                'device_id' => $device_id,
                'access_token' => $token,
                'license_key' => $license,
                'private_key' => $private_key,
                'v4' => $v4,
                'v6' => $v6,
                'reserved' => $reserved
            ]
        ];
        
        file_put_contents($warp_file, json_encode($warp_json, JSON_PRETTY_PRINT));
        trigger_rebuild();
        
        echo json_encode(["success" => true, "message" => "Cloudflare WARP успешно зарегистрирован и включен."]);
        exit;
        
    } elseif ($action === 'toggle') {
        if (!file_exists($warp_file)) {
            throw new Exception("WARP еще не зарегистрирован.");
        }
        
        $warp_json = json_decode(file_get_contents($warp_file), true);
        if (!$warp_json) {
            throw new Exception("Некорректный файл конфигурации WARP.");
        }
        
        $warp_json['status'] = ($warp_json['status'] ?? 'disabled') === 'enabled' ? 'disabled' : 'enabled';
        
        file_put_contents($warp_file, json_encode($warp_json, JSON_PRETTY_PRINT));
        trigger_rebuild();
        
        $msg = $warp_json['status'] === 'enabled' ? "WARP успешно включен." : "WARP успешно выключен.";
        echo json_encode(["success" => true, "status" => $warp_json['status'], "message" => $msg]);
        exit;
        
    } elseif ($action === 'set_mode') {
        $mode = $_POST['mode'] ?? $_GET['mode'] ?? '';
        if ($mode !== 'smart' && $mode !== 'all') {
            throw new Exception("Неверный режим маршрутизации.");
        }
        
        if (!file_exists($warp_file)) {
            throw new Exception("WARP еще не зарегистрирован.");
        }
        
        $warp_json = json_decode(file_get_contents($warp_file), true);
        if (!$warp_json) {
            throw new Exception("Некорректный файл конфигурации WARP.");
        }
        
        $warp_json['mode'] = $mode;
        
        file_put_contents($warp_file, json_encode($warp_json, JSON_PRETTY_PRINT));
        trigger_rebuild();
        
        echo json_encode(["success" => true, "mode" => $mode, "message" => "Режим маршрутизации успешно изменен."]);
        exit;
        
    } elseif ($action === 'delete') {
        if (file_exists($warp_file)) {
            unlink($warp_file);
        }
        trigger_rebuild();
        
        echo json_encode(["success" => true, "message" => "Подключение WARP успешно удалено."]);
        exit;
        
    } elseif ($action === 'status') {
        if (!file_exists($warp_file)) {
            echo json_encode(["success" => true, "registered" => false]);
            exit;
        }
        
        $warp_json = json_decode(file_get_contents($warp_file), true);
        if (!$warp_json) {
            echo json_encode(["success" => true, "registered" => false]);
            exit;
        }
        
        $warp_json['registered'] = true;
        echo json_encode($warp_json);
        exit;
        
    } else {
        throw new Exception("Неподдерживаемое действие.");
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    exit;
}

function base64url_to_base64($str) {
    $str = str_replace(['-', '_'], ['+', '/'], $str);
    $len = strlen($str) % 4;
    if ($len > 0) {
        $str .= str_repeat('=', 4 - $len);
    }
    return $str;
}
