import './bootstrap.js';
import './styles/app.scss';

// Bootstrap gère le dropdown nativement après un rechargement complet.
// On garde une init défensive si besoin ailleurs.
function initBootstrapDropdowns() {
    const toggles = document.querySelectorAll('[data-bs-toggle="dropdown"]');
    toggles.forEach((el) => {
        // eslint-disable-next-line no-undef
        bootstrap.Dropdown.getOrCreateInstance(el);
    });
}

document.addEventListener('DOMContentLoaded', initBootstrapDropdowns);
