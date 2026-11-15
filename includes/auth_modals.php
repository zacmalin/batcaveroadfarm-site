<?php /* includes/auth_modals.php — login + register modal. JS (min.auth.js) drives it; CSRF is fetched at runtime.
   Native <dialog> opened with showModal() (Nov 15, 2026): the browser keeps focus inside, closes on Esc and hides the page behind from screen readers. */ ?>
<dialog class="auth-modal" id="authModal" aria-labelledby="authLoginTitle">
  <div class="auth-card">
    <button type="button" class="auth-modal-close" data-auth-close aria-label="Close">&times;</button>

    <div class="auth-pane" data-pane="login">
      <h2 id="authLoginTitle">Welcome Back</h2>
      <p class="auth-sub">Log in to rate and comment on recipes.</p>
      <div class="auth-error" role="alert" style="display:none"></div>
      <div class="auth-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <div class="auth-field"><label for="authLoginEmail">Email</label><input id="authLoginEmail" type="email" name="email" autocomplete="email" autocapitalize="none" spellcheck="false"></div>
      <div class="auth-field"><label for="authLoginPw">Password</label><input id="authLoginPw" type="password" name="password" autocomplete="current-password"></div>
      <button type="button" class="auth-btn" data-auth-submit="login">Log In</button>
      <p class="auth-alt"><a href="/account/forgot.php">Forgot password?</a> · <a href="/account/link.php">Email me a sign-in link</a></p>
      <p class="auth-alt">New here? <a href="#" data-auth-switch="register">Create an account</a></p>
    </div>

    <div class="auth-pane" data-pane="register" hidden>
      <h2 id="authRegTitle">Join the Farm</h2>
      <p class="auth-sub">Create an account to rate and comment.</p>
      <div class="auth-error" role="alert" style="display:none"></div>
      <div class="auth-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <div class="auth-row">
        <div class="auth-field"><label for="authRegFirst">First name</label><input id="authRegFirst" type="text" name="first_name" autocomplete="given-name" maxlength="50"></div>
        <div class="auth-field"><label for="authRegLast">Last name</label><input id="authRegLast" type="text" name="last_name" autocomplete="family-name" maxlength="50"></div>
      </div>
      <div class="auth-field"><label for="authRegEmail">Email</label><input id="authRegEmail" type="email" name="email" autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="100"></div>
      <div class="auth-field"><label for="authRegPhone">Phone <span class="auth-hint">(optional)</span></label><input id="authRegPhone" type="tel" name="phone_number" autocomplete="tel" maxlength="20"></div>
      <div class="auth-field"><label for="authRegPw">Password</label><input id="authRegPw" type="password" name="password" autocomplete="new-password" minlength="8" aria-describedby="authRegPwHint"><p class="auth-hint" id="authRegPwHint">At least 8 characters.</p></div>
      <div class="auth-field"><label for="authRegPw2">Confirm password</label><input id="authRegPw2" type="password" name="password_confirm" autocomplete="new-password"></div>
      <button type="button" class="auth-btn" data-auth-submit="register">Create Account</button>
      <p class="auth-alt">Already have an account? <a href="#" data-auth-switch="login">Log in</a></p>
    </div>
  </div>
</dialog>
