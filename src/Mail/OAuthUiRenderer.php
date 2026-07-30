<?php

declare(strict_types=1);

namespace Greatcode\OtpReader\Mail;

/**
 * Renders modern, responsive Glassmorphism HTML interfaces for Google OAuth account registration.
 */
class OAuthUiRenderer
{
    /**
     * Render the complete HTML registration page.
     *
     * @param string|null $successMessage Notice to display on successful action
     * @param string|null $errorMessage Notice to display on error
     * @param string $authRedirectUrl Optional OAuth consent URL to generate "Sign in with Google" button
     */
    public static function renderRegisterForm(
        ?string $successMessage = null,
        ?string $errorMessage = null,
        string $authRedirectUrl = ''
    ): string {
        $successAlert = '';
        if ($successMessage !== null && trim($successMessage) !== '') {
            $safeMsg = htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8');
            $successAlert = <<<HTML
            <div class="alert alert-success">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <span>{$safeMsg}</span>
            </div>
            HTML;
        }

        $errorAlert = '';
        if ($errorMessage !== null && trim($errorMessage) !== '') {
            $safeErr = htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8');
            $errorAlert = <<<HTML
            <div class="alert alert-error">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span>{$safeErr}</span>
            </div>
            HTML;
        }

        $googleBtnHtml = '';
        if ($authRedirectUrl !== '') {
            $safeAuthUrl = htmlspecialchars($authRedirectUrl, ENT_QUOTES, 'UTF-8');
            $googleBtnHtml = <<<HTML
            <a href="{$safeAuthUrl}" class="btn-google">
                <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
                <span>Authorize with Google OAuth</span>
            </a>
            <div class="divider"><span>OR MANUAL ENTRY</span></div>
            HTML;
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Mail Reader - Account Registration</title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
            <style>
                :root {
                    --bg-gradient: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
                    --card-bg: rgba(30, 41, 59, 0.7);
                    --card-border: rgba(255, 255, 255, 0.12);
                    --accent-color: #6366f1;
                    --accent-hover: #4f46e5;
                    --text-main: #f8fafc;
                    --text-muted: #94a3b8;
                    --input-bg: rgba(15, 23, 42, 0.6);
                    --input-border: rgba(255, 255, 255, 0.15);
                    --radius-lg: 20px;
                    --radius-md: 12px;
                }

                * { box-sizing: border-box; margin: 0; padding: 0; }

                body {
                    font-family: 'Inter', sans-serif;
                    background: var(--bg-gradient);
                    color: var(--text-main);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }

                .container {
                    width: 100%;
                    max-width: 480px;
                }

                .glass-card {
                    background: var(--card-bg);
                    backdrop-filter: blur(16px);
                    -webkit-backdrop-filter: blur(16px);
                    border: 1px solid var(--card-border);
                    border-radius: var(--radius-lg);
                    padding: 36px 32px;
                    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.1);
                    animation: fadeIn 0.4s ease-out;
                }

                @keyframes fadeIn {
                    from { opacity: 0; transform: translateY(12px); }
                    to { opacity: 1; transform: translateY(0); }
                }

                .header {
                    text-align: center;
                    margin-bottom: 28px;
                }

                .badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    background: rgba(99, 102, 241, 0.15);
                    border: 1px solid rgba(99, 102, 241, 0.3);
                    color: #818cf8;
                    font-size: 12px;
                    font-weight: 600;
                    padding: 4px 12px;
                    border-radius: 9999px;
                    margin-bottom: 12px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }

                h1 {
                    font-family: 'Outfit', sans-serif;
                    font-size: 26px;
                    font-weight: 700;
                    color: var(--text-main);
                    margin-bottom: 6px;
                }

                p.subtitle {
                    font-size: 14px;
                    color: var(--text-muted);
                }

                .alert {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    padding: 14px 16px;
                    border-radius: var(--radius-md);
                    font-size: 14px;
                    margin-bottom: 20px;
                    animation: fadeIn 0.3s ease;
                }

                .alert-success {
                    background: rgba(34, 197, 94, 0.15);
                    border: 1px solid rgba(34, 197, 94, 0.3);
                    color: #4ade80;
                }

                .alert-error {
                    background: rgba(239, 68, 68, 0.15);
                    border: 1px solid rgba(239, 68, 68, 0.3);
                    color: #f87171;
                }

                .tabs {
                    display: flex;
                    background: rgba(15, 23, 42, 0.6);
                    border-radius: 10px;
                    padding: 4px;
                    margin-bottom: 20px;
                }

