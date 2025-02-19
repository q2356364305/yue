<?php
// 禁止使用 F12 开发者工具（虽然在 PHP 层面不能完全阻止，但可以通过 HTTP 头做一定限制）
header('X-DevTools-Emulate-Network-Conditions-Client-Id: blocked');
// 引入PHPMailer库，用于发送邮件
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// 获取要监控的账号参数
$account = isset($_GET['account']) ? $_GET['account'] : 'account1';

// 账号配置信息，可根据实际情况修改
$accounts = [
    'account1' => [
        'username' => 'jiushennb2',
        'password' => '20030729..'
    ],
    'account2' => [
        'username' => 'jiushennb1',
        'password' => '20030729..'
    ]
];

if (!isset($accounts[$account])) {
    echo json_encode(['error' => 'Invalid account']);
    exit;
}

$username = $accounts[$account]['username'];
$password = $accounts[$account]['password'];

// 定义监控时间间隔（单位：秒），这里设置为 60 秒（1 分钟）
$monitorInterval = 60;

// 记录上次监控的时间文件
$lastMonitorFile = "last_monitor_time_{$account}.txt";

// 获取上次监控的时间
if (file_exists($lastMonitorFile)) {
    $lastMonitorTime = file_get_contents($lastMonitorFile);
} else {
    $lastMonitorTime = 0;
}

// 当前时间
$currentTime = time();

// 判断是否达到监控时间间隔
if ($currentTime - $lastMonitorTime >= $monitorInterval) {
    // 登录函数
    function login($username, $password) {
        $mainUrl = "http://api.sqhyw.net:90/api/logins?username=$username&password=$password";
        $backupUrl = "http://api.nnanx.com:90/api/logins?username=$username&password=$password";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // 尝试主域名
        curl_setopt($ch, CURLOPT_URL, $mainUrl);
        $response = curl_exec($ch);
        if (curl_errno($ch) ||!$response) {
            // 主域名请求失败，尝试备用域名
            curl_setopt($ch, CURLOPT_URL, $backupUrl);
            $response = curl_exec($ch);
        }

        if (curl_errno($ch)) {
            echo 'Curl error: '. curl_error($ch);
            return null;
        }
        curl_close($ch);

        $data = json_decode($response, true);
        if ($data && isset($data['token'])) {
            return $data['token'];
        } elseif ($data && isset($data['error']) && strpos($data['error'], '密码错误')!== false) {
            // 密码错误，发送邮件通知
            sendNotification("登录密码错误通知", "账户 {$username} 的登录密码错误");
            return null;
        }
        return null;
    }

    // 获取余额函数
    function getBalance($token) {
        $mainUrl = "http://api.sqhyw.net:90/api/get_myinfo?token=$token";
        $backupUrl = "http://api.nnanx.com:90/api/get_myinfo?token=$token";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // 尝试主域名
        curl_setopt($ch, CURLOPT_URL, $mainUrl);
        $response = curl_exec($ch);
        if (curl_errno($ch) ||!$response) {
            // 主域名请求失败，尝试备用域名
            curl_setopt($ch, CURLOPT_URL, $backupUrl);
            $response = curl_exec($ch);
        }

        if (curl_errno($ch)) {
            echo 'Curl error: '. curl_error($ch);
            return null;
        }
        curl_close($ch);

        $data = json_decode($response, true);
        if ($data && isset($data['data'][0]['money'])) {
            return $data['data'][0]['money'];
        } elseif ($data && isset($data['error']) && strpos($data['error'], 'token过期')!== false) {
            // token 过期，发送邮件通知
            sendNotification("Token 过期通知", "当前使用的 Token 已过期");
            return null;
        }
        return null;
    }

    // 登录获取 token
    $token = login($username, $password);

    if ($token) {
        // 使用 token 获取余额
        $balance = getBalance($token);
        if ($balance!== null) {
            // 获取当前日期和时间
            $dateTime = date('Y-m-d H:i:s');

            // 读取历史余额记录文件
            $logFile = "balance_log_{$account}.txt";
            if (file_exists($logFile)) {
                $lines = file($logFile, FILE_IGNORE_NEW_LINES);
                if (!empty($lines)) {
                    $lastLine = end($lines);
                    list($lastDateTime, $lastBalance) = explode(': ', $lastLine);
                    $lastBalance = (float)$lastBalance;
                } else {
                    $lastBalance = null;
                }
            } else {
                $lastBalance = null;
            }

            // 判断余额是否变少或异常
            if ($lastBalance!== null && $balance < $lastBalance) {
                sendNotification("余额变少通知", "账户 {$account} 的余额从 {$lastBalance} 变为 {$balance}");
            } elseif ($balance < 0) {
                sendNotification("余额异常通知", "账户 {$account} 的余额出现异常，当前余额为 {$balance}");
            }

            // 保存余额信息到文件
            $logEntry = "$dateTime: $balance\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND);

            // 更新上次监控时间
            file_put_contents($lastMonitorFile, $currentTime);
        }
    }
}

// 历史余额记录文件
$logFile = "balance_log_{$account}.txt";

// 读取历史余额记录文件
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES);
    if (!empty($lines)) {
        $lastLine = end($lines);
        list($dateTime, $balance) = explode(': ', $lastLine);
        echo json_encode([
            'dateTime' => $dateTime,
            'balance' => $balance
        ]);
    } else {
        echo json_encode(['error' => 'No balance records found']);
    }
} else {
    echo json_encode(['error' => 'Balance log file not found']);
}

// 发送邮件通知函数
function sendNotification($subject, $message) {
    $mail = new PHPMailer(true);
    try {
        // 服务器设置
        $mail->SMTPDebug = 0;                      // 调试模式
        $mail->isSMTP();                           // 使用SMTP发送
        $mail->Host       = 'smtp.qq.com';         // SMTP服务器
        $mail->SMTPAuth   = true;                  // 开启SMTP认证
        $mail->Username   = '9358924@qq.com';      // 发件人邮箱
        $mail->Password   = 'astyjcuubyqybjhj';    // 邮箱授权码
        $mail->SMTPSecure = 'ssl';                  // 加密方式
        $mail->Port       = 465;                    // 端口号

        // 收件人
        $mail->setFrom('9358924@qq.com', '余额监控系统');
        $mail->addAddress('2356241@qq.com');        // 收件人邮箱

        // 内容
        $mail->isHTML(false);                       // 纯文本格式
        $mail->Subject = $subject;
        $mail->Body    = $message;

        $mail->send();
        // echo '邮件已发送';
    } catch (Exception $e) {
        // echo "邮件发送失败: {$mail->ErrorInfo}";
    }
}
?>