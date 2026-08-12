// UI behaviour: toggle password, simple client validation, show loader then submit
(function(){
  const passwordToggle = document.getElementById('passwordToggle');
  const passwordInput = document.getElementById('password');
  const form = document.getElementById('loginForm');
  const submitBtn = document.getElementById('submitBtn');
  const successBox = document.getElementById('successMessage');
  const usernameInput = document.getElementById('username');

  // Toggle password visibility
  passwordToggle && passwordToggle.addEventListener('click', (e) => {
    e.preventDefault();
    const openIcon = passwordToggle.querySelector('.eye-open');
    const closedIcon = passwordToggle.querySelector('.eye-closed');
    if (passwordInput.type === 'password') {
      passwordInput.type = 'text';
      if(openIcon) openIcon.style.display = 'none';
      if(closedIcon) closedIcon.style.display = 'block';
    } else {
      passwordInput.type = 'password';
      if(openIcon) openIcon.style.display = 'block';
      if(closedIcon) closedIcon.style.display = 'none';
    }
  });

  // client validation helper
  function setError(el, msg){
    const span = el.parentElement.parentElement.querySelector('.gentle-error');
    if(span) span.textContent = msg || '';
  }

  // on submit: validate, show loader animation, then allow normal form submit
  form && form.addEventListener('submit', function(e){
    // simple client-side checks (will not replace server validation)
    let valid = true;
    if(!usernameInput.value.trim()){
      setError(usernameInput, 'Masukkan username');
      valid = false;
    } else setError(usernameInput, '');

    if(!passwordInput.value.trim()){
      setError(passwordInput, 'Masukkan password');
      valid = false;
    } else setError(passwordInput, '');

    if(!valid){
      e.preventDefault();
      return;
    }

    // show button loading state and a small animation before submit so user sees feedback
    submitBtn.classList.add('loading');
    submitBtn.querySelector('.button-text').textContent = 'Signing in...';

    // let the form submit normally after a short delay so PHP login logic runs as before
    // if you prefer immediate submit, reduce the timeout
    setTimeout(() => {
      // do not prevent default; let the browser submit the form
      form.submit();
    }, 300);
    e.preventDefault(); // prevented to control timing above
  });

  // Optional: show success state if server rendered page with a flag (not changing server logic)
  // Server can set a JS variable or add class to body to trigger this; here we simply expose function
  window.showLoginSuccess = function(){
    successBox.classList.add('open');
    setTimeout(()=>{ /* no auto-redirect here; server should handle redirect */ }, 900);
  };

})();