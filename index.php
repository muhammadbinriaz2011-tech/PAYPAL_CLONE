<?php
// Start session
session_start();

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

// Handle form submissions
 $message = '';
 $error = '';
 $currentPage = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'login':
            if (verifyCSRFToken($_POST['csrf_token'])) {
                $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
                $password = $_POST['password'];
                
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
                $stmt->execute([$username, $username]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password_hash'])) {
                    $_SESSION['user_id'] = $user['id'];
                    header('Location: index.php?page=dashboard');
                    exit;
                } else {
                    $error = "Invalid username or password";
                }
            }
            break;
            
        case 'register':
            if (verifyCSRFToken($_POST['csrf_token'])) {
                $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
                $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
                $password = $_POST['password'];
                $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
                $last_name = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
                
                if (strlen($password) < 6) {
                    $error = "Password must be at least 6 characters";
                } else {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $first_name, $last_name]);
                        $message = "Registration successful! Please login.";
                    } catch(PDOException $e) {
                        $error = "Username or email already exists";
                    }
                }
            }
            break;
            
        case 'send_money':
            if (verifyCSRFToken($_POST['csrf_token']) && isLoggedIn()) {
                $receiver_username = filter_input(INPUT_POST, 'receiver', FILTER_SANITIZE_STRING);
                $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
                $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
                
                if ($amount <= 0) {
                    $error = "Amount must be greater than 0";
                } else {
                    $sender = getCurrentUser();
                    
                    if ($sender['balance'] < $amount) {
                        $error = "Insufficient balance";
                    } else {
                        // Check both username and email for recipient
                        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
                        $stmt->execute([$receiver_username, $receiver_username]);
                        $receiver = $stmt->fetch();
                        
                        if (!$receiver) {
                            $error = "Recipient not found. Please check username or email and try again.";
                        } elseif ($receiver['id'] == $sender['id']) {
                            $error = "Cannot send money to yourself";
                        } else {
                            try {
                                $pdo->beginTransaction();
                                
                                // Update sender balance
                                $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
                                $stmt->execute([$amount, $sender['id']]);
                                
                                // Update receiver balance
                                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                                $stmt->execute([$amount, $receiver['id']]);
                                
                                // Create transaction record
                                $transaction_id = generateTransactionId();
                                $stmt = $pdo->prepare("INSERT INTO transactions (sender_id, receiver_id, amount, description, transaction_id) VALUES (?, ?, ?, ?, ?)");
                                $stmt->execute([$sender['id'], $receiver['id'], $amount, $description, $transaction_id]);
                                
                                $pdo->commit();
                                $message = "Money sent successfully to " . htmlspecialchars($receiver['first_name'] . " " . $receiver['last_name']) . "!";
                            } catch(PDOException $e) {
                                $pdo->rollBack();
                                $error = "Transaction failed: " . $e->getMessage();
                            }
                        }
                    }
                }
            }
            break;
            
        case 'update_profile':
            if (verifyCSRFToken($_POST['csrf_token']) && isLoggedIn()) {
                $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
                $last_name = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
                $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
                
                $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE id = ?");
                $stmt->execute([$first_name, $last_name, $phone, $_SESSION['user_id']]);
                
                $message = "Profile updated successfully!";
            }
            break;
            
        case 'logout':
            session_destroy();
            header('Location: index.php');
            exit;
    }
}

// Redirect if not logged in
if (!isLoggedIn() && !in_array($currentPage, ['login', 'register'])) {
    header('Location: index.php?page=login');
    exit;
}

