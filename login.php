<?php
session_start();
require_once __DIR__ . '/db_salam.php';

// Variabel username dari form login.
$form_username = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $form_username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $sql = "SELECT * FROM users WHERE username=? AND role IN ('admin', 'superadmin') LIMIT 1";
    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param("s", $form_username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password'])) {
            // sukses login
            $_SESSION['login'] = true;
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['wilayah'] = $row['wilayah'];

            // Admin diarahkan ke dashboard wilayahnya, Super Admin melihat semua wilayah.
            header("Location: dashboard_salam.php");
            exit;
        } else {
            $error_message = "Password salah!";
        }
    } else {
        $error_message = "Username tidak ditemukan!";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login - Billing Multiwilayah</title>
    <link rel="stylesheet" href="login_style.css">
    <link rel="icon" href="logo_cleon.png" type="image/png">
    <link rel="shortcut icon" href="logo_cleon.png" type="image/png">
    <link rel="apple-touch-icon" sizes="180x180" href="logo_cleon.png">
</head>
<body>
    <div class="soft-background">
        <div class="floating-shapes">
            <div class="soft-blob blob-1"></div>
            <div class="soft-blob blob-2"></div>
            <div class="soft-blob blob-3"></div>
            <div class="soft-blob blob-4"></div>
        </div>
    </div>

    <div class="login-container">
        <div class="soft-card">
            <div class="comfort-header">
                <div class="gentle-logo">
                    <!-- <div class="logo-circle">
                        <div class="comfort-icon">
                            
                            <svg width="32" height="32" viewBox="0 0 32 32" fill="none" aria-hidden="true">
                                <path d="M16 2C8.3 2 2 8.3 2 16s6.3 14 14 14 14-6.3 14-14S23.7 2 16 2z" fill="none" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M12 16a4 4 0 108 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                                <circle cx="20" cy="12" r="1.5" fill="currentColor"/>
                            </svg>
                        </div>
                        <div class="gentle-glow"></div>
                    </div> -->
                     <div class="logo">
                        <!-- Ganti src logo.png dengan logo Anda -->
                        <img src="logo_cleon.png" alt="Logo" style="width:90px; height:auto;">
                    </div>
                </div>
                <h1 class="comfort-title">Welcome back</h1>
                <p class="gentle-subtitle">Sign in to Billing Multiwilayah</p>
            </div>

            <?php
            // optionally show server-side error message if set by existing logic
            if (!empty($error_message ?? '')): ?>
                <div class="server-error" role="alert" style="color: #b91c1c; margin:12px 0; font-weight:600;"><?= htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <form class="comfort-form" id="loginForm" method="post" novalidate>
                <div class="soft-field">
                    <div class="field-container">
                        <input type="text" id="username" name="username" required autocomplete="username" value="<?= htmlspecialchars($form_username ?? ''); ?>">
                        <label for="username">Username</label>
                        <div class="field-accent"></div>
                    </div>
                    <span class="gentle-error" id="usernameError"></span>
                </div>

                <div class="soft-field">
                    <div class="field-container">
                        <input type="password" id="password" name="password" required autocomplete="current-password">
                        <label for="password">Password</label>
                        <button type="button" class="gentle-toggle" id="passwordToggle" aria-label="Toggle password visibility">
                            <div class="toggle-icon">
                                <svg class="eye-closed" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" style="display:none">
                                    <path d="M3 3l14 14M8.5 8.5a3 3 0 004 4m2.5-2.5C15 10 12.5 7 10 7c-.5 0-1 .1-1.5.3M10 13c-2.5 0-4.5-2-5-3 .3-.6.7-1.2 1.2-1.7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                        </button>
                        <div class="field-accent"></div>
                    </div>
                    <span class="gentle-error" id="passwordError"></span>
                </div>

                <button type="submit" class="comfort-button" id="submitBtn">
                    <div class="button-background"></div>
                    <span class="button-text">Sign in</span>
                    <div class="button-loader" aria-hidden="true">
                        <div class="gentle-spinner">
                            <div class="spinner-circle"></div>
                        </div>
                    </div>
                    <div class="button-glow"></div>
                </button>
            </form>

            <div class="gentle-success" id="successMessage" aria-hidden="true">
                <div class="success-bloom">
                    <div class="bloom-rings">
                        <div class="bloom-ring ring-1"></div>
                        <div class="bloom-ring ring-2"></div>
                        <div class="bloom-ring ring-3"></div>
                    </div>
                    <div class="success-icon">
                        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" aria-hidden="true">
                            <path d="M8 14l5 5 11-11" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                </div>
                <h3 class="success-title">Welcome!</h3>
                <p class="success-desc">Taking you to your dashboard...</p>
            </div>
        </div>
    </div>

    <script src="login_script.js"></script>
</body>
</html>
