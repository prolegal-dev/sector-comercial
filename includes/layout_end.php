    </div><!-- /padding -->
</div><!-- /main-content -->

<script>
// ── Sidebar móvil ──────────────────────────────────────────────────────────
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
}
document.addEventListener('click', e => {
    const sidebar = document.getElementById('sidebar');
    if (window.innerWidth < 768 && sidebar.classList.contains('open') &&
        !sidebar.contains(e.target) && !e.target.closest('button[onclick="toggleSidebar()"]')) {
        sidebar.classList.remove('open');
    }
});

// ── Toast ──────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const icons  = { success: 'fa-check-circle', error: 'fa-circle-exclamation', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
    const colors = { success: '#10b981', error: '#ef4444', warning: '#f59e0b', info: '#0099cd' };
    const c = document.getElementById('toast-container');
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.innerHTML = `<i class="fa-solid ${icons[type] || icons.info}" style="color:${colors[type]};flex-shrink:0"></i><span>${msg}</span>`;
    c.appendChild(t);
    setTimeout(() => {
        t.style.cssText += 'opacity:0;transform:translateX(110%);transition:all .3s';
        setTimeout(() => t.remove(), 320);
    }, 3200);
}

// ── AJAX helper ────────────────────────────────────────────────────────────
async function apiCall(url, data = {}) {
    try {
        const res = await fetch(url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body:    JSON.stringify(data),
        });
        if (res.status === 401) {
            // Sesión expirada → redirigir
            window.location.href = BASE_URL + '/login.php?expired=1';
            return { success: false };
        }
        return await res.json();
    } catch (e) {
        console.error(e);
        return { success: false, error: e.message };
    }
}

// ── Modal helpers ──────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none';  }

// ── Fotos: fallback global para imágenes rotas ──────────────────────────────
// Si una foto de usuario falla (URL rota, usuario sin foto, etc.)
// se reemplaza automáticamente con el avatar por defecto
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('img[onerror]').forEach(function(img) {
        img.addEventListener('error', function() {
            if (this.src.indexOf('avatar-default.svg') === -1) {
                this.src = BASE_URL + '/assets/img/avatar-default.svg';
                this.onerror = null; // Prevenir loop infinito
            }
        });
    });
});
</script>
<?php if (isset($extraScript)) echo $extraScript; ?>
</body>
</html>
