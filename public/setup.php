<?php
/**
 * EsperWorks — cPanel Setup Script (No Terminal Required)
 * 
 * Upload this with the rest of api/public/ to your server.
 * Visit: https://api.yourdomain.com/setup.php
 * DELETE THIS FILE AFTER SETUP IS COMPLETE!
 */

// Security: only run if .env exists
$basePath = dirname(__DIR__);

if (!file_exists($basePath . '/.env')) {
    die('<h2>❌ .env file not found.</h2><p>Create your .env file in the api/ folder first (copy from .env.example and fill in your values).</p>');
}

// Prevent re-running if a lock file exists
$lockFile = $basePath . '/storage/setup.lock';

$action = $_GET['action'] ?? 'home';

// Simple auth — change this before uploading!
$setupPassword = 'EsperSetup2026!';
session_start();

if ($action !== 'home' && ($_SESSION['setup_auth'] ?? false) !== true) {
    if (($_POST['password'] ?? '') === $setupPassword) {
        $_SESSION['setup_auth'] = true;
    } else {
        echo '<html><body style="font-family:sans-serif;max-width:600px;margin:60px auto;padding:20px;">';
        echo '<h2>🔐 Setup Authentication</h2>';
        echo '<form method="POST"><input type="password" name="password" placeholder="Setup password" style="padding:10px;width:300px;border:1px solid #ccc;border-radius:6px;">';
        echo ' <button type="submit" style="padding:10px 20px;background:#00983a;color:#fff;border:none;border-radius:6px;cursor:pointer;">Login</button></form>';
        echo '</body></html>';
        exit;
    }
}

echo '<html><head><title>EsperWorks Setup</title></head>';
echo '<body style="font-family:sans-serif;max-width:700px;margin:40px auto;padding:20px;line-height:1.8;">';
echo '<h1 style="color:#29235c;">⚙️ EsperWorks Setup</h1>';

