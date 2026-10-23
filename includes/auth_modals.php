<?php /* includes/auth_modals.php — login + register modal. JS (min.auth.js) drives it; CSRF is fetched at runtime. */ ?>
<div class="auth-modal" id="authModal" hidden>
  <div class="auth-card">
    <button type="button" class="auth-modal-close" data-auth-close aria-label="Close">&times;</button>

    <div class="auth-pane" data-pane="login">
      <h2>Welcome Back</h2>
      <p class="auth-sub">Log in to rate and comment on recipes.</p>
      <div class="auth-error" role="alert" style="display:none"></div>
      <div class="auth-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <div class="auth-field"><label>Email</label><input type="email" name="email" autocomplete="email"></div>
      <div class="auth-field"><label>Password</label><input type="password" name="password" autocomplete="current-password"></div>
      <button type="button" class="auth-btn" data-auth-submit="login">Log In</button>
      <p class="auth-alt"><a href="/account/forgot.php">Forgot password?</a></p>
      <p class="auth-alt">New here? <a href="#" data-auth-switch="register">Create an account</a></p>
    </div>

    <div class="auth-pane" data-pane="register" hidden>
      <h2>Join the Farm</h2>
      <p class="auth-sub">Create an account to rate and comment.</p>
      <div class="auth-error" role="alert" style="display:none"></div>
      <div class="auth-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <div class="auth-row">
        <div class="auth-field"><label>First name</label><input type="text" name="first_name" autocomplete="given-name" maxlength="50"></div>
        <div class="auth-field"><label>Last name</label><input type="text" name="last_name" autocomplete="family-name" maxlength="50"></div>
      </div>
      <div class="auth-field"><label>Email</label><input type="email" name="email" autocomplete="email" maxlength="100"></div>
      <div class="auth-field"><label>Phone <span class="auth-hint">(optional)</span></label><input type="tel" name="phone_number" autocomplete="tel" maxlength="20"></div>
      <div class="auth-field"><label>Password</label><input type="password" name="password" autocomplete="new-password"><p class="auth-hint">At least 8 characters.</p></div>
      <div class="auth-field"><label>Confirm password</label><input type="password" name="password_confirm" autocomplete="new-password"></div>
      <button type="button" class="auth-btn" data-auth-submit="register">Create Account</button>
      <p class="auth-alt">Already have an account? <a href="#" data-auth-switch="login">Log in</a></p>
    </div>
  </div>
</div>
