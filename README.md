<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Sign In — APEX TRADERS</title>
  <link rel="stylesheet" href="../assets/css/main.css" />
  <link rel="stylesheet" href="../assets/css/pages.css" />
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet" />
</head>
<body>
<div class="cursor" id="cursor"></div>
<div class="cursor-trail" id="cursorTrail"></div>

<nav class="nav" id="nav">
  <a href="../index.html" class="nav-logo">
    <span class="logo-mark">▲</span>
    <span class="logo-text">APEX<em>TRADERS</em></span>
  </a>
  <div style="font-size:0.85rem;color:var(--text-3)">New here? <a href="register.html" style="color:var(--accent)">Create Account</a></div>
</nav>

<div class="auth-page">
  <div class="auth-card">
    <div class="section-tag">WELCOME BACK</div>
    <h2>Sign In</h2>
    <p class="sub">Access your dashboard, bots, and strategies.</p>

    <div class="form-success" id="loginSuccess">Login successful! Redirecting…</div>
    <div class="form-error show" id="loginError" style="display:none;background:rgba(255,77,77,0.08);border:1px solid rgba(255,77,77,0.25);border-radius:8px;padding:12px 16px;margin-bottom:16px;color:var(--red);font-size:0.88rem;"></div>

    <div class="form-group">
      <label>Email Address</label>
      <input type="email" id="loginEmail" placeholder="you@example.com" autocomplete="email" />
      <span class="form-error" id="errLoginEmail">Please enter a valid email.</span>
    </div>
    <div class="form-group">
      <label>Password</label>
      <div class="password-wrap">
        <input type="password" id="loginPass" placeholder="Your password" autocomplete="current-password" />
        <button class="password-toggle" type="button" onclick="togglePass('loginPass',this)">👁</button>
      </div>
      <span class="form-error" id="errLoginPass">Please enter your password.</span>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
      <div class="checkbox-group" style="margin:0">
        <input type="checkbox" id="rememberMe" />
        <label for="rememberMe" style="font-size:0.83rem">Remember me</label>
      </div>
      <a href="forgot-password.html" style="font-size:0.83rem;color:var(--accent)">Forgot password?</a>
    </div>

    <button class="btn-primary btn-full" onclick="doLogin()">Sign In <span>→</span></button>

    <div class="divider">or</div>

    <button class="btn-ghost btn-full" onclick="derivOAuth()" style="gap:10px;justify-content:center">
      <img src="https://deriv.com/favicon.ico" width="16" height="16" alt="Deriv" style="border-radius:2px" onerror="this.style.display='none'">
      Continue with Deriv
    </button>

    <p class="auth-link">Don't have an account? <a href="register.html">Sign Up Free</a></p>
  </div>
</div>

<script src="../assets/js/main.js"></script>
<script>
function togglePass(id, btn) {
  const inp = document.getElementById(id);
  inp.type = inp.type === 'password' ? 'text' : 'password';
  btn.textContent = inp.type === 'password' ? '👁' : '🙈';
}

async function doLogin() {
  const email = document.getElementById('loginEmail').value.trim();
  const pass = document.getElementById('loginPass').value;
  const emailRx = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  let ok = true;

  document.getElementById('errLoginEmail').classList.toggle('show', !emailRx.test(email));
  document.getElementById('errLoginPass').classList.toggle('show', !pass);
  if (!emailRx.test(email) || !pass) return;

  // POST to /api/login.php in production
  document.getElementById('loginSuccess').classList.add('show');
  setTimeout(() => { window.location.href = 'dashboard.html'; }, 1200);
}

function derivOAuth() {
  // Redirect to Deriv OAuth endpoint
  const clientId = 'YOUR_DERIV_APP_ID'; // replace with actual app ID from developers.deriv.com
  window.location.href = `https://oauth.deriv.com/oauth2/authorize?app_id=${clientId}&l=EN&brand=deriv`;
}

document.getElementById('loginPass').addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
</script>
</body>
</html>
