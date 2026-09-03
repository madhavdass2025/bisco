<?php
// login.php

require_once __DIR__ . '/includes/auth.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($phone) || empty($password)) {
        $error = 'Please enter phone number and password.';
    } else {
        $res = Auth::login($phone, $password);
        if ($res['success']) {
            header('Location: dashboard.php');
            exit;
        } else {
            $error = $res['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - POWERNET ASSOCIATE - BISCO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-green: #006837;
            --secondary-green: #39B54A;
            --accent-gold: #F7941E;
        }
        body {
            background: linear-gradient(135deg, #006837 0%, #004222 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .card-login {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            border: none;
        }
        .btn-brand {
            background-color: var(--primary-green);
            color: #fff;
            font-weight: 600;
        }
        .btn-brand:hover {
            background-color: var(--secondary-green);
            color: #fff;
        }
        .brand-header {
            color: var(--primary-green);
            font-weight: 700;
        }
        .accent-text {
            color: var(--accent-gold);
        }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card card-login p-4">
                <div class="text-center mb-4">
                    <h3 class="brand-header">POWERNET ASSOCIATE</h3>
                    <div class="badge bg-warning text-dark px-3 py-2 rounded-pill fw-bold">BISCO Network Portal</div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="login.php">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Phone Number</label>
                        <input type="text" name="phone" class="form-control" placeholder="10-digit mobile number" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Your password" required>
                    </div>
                    <button type="submit" class="btn btn-brand w-100 py-2 mt-2">Sign In</button>
                </form>

                <div class="text-center mt-4">
                    <p class="text-muted mb-0">Don't have an account? <a href="register.php" class="fw-bold text-decoration-none accent-text">Register Here</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