switch ($action) {
    case 'key':
        echo '<h2>Step 1: Generate App Key</h2>';
        require $basePath . '/vendor/autoload.php';
        $app = require $basePath . '/bootstrap/app.php';
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        
        \Illuminate\Support\Facades\Artisan::call('key:generate', ['--force' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        echo '<pre style="background:#f0f0f0;padding:15px;border-radius:8px;">' . htmlspecialchars($output) . '</pre>';
        echo '<p style="color:green;">✅ App key generated!</p>';
        echo '<p><a href="?action=migrate">→ Next: Run Migrations</a></p>';
        break;

    case 'migrate':
        echo '<h2>Step 2: Run Migrations</h2>';
        require $basePath . '/vendor/autoload.php';
        $app = require $basePath . '/bootstrap/app.php';
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        
        try {
            \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
            $output = \Illuminate\Support\Facades\Artisan::output();
            echo '<pre style="background:#f0f0f0;padding:15px;border-radius:8px;">' . htmlspecialchars($output) . '</pre>';
            echo '<p style="color:green;">✅ Migrations complete!</p>';
        } catch (\Exception $e) {
            echo '<p style="color:red;">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<p>Check your database credentials in .env</p>';
        }
        echo '<p><a href="?action=seed">→ Next: Seed Database</a> (optional — creates test accounts)</p>';
        echo '<p><a href="?action=storage">→ Skip to: Storage Link</a></p>';
        break;

    case 'seed':
        echo '<h2>Step 3: Seed Database (Optional)</h2>';
        require $basePath . '/vendor/autoload.php';
        $app = require $basePath . '/bootstrap/app.php';
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        
        try {
            \Illuminate\Support\Facades\Artisan::call('db:seed', ['--force' => true]);
            $output = \Illuminate\Support\Facades\Artisan::output();
            echo '<pre style="background:#f0f0f0;padding:15px;border-radius:8px;">' . htmlspecialchars($output) . '</pre>';
            echo '<p style="color:green;">✅ Database seeded with test data!</p>';
        } catch (\Exception $e) {
            echo '<p style="color:red;">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        echo '<p><a href="?action=storage">→ Next: Create Storage Link</a></p>';
        break;

    case 'storage':
        echo '<h2>Step 4: Create Storage Link</h2>';
        $target = $basePath . '/storage/app/public';
        $link = $basePath . '/public/storage';
        
        if (is_link($link) || is_dir($link)) {
            echo '<p style="color:green;">✅ Storage link already exists!</p>';
        } else {
            if (@symlink($target, $link)) {
                echo '<p style="color:green;">✅ Storage link created!</p>';
            } else {
                // Symlink may fail on some hosts, try copy approach
                echo '<p style="color:orange;">⚠️ Symlink failed (common on shared hosting). Creating directory copy instead...</p>';
                if (!is_dir($link)) mkdir($link, 0755, true);
                echo '<p>Created public/storage directory. Files uploaded to storage will need manual sync.</p>';
            }
        }
        echo '<p><a href="?action=optimize">→ Next: Optimize</a></p>';
        break;

    case 'optimize':
        echo '<h2>Step 5: Optimize for Production</h2>';
        require $basePath . '/vendor/autoload.php';
        $app = require $basePath . '/bootstrap/app.php';
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        
        \Illuminate\Support\Facades\Artisan::call('config:cache');
        echo '<pre>' . htmlspecialchars(\Illuminate\Support\Facades\Artisan::output()) . '</pre>';
        
        \Illuminate\Support\Facades\Artisan::call('route:cache');
        echo '<pre>' . htmlspecialchars(\Illuminate\Support\Facades\Artisan::output()) . '</pre>';
        
        \Illuminate\Support\Facades\Artisan::call('view:cache');
        echo '<pre>' . htmlspecialchars(\Illuminate\Support\Facades\Artisan::output()) . '</pre>';
        
        echo '<p style="color:green;">✅ Application optimized!</p>';
        echo '<p><a href="?action=verify">→ Next: Verify Installation</a></p>';
        break;

    case 'verify':
        echo '<h2>Step 6: Verify Installation</h2>';
        require $basePath . '/vendor/autoload.php';
        $app = require $basePath . '/bootstrap/app.php';
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        $checks = [];
        
        // Check DB connection
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
            $checks[] = ['✅', 'Database connection', 'Connected successfully'];
        } catch (\Exception $e) {
            $checks[] = ['❌', 'Database connection', $e->getMessage()];
        }
        
        // Check tables
        try {
            $tables = \Illuminate\Support\Facades\DB::select('SHOW TABLES');
            $checks[] = ['✅', 'Database tables', count($tables) . ' tables found'];
        } catch (\Exception $e) {
            $checks[] = ['❌', 'Database tables', 'Could not list tables'];
        }
        
        // Check storage writable
        $checks[] = [is_writable($basePath . '/storage') ? '✅' : '❌', 'Storage writable', $basePath . '/storage'];
        $checks[] = [is_writable($basePath . '/bootstrap/cache') ? '✅' : '❌', 'Cache writable', $basePath . '/bootstrap/cache'];
        
        // Check .env
        $checks[] = [file_exists($basePath . '/.env') ? '✅' : '❌', '.env file', 'Exists'];
        
        // Check APP_KEY
        $key = env('APP_KEY', '');
        $checks[] = [!empty($key) ? '✅' : '❌', 'APP_KEY', !empty($key) ? 'Set' : 'Missing — run Step 1'];
        
        echo '<table style="width:100%;border-collapse:collapse;">';
        foreach ($checks as $c) {
            echo '<tr style="border-bottom:1px solid #eee;"><td style="padding:8px;">' . $c[0] . '</td><td style="padding:8px;font-weight:bold;">' . $c[1] . '</td><td style="padding:8px;color:#666;">' . htmlspecialchars($c[2]) . '</td></tr>';
        }
        echo '</table>';
        
        $allPassed = !in_array('❌', array_column($checks, 0));
        if ($allPassed) {
            echo '<p style="color:green;font-weight:bold;font-size:18px;margin-top:20px;">🎉 All checks passed! Your API is ready.</p>';
            echo '<p>Test the API: <a href="/api/pricing" target="_blank">/api/pricing</a></p>';
            echo '<p style="color:red;font-weight:bold;">⚠️ DELETE this setup.php file now for security!</p>';
            
            // Create lock file
            file_put_contents($lockFile, date('Y-m-d H:i:s'));
        } else {
            echo '<p style="color:red;">Some checks failed. Fix the issues and re-run verification.</p>';
        }
        break;

    case 'clear':
        echo '<h2>Clear All Caches</h2>';
        require $basePath . '/vendor/autoload.php';
        $app = require $basePath . '/bootstrap/app.php';
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        
        \Illuminate\Support\Facades\Artisan::call('cache:clear');
        echo '<pre>' . htmlspecialchars(\Illuminate\Support\Facades\Artisan::output()) . '</pre>';
        \Illuminate\Support\Facades\Artisan::call('config:clear');
        echo '<pre>' . htmlspecialchars(\Illuminate\Support\Facades\Artisan::output()) . '</pre>';
        \Illuminate\Support\Facades\Artisan::call('route:clear');
        echo '<pre>' . htmlspecialchars(\Illuminate\Support\Facades\Artisan::output()) . '</pre>';
        \Illuminate\Support\Facades\Artisan::call('view:clear');
        echo '<pre>' . htmlspecialchars(\Illuminate\Support\Facades\Artisan::output()) . '</pre>';
        echo '<p style="color:green;">✅ All caches cleared!</p>';
        echo '<p><a href="?action=home">← Back</a></p>';
        break;

    default: // home
        echo '<p>Follow these steps in order to set up your EsperWorks backend:</p>';
        echo '<div style="background:#f8f9fa;padding:20px;border-radius:12px;margin:20px 0;">';
        echo '<ol style="font-size:16px;">';
        echo '<li style="margin-bottom:12px;"><a href="?action=key" style="color:#29235c;font-weight:bold;">Generate App Key</a> — Creates encryption key</li>';
        echo '<li style="margin-bottom:12px;"><a href="?action=migrate" style="color:#29235c;font-weight:bold;">Run Migrations</a> — Creates database tables</li>';
        echo '<li style="margin-bottom:12px;"><a href="?action=seed" style="color:#29235c;font-weight:bold;">Seed Database</a> — <em>Optional:</em> Creates test accounts</li>';
        echo '<li style="margin-bottom:12px;"><a href="?action=storage" style="color:#29235c;font-weight:bold;">Create Storage Link</a> — Links public storage</li>';
        echo '<li style="margin-bottom:12px;"><a href="?action=optimize" style="color:#29235c;font-weight:bold;">Optimize</a> — Cache config/routes for performance</li>';
        echo '<li style="margin-bottom:12px;"><a href="?action=verify" style="color:#29235c;font-weight:bold;">Verify Installation</a> — Check everything works</li>';
        echo '</ol>';
        echo '</div>';
        echo '<hr style="margin:20px 0;">';
        echo '<p><a href="?action=clear" style="color:#666;">🔄 Clear All Caches</a> (use if you update .env)</p>';
        echo '<p style="color:red;margin-top:20px;">⚠️ <strong>Delete this file after setup is complete!</strong></p>';
        break;
}

echo '</body></html>';
