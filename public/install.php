<?php
/**
 * EsperWorks Web-Based Installation Tool
 * 
 * Upload this file to: public_html/api/public/install.php
 * Then visit: https://yourdomain.com/api/install.php
 * 
 * This tool will:
 * 1. Check system requirements
 * 2. Run database migrations
 * 3. Seed sample data
 * 4. Set up your application
 * 
 * DELETE THIS FILE AFTER INSTALLATION!
 */

// Security: Only allow access from specific IPs (optional)
// $allowed_ips = ['your.ip.address.here'];
// if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
//     die('Access denied');
// }

// Load Laravel
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

// Get action
$action = $_GET['action'] ?? 'home';
$output = '';
$status = '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EsperWorks Installation</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 32px; margin-bottom: 10px; }
        .header p { opacity: 0.9; }
        .content { padding: 30px; }
        .step {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .step h3 { color: #667eea; margin-bottom: 10px; }
        .btn {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        .btn:hover { background: #5568d3; transform: translateY(-2px); }
        .btn-success { background: #10b981; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #ef4444; }
        .btn-danger:hover { background: #dc2626; }
        .output {
            background: #1e293b;
            color: #e2e8f0;
            padding: 20px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.6;
            max-height: 400px;
            overflow-y: auto;
            margin: 20px 0;
        }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
        .info { color: #3b82f6; }
        .check-item {
            display: flex;
            justify-content: space-between;
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        .check-item:last-child { border-bottom: none; }
        .badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-error { background: #fee2e2; color: #991b1b; }
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .alert-warning { background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 EsperWorks Installation</h1>
            <p>Web-Based Setup Tool for cPanel</p>
        </div>
        
        <div class="content">
            <?php if ($action === 'home'): ?>
                
                <div class="step">
                    <h3>📋 Pre-Installation Checklist</h3>
                    <p>Make sure you've completed these steps:</p>
                    <ul style="margin: 15px 0 0 20px; line-height: 2;">
                        <li>✅ Uploaded all Laravel files to cPanel</li>
                        <li>✅ Created MySQL database in cPanel</li>
                        <li>✅ Created MySQL user and granted privileges</li>
                        <li>✅ Configured .env file with database credentials</li>
                        <li>✅ Set file permissions (755 for folders, 644 for files)</li>
                    </ul>
                </div>

                <div class="step">
                    <h3>🔍 Step 1: System Requirements Check</h3>
                    <p>Check if your server meets all requirements</p>
                    <br>
                    <a href="?action=check" class="btn">Run System Check</a>
                </div>

                <div class="step">
                    <h3>🗄️ Step 2: Database Migration</h3>
                    <p>Create all database tables (25+ tables)</p>
                    <br>
                    <a href="?action=migrate" class="btn">Run Migrations</a>
                </div>

                <div class="step">
                    <h3>🌱 Step 3: Seed Sample Data</h3>
                    <p>Add demo accounts and sample data</p>
                    <br>
                    <a href="?action=seed" class="btn btn-success">Seed Database</a>
                </div>

                <div class="step">
                    <h3>🧹 Step 4: Optimize & Clean Up</h3>
                    <p>Cache configuration and clean up</p>
                    <br>
                    <a href="?action=optimize" class="btn">Optimize Application</a>
                </div>

                <div class="alert alert-warning">
                    <strong>⚠️ Security Warning:</strong> Delete this file (install.php) after installation is complete!
                </div>

            <?php elseif ($action === 'check'): ?>
                
                <h2>🔍 System Requirements Check</h2>
                <br>
                
                <?php
                $checks = [
                    'PHP Version >= 8.2' => version_compare(PHP_VERSION, '8.2.0', '>='),
                    'PDO Extension' => extension_loaded('pdo'),
                    'PDO MySQL Extension' => extension_loaded('pdo_mysql'),
                    'OpenSSL Extension' => extension_loaded('openssl'),
                    'Mbstring Extension' => extension_loaded('mbstring'),
                    'Tokenizer Extension' => extension_loaded('tokenizer'),
                    'XML Extension' => extension_loaded('xml'),
                    'Ctype Extension' => extension_loaded('ctype'),
                    'JSON Extension' => extension_loaded('json'),
                    'BCMath Extension' => extension_loaded('bcmath'),
                    '.env file exists' => file_exists(__DIR__.'/../.env'),
                    'storage/ writable' => is_writable(__DIR__.'/../storage'),
                    'bootstrap/cache/ writable' => is_writable(__DIR__.'/../bootstrap/cache'),
                ];

                $allPassed = true;
                foreach ($checks as $check => $passed) {
                    if (!$passed) $allPassed = false;
                }
                ?>

                <div style="background: #f8f9fa; border-radius: 6px; padding: 20px;">
                    <?php foreach ($checks as $check => $passed): ?>
                        <div class="check-item">
                            <span><?php echo $check; ?></span>
                            <span class="badge <?php echo $passed ? 'badge-success' : 'badge-error'; ?>">
                                <?php echo $passed ? '✓ PASS' : '✗ FAIL'; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <br>
                <?php if ($allPassed): ?>
                    <div class="alert alert-success">
                        <strong>✅ All checks passed!</strong> Your server meets all requirements.
                    </div>
                    <a href="?action=migrate" class="btn btn-success">Continue to Migration →</a>
                <?php else: ?>
                    <div class="alert alert-error">
                        <strong>❌ Some checks failed.</strong> Please fix the issues above before continuing.
                    </div>
                <?php endif; ?>
                
                <br><br>
                <a href="?action=home" class="btn">← Back to Home</a>

            <?php elseif ($action === 'migrate'): ?>
                
                <h2>🗄️ Running Database Migrations</h2>
                <br>
                
                <?php
                try {
                    ob_start();
                    $exitCode = $kernel->call('migrate', ['--force' => true]);
                    $output = ob_get_clean();
                    
                    if ($exitCode === 0) {
                        $status = 'success';
                        echo '<div class="alert alert-success"><strong>✅ Success!</strong> All migrations completed successfully.</div>';
                    } else {
                        $status = 'error';
                        echo '<div class="alert alert-error"><strong>❌ Error!</strong> Migration failed. Check output below.</div>';
                    }
                } catch (Exception $e) {
                    $status = 'error';
                    $output = $e->getMessage();
                    echo '<div class="alert alert-error"><strong>❌ Error!</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
                ?>

                <div class="output"><?php echo htmlspecialchars($output); ?></div>

                <?php if ($status === 'success'): ?>
                    <a href="?action=seed" class="btn btn-success">Continue to Seeding →</a>
                <?php else: ?>
                    <a href="?action=migrate" class="btn">Try Again</a>
                <?php endif; ?>
                
                <br><br>
                <a href="?action=home" class="btn">← Back to Home</a>

            <?php elseif ($action === 'seed'): ?>
                
                <h2>🌱 Seeding Database</h2>
                <br>
                
                <?php
                try {
                    ob_start();
                    $exitCode = $kernel->call('db:seed', ['--force' => true]);
                    $output = ob_get_clean();
                    
                    if ($exitCode === 0) {
                        $status = 'success';
                        echo '<div class="alert alert-success"><strong>✅ Success!</strong> Database seeded with sample data.</div>';
                        echo '<div class="step">';
                        echo '<h3>📧 Demo Accounts Created:</h3>';
                        echo '<p><strong>Admin:</strong> admin@esperworks.com / Admin@2026</p>';
                        echo '<p><strong>Business Owner:</strong> kofi@esperworks.com / Password@2026</p>';
                        echo '</div>';
                    } else {
                        $status = 'error';
                        echo '<div class="alert alert-error"><strong>❌ Error!</strong> Seeding failed. Check output below.</div>';
                    }
                } catch (Exception $e) {
                    $status = 'error';
                    $output = $e->getMessage();
                    echo '<div class="alert alert-error"><strong>❌ Error!</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
                ?>

                <div class="output"><?php echo htmlspecialchars($output); ?></div>

                <?php if ($status === 'success'): ?>
                    <a href="?action=optimize" class="btn btn-success">Continue to Optimization →</a>
                <?php else: ?>
                    <a href="?action=seed" class="btn">Try Again</a>
                <?php endif; ?>
                
                <br><br>
                <a href="?action=home" class="btn">← Back to Home</a>

            <?php elseif ($action === 'optimize'): ?>
                
                <h2>🧹 Optimizing Application</h2>
                <br>
                
                <?php
                try {
                    ob_start();
                    $kernel->call('config:cache');
                    $kernel->call('route:cache');
                    $kernel->call('view:cache');
                    $output = ob_get_clean();
                    
                    echo '<div class="alert alert-success"><strong>✅ Success!</strong> Application optimized for production.</div>';
                    $status = 'success';
                } catch (Exception $e) {
                    $status = 'error';
                    $output = $e->getMessage();
                    echo '<div class="alert alert-error"><strong>❌ Error!</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
                ?>

                <div class="output"><?php echo htmlspecialchars($output); ?></div>

                <?php if ($status === 'success'): ?>
                    <div class="step">
                        <h3>🎉 Installation Complete!</h3>
                        <p>Your EsperWorks backend is now ready to use.</p>
                        <br>
                        <p><strong>Next Steps:</strong></p>
                        <ol style="margin: 15px 0 0 20px; line-height: 2;">
                            <li>Test your API: <a href="../health" target="_blank">../health</a></li>
                            <li>Update your Vercel frontend environment variables</li>
                            <li><strong style="color: #ef4444;">DELETE THIS FILE (install.php) for security!</strong></li>
                        </ol>
                    </div>
                    <br>
                    <a href="../health" class="btn btn-success" target="_blank">Test API Health →</a>
                <?php endif; ?>
                
                <br><br>
                <a href="?action=home" class="btn">← Back to Home</a>

            <?php endif; ?>
        </div>
    </div>
</body>
</html>