                .tab-btn {
                    flex: 1;
                    padding: 8px;
                    font-size: 13px;
                    font-weight: 600;
                    border: none;
                    background: transparent;
                    color: var(--text-muted);
                    border-radius: 8px;
                    cursor: pointer;
                    transition: all 0.2s ease;
                }

                .tab-btn.active {
                    background: var(--accent-color);
                    color: #ffffff;
                    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
                }

                .form-group {
                    margin-bottom: 18px;
                }

                label {
                    display: block;
                    font-size: 13px;
                    font-weight: 500;
                    color: #cbd5e1;
                    margin-bottom: 6px;
                }

                input[type="email"], input[type="text"], textarea {
                    width: 100%;
                    padding: 12px 14px;
                    background: var(--input-bg);
                    border: 1px solid var(--input-border);
                    border-radius: var(--radius-md);
                    color: var(--text-main);
                    font-size: 14px;
                    outline: none;
                    transition: border-color 0.2s, box-shadow 0.2s;
                }

                input:focus, textarea:focus {
                    border-color: var(--accent-color);
                    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.25);
                }

                .btn-submit {
                    width: 100%;
                    padding: 14px;
                    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
                    border: none;
                    border-radius: var(--radius-md);
                    color: #ffffff;
                    font-size: 15px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: transform 0.15s ease, box-shadow 0.2s ease;
                    box-shadow: 0 4px 14px rgba(99, 102, 241, 0.4);
                }

                .btn-submit:hover {
                    transform: translateY(-1px);
                    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.5);
                }

                .btn-submit:active {
                    transform: translateY(0);
                }

                .btn-google {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 10px;
                    width: 100%;
                    padding: 12px;
                    background: #ffffff;
                    color: #1e293b;
                    border-radius: var(--radius-md);
                    font-weight: 600;
                    font-size: 14px;
                    text-decoration: none;
                    transition: transform 0.15s ease, box-shadow 0.2s ease;
                    margin-bottom: 20px;
                }

                .btn-google:hover {
                    transform: translateY(-1px);
                    box-shadow: 0 4px 12px rgba(255, 255, 255, 0.2);
                }

                .divider {
                    display: flex;
                    align-items: center;
                    text-align: center;
                    color: var(--text-muted);
                    font-size: 11px;
                    font-weight: 600;
                    margin: 20px 0;
                }

                .divider::before, .divider::after {
                    content: '';
                    flex: 1;
                    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
                }

                .divider span {
                    padding: 0 10px;
                }

                .footer-text {
                    text-align: center;
                    font-size: 12px;
                    color: var(--text-muted);
                    margin-top: 24px;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="glass-card">
                    <div class="header">
                        <div class="badge">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                            Mail Reader Account Register
                        </div>
                        <h1>Register Google Account</h1>
                        <p class="subtitle">Authorize OAuth credentials for automated OTP extraction</p>
                    </div>

                    {$successAlert}
                    {$errorAlert}

                    {$googleBtnHtml}

                    <div class="tabs">
                        <button type="button" class="tab-btn active" onclick="switchTab('refresh_token')">Refresh Token</button>
                        <button type="button" class="tab-btn" onclick="switchTab('auth_code')">Auth Code</button>
                    </div>

                    <form method="POST" action="">
                        <input type="hidden" name="type" id="auth_type" value="refresh_token">

                        <div class="form-group">
                            <label for="email">Gmail Account Email</label>
                            <input type="email" id="email" name="email" placeholder="user@gmail.com" required>
                        </div>

                        <div class="form-group" id="field_refresh_token">
                            <label for="refresh_token">Google Refresh Token</label>
                            <input type="text" id="refresh_token" name="refresh_token" placeholder="1//04xxxxx...">
                        </div>

                        <div class="form-group" id="field_auth_code" style="display: none;">
                            <label for="code">Google Authorization Code</label>
                            <input type="text" id="code" name="code" placeholder="4/0AY0e-g...">
                        </div>

                        <button type="submit" class="btn-submit">Save Account Credential</button>
                    </form>

                    <div class="footer-text">
                        Protected by Thread-Safe Atomic Storage
                    </div>
                </div>
            </div>

            <script>
                function switchTab(type) {
                    document.getElementById('auth_type').value = type;
                    const btns = document.querySelectorAll('.tab-btn');
                    btns.forEach(b => b.classList.remove('active'));

                    if (type === 'refresh_token') {
                        btns[0].classList.add('active');
                        document.getElementById('field_refresh_token').style.display = 'block';
                        document.getElementById('field_auth_code').style.display = 'none';
                    } else {
                        btns[1].classList.add('active');
                        document.getElementById('field_refresh_token').style.display = 'none';
                        document.getElementById('field_auth_code').style.display = 'block';
                    }
                }
            </script>
        </body>
        </html>
        HTML;
    }
}
