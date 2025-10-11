<?php
?>

<div class="theme-toggle-wrapper" title="Toggle dark mode">
  <label class="switch" aria-hidden="false">
    <input id="themeToggleCheckbox" type="checkbox" aria-label="Toggle dark mode">
    <span class="slider"></span>
  </label>
</div>

<script>
(function(){
    const checkbox = document.getElementById('themeToggleCheckbox');
    const body = document.body;
    if (!checkbox) return;

    function applyTheme(mode){
        if(mode === 'dark'){
            body.classList.add('dark-mode');
            checkbox.checked = true;
            checkbox.setAttribute('aria-pressed','true');
        } else {
            body.classList.remove('dark-mode');
            checkbox.checked = false;
            checkbox.setAttribute('aria-pressed','false');
        }
    }

    try {
        const saved = localStorage.getItem('site-theme');
        applyTheme(saved === 'dark' ? 'dark' : 'light');
    } catch(e) {
        applyTheme('light');
    }

    checkbox.addEventListener('change', function(){
        const mode = this.checked ? 'dark' : 'light';
        applyTheme(mode);
        try { localStorage.setItem('site-theme', mode); } catch(e){}
    });
})();
</script>