<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'rsoa_rsoa0142_1');
define('DB_USER', 'rsoa_rsoa0142_1');
define('DB_PASS', '123456');
 
// Create connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
 
// Initialize database tables if they don't exist
 $pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    balance DECIMAL(10,2) DEFAULT 1000.00,
    first_name VARCHAR(50),
    last_name VARCHAR(50),
    phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
 
 $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT,
    receiver_id INT,
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(255),
    status ENUM('pending', 'completed', 'failed') DEFAULT 'completed',
    transaction_id VARCHAR(50) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id),
    FOREIGN KEY (receiver_id) REFERENCES users(id)
)");
 
// Insert demo users if table is empty
 $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
if ($stmt->fetch()['count'] == 0) {
    $demoUsers = [
        ['john_doe', 'john@example.com', 'John', 'Doe', 2500.00],
        ['jane_smith', 'jane@example.com', 'Jane', 'Smith', 1800.00],
        ['mike_wilson', 'mike@example.com', 'Mike', 'Wilson', 3200.00]
    ];
 
    foreach ($demoUsers as $user) {
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, balance) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user[0], $user[1], password_hash('password', PASSWORD_DEFAULT), $user[2], $user[3], $user[4]]);
    }
}
 
// Helper functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}
 
function getCurrentUser() {
    if (isLoggedIn()) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    }
    return null;
}
 
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
 
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
 
function formatCurrency($amount) {
    return '$' . number_format($amount, 2);
}
 
function generateTransactionId() {
    return 'TXN' . strtoupper(uniqid());
}
?>