// Get current user data
 $user = getCurrentUser();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PayPal - Send Money, Pay Online or Set Up a Merchant Account</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 2rem;
            flex-wrap: wrap;
        }
        
        .logo {
            font-size: 1.5rem;
            font-weight: bold;
            color: #003087;
        }
        
        .nav-links {
            display: flex;
            gap: 2rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .nav-links a {
            text-decoration: none;
            color: #333;
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .nav-links a:hover {
            color: #003087;
        }
        
        .balance-display {
            background: #003087;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            font-weight: bold;
        }
        
        .card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #555;
        }
        
        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        textarea,
        select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: #003087;
        }
        
        button {
            background: #003087;
            color: white;
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        button:hover {
            background: #002561;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #545b62;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .transaction-list {
            margin-top: 2rem;
        }
        
        .transaction-item {
            padding: 1rem;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .transaction-item:last-child {
            border-bottom: none;
        }
        
        .transaction-info {
            flex: 1;
        }
        
        .transaction-amount {
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .amount-positive {
            color: #28a745;
        }
        
        .amount-negative {
            color: #dc3545;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            opacity: 0.9;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }
        
        .pagination a {
            padding: 0.5rem 1rem;
            text-decoration: none;
            border-radius: 5px;
            background: #f0f0f0;
            color: #333;
            transition: background 0.3s;
        }
        
        .pagination a:hover {
            background: #e0e0e0;
        }
        
        .pagination a.active {
            background: #003087;
            color: white;
        }
        
        .user-suggestions {
            margin-top: 0.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .user-tag {
            background: #f0f0f0;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-size: 0.85rem;
            cursor: pointer;
        }
        
        .user-tag:hover {
            background: #e0e0e0;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #666;
        }
        
        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .empty-state-title {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: #555;
        }
        
        .empty-state-description {
            margin-bottom: 1.5rem;
        }
        
        @media (max-width: 768px) {
            .nav-links {
                flex-direction: column;
                gap: 1rem;
                width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header>
        <nav>
            <div class="logo">💳 PayPal</div>
            <div class="nav-links">
                <?php if (isLoggedIn()): ?>
                    <a href="index.php?page=dashboard">Dashboard</a>
                    <a href="index.php?page=send">Send Money</a>
                    <a href="index.php?page=transactions">Transactions</a>
                    <a href="index.php?page=profile">Profile</a>
                    <div class="balance-display">Balance: <?php echo formatCurrency($user['balance']); ?></div>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <button type="submit" class="btn-secondary">Logout</button>
                    </form>
                <?php else: ?>
                    <a href="index.php?page=login">Login</a>
                    <a href="index.php?page=register">Sign Up</a>
                <?php endif; ?>
            </div>
        </nav>
    </header>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php
        // Page content based on current page
        switch ($currentPage) {
            case 'login':
                ?>
                <div class="card" style="max-width: 500px; margin: 0 auto;">
                    <h2 style="margin-bottom: 2rem; color: #003087;">Log in to your PayPal account</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        
                        <div class="form-group">
                            <label for="username">Email or username</label>
                            <input type="text" id="username" name="username" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                        
                        <button type="submit">Log In</button>
                        <p style="margin-top: 1rem;">
                            Don't have an account? <a href="index.php?page=register">Sign Up</a>
                        </p>
                    </form>
                </div>
                <?php
                break;
                
            case 'register':
                ?>
                <div class="card" style="max-width: 500px; margin: 0 auto;">
                    <h2 style="margin-bottom: 2rem; color: #003087;">Sign up for PayPal</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="register">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="password">Password (min 6 characters)</label>
                            <input type="password" id="password" name="password" required minlength="6">
                        </div>
                        
                        <button type="submit">Sign Up</button>
                        <p style="margin-top: 1rem;">
                            Already have an account? <a href="index.php?page=login">Log In</a>
                        </p>
                    </form>
                </div>
                <?php
                break;
                
            case 'dashboard':
                // Get recent transactions
                $stmt = $pdo->prepare("SELECT t.*, u1.username as sender_name, u2.username as receiver_name 
                                      FROM transactions t 
                                      LEFT JOIN users u1 ON t.sender_id = u1.id 
                                      LEFT JOIN users u2 ON t.receiver_id = u2.id 
                                      WHERE t.sender_id = ? OR t.receiver_id = ? 
                                      ORDER BY t.created_at DESC LIMIT 5");
                $stmt->execute([$user['id'], $user['id']]);
                $recentTransactions = $stmt->fetchAll();
                
                // Get stats
                $stmt = $pdo->prepare("SELECT COUNT(*) as total_sent FROM transactions WHERE sender_id = ?");
                $stmt->execute([$user['id']]);
                $totalSent = $stmt->fetch()['total_sent'];
                
                $stmt = $pdo->prepare("SELECT COUNT(*) as total_received FROM transactions WHERE receiver_id = ?");
                $stmt->execute([$user['id']]);
                $totalReceived = $stmt->fetch()['total_received'];
                
                $stmt = $pdo->prepare("SELECT SUM(amount) as total_amount_sent FROM transactions WHERE sender_id = ?");
                $stmt->execute([$user['id']]);
                $totalAmountSent = $stmt->fetch()['total_amount_sent'] ?: 0;
                ?>
                <h2 style="color: white; margin-bottom: 2rem;">Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>!</h2>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo formatCurrency($user['balance']); ?></div>
                        <div class="stat-label">Current Balance</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $totalSent; ?></div>
                        <div class="stat-label">Payments Sent</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $totalReceived; ?></div>
                        <div class="stat-label">Payments Received</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo formatCurrency($totalAmountSent); ?></div>
                        <div class="stat-label">Total Sent</div>
                    </div>
                </div>
                
                <div class="card">
                    <h3 style="margin-bottom: 1.5rem; color: #003087;">Quick Actions</h3>
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                        <a href="index.php?page=send" style="text-decoration: none;">
                            <button>Send Money</button>
                        </a>
                        <a href="index.php?page=transactions" style="text-decoration: none;">
                            <button class="btn-secondary">View All Transactions</button>
                        </a>
                        <a href="index.php?page=profile" style="text-decoration: none;">
                            <button class="btn-secondary">Update Profile</button>
                        </a>
                    </div>
                </div>
                
                <div class="card">
                    <h3 style="margin-bottom: 1.5rem; color: #003087;">Recent Transactions</h3>
                    <?php if (empty($recentTransactions)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">💸</div>
                            <div class="empty-state-title">No transactions yet</div>
                            <div class="empty-state-description">You haven't made any transactions yet. Send money to see your transaction history here.</div>
                            <a href="index.php?page=send" style="text-decoration: none;">
                                <button>Send Money</button>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="transaction-list">
                            <?php foreach ($recentTransactions as $transaction): ?>
                                <div class="transaction-item">
                                    <div class="transaction-info">
                                        <div style="font-weight: 500;">
                                            <?php 
                                            if ($transaction['sender_id'] == $user['id']) {
                                                echo 'Sent to ' . htmlspecialchars($transaction['receiver_name']);
                                            } else {
                                                echo 'Received from ' . htmlspecialchars($transaction['sender_name']);
                                            }
                                            ?>
                                        </div>
                                        <div style="color: #666; font-size: 0.9rem;">
                                            <?php echo date('M j, Y g:i A', strtotime($transaction['created_at'])); ?>
                                        </div>
                                        <?php if ($transaction['description']): ?>
                                            <div style="color: #888; font-size: 0.85rem; margin-top: 0.25rem;">
                                                <?php echo htmlspecialchars($transaction['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="transaction-amount <?php echo $transaction['sender_id'] == $user['id'] ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo $transaction['sender_id'] == $user['id'] ? '-' : '+'; ?>
                                        <?php echo formatCurrency($transaction['amount']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top: 1rem;">
                            <a href="index.php?page=transactions" style="color: #003087; text-decoration: none; font-weight: 500;">
                                View All Transactions →
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <?php
                break;
                
            case 'send':
                // Get all users for suggestions
                $stmt = $pdo->prepare("SELECT username, email, first_name, last_name FROM users WHERE id != ? ORDER BY first_name LIMIT 10");
                $stmt->execute([$user['id']]);
                $suggestedUsers = $stmt->fetchAll();
                ?>
                <div class="card" style="max-width: 600px; margin: 0 auto;">
                    <h2 style="margin-bottom: 2rem; color: #003087;">Send Money</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="send_money">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        
                        <div class="form-group">
                            <label for="receiver">Recipient's email or username</label>
                            <input type="text" id="receiver" name="receiver" required placeholder="Enter email or username">
                            
                            <?php if (!empty($suggestedUsers)): ?>
                                <div class="user-suggestions">
                                    <small style="color: #666; width: 100%;">Click to select:</small>
                                    <?php foreach ($suggestedUsers as $suggestedUser): ?>
                                        <span class="user-tag" onclick="document.getElementById('receiver').value='<?php echo htmlspecialchars($suggestedUser['username']); ?>'">
                                            <?php echo htmlspecialchars($suggestedUser['first_name'] . ' ' . $suggestedUser['last_name']); ?> (<?php echo htmlspecialchars($suggestedUser['username']); ?>)
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="amount">Amount</label>
                            <input type="number" id="amount" name="amount" required min="0.01" step="0.01" placeholder="0.00">
                            <small style="color: #666;">Available balance: <?php echo formatCurrency($user['balance']); ?></small>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Note (optional)</label>
                            <textarea id="description" name="description" rows="3" placeholder="What's this payment for?"></textarea>
                        </div>
                        
                        <button type="submit">Send Payment</button>
                    </form>
                </div>
                <?php
                break;
                
            case 'transactions':
                $page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
                $limit = 10;
                $offset = ($page - 1) * $limit;
                
                // Get total transactions count
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM transactions WHERE sender_id = ? OR receiver_id = ?");
                $stmt->execute([$user['id'], $user['id']]);
                $total = $stmt->fetch()['total'];
                $totalPages = ceil($total / $limit);
                
                // Get transactions with proper error handling
                try {
                    // Fixed: Use bindValue with PARAM_INT for LIMIT and OFFSET
                    $stmt = $pdo->prepare("SELECT t.*, u1.username as sender_name, u2.username as receiver_name 
                                          FROM transactions t 
                                          LEFT JOIN users u1 ON t.sender_id = u1.id 
                                          LEFT JOIN users u2 ON t.receiver_id = u2.id 
                                          WHERE t.sender_id = ? OR t.receiver_id = ? 
                                          ORDER BY t.created_at DESC 
                                          LIMIT ? OFFSET ?");
                    $stmt->bindValue(1, $user['id']);
                    $stmt->bindValue(2, $user['id']);
                    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
                    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
                    $stmt->execute();
                    $transactions = $stmt->fetchAll();
                } catch (PDOException $e) {
                    $error = "Error retrieving transactions: " . $e->getMessage();
                    $transactions = [];
                }
                ?>
                <div class="card">
                    <h2 style="margin-bottom: 2rem; color: #003087;">Transaction History</h2>
                    
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    
                    <?php if (empty($transactions)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">💸</div>
                            <div class="empty-state-title">No transactions yet</div>
                            <div class="empty-state-description">You haven't made any transactions yet. Send money to see your transaction history here.</div>
                            <a href="index.php?page=send" style="text-decoration: none;">
                                <button>Send Money</button>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="transaction-list">
                            <?php foreach ($transactions as $transaction): ?>
                                <div class="transaction-item">
                                    <div class="transaction-info">
                                        <div style="font-weight: 500;">
                                            <?php 
                                            if ($transaction['sender_id'] == $user['id']) {
                                                echo 'Sent to ' . htmlspecialchars($transaction['receiver_name']);
                                            } else {
                                                echo 'Received from ' . htmlspecialchars($transaction['sender_name']);
                                            }
                                            ?>
                                        </div>
                                        <div style="color: #666; font-size: 0.9rem;">
                                            <?php echo date('M j, Y g:i A', strtotime($transaction['created_at'])); ?>
                                        </div>
                                        <div style="color: #888; font-size: 0.85rem;">
                                            Transaction ID: <?php echo htmlspecialchars($transaction['transaction_id']); ?>
                                        </div>
                                        <?php if ($transaction['description']): ?>
                                            <div style="color: #888; font-size: 0.85rem; margin-top: 0.25rem;">
                                                <?php echo htmlspecialchars($transaction['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="transaction-amount <?php echo $transaction['sender_id'] == $user['id'] ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo $transaction['sender_id'] == $user['id'] ? '-' : '+'; ?>
                                        <?php echo formatCurrency($transaction['amount']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($totalPages > 1): ?>
                            <div class="pagination">
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <a href="index.php?page=transactions&p=<?php echo $i; ?>" 
                                       class="<?php echo $i == $page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php
                break;
                
            case 'profile':
                ?>
                <div class="card" style="max-width: 600px; margin: 0 auto;">
                    <h2 style="margin-bottom: 2rem; color: #003087;">My Profile</h2>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                            <small style="color: #666;">Username cannot be changed</small>
                        </div>
                        
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                            <small style="color: #666;">Email cannot be changed</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Account Created</label>
                            <input type="text" value="<?php echo date('M j, Y', strtotime($user['created_at'])); ?>" disabled>
                        </div>
                        
                        <button type="submit">Update Profile</button>
                    </form>
                </div>
                <?php
                break;
                
            default:
                header('Location: index.php?page=dashboard');
                exit;
        }
        ?>
    </div>
    
    <script>
        // Simple form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const requiredFields = form.querySelectorAll('[required]');
                    let valid = true;
                    
                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            valid = false;
                            field.style.borderColor = '#dc3545';
                        } else {
                            field.style.borderColor = '#e0e0e0';
                        }
                    });
                    
                    if (!valid) {
                        e.preventDefault();
                        alert('Please fill in all required fields');
                    }
                });
            });
        });
    </script>
</body>
</html>
