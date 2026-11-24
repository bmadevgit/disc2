const mailbox = document.getElementById('bma-username');
const warning = document.getElementById('email-warning');

mailbox.addEventListener('input', () => {
    const separator = mailbox.value.indexOf('@');
    if (separator >= 0) {
        mailbox.value = mailbox.value.slice(0, separator);
        warning.classList.add('show');
        window.setTimeout(() => warning.classList.remove('show'), 4000);
    }
});

const tabs = document.querySelectorAll('.tab-bar a[data-mode]');
for (const tab of tabs) {
    tab.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        for (const item of tabs) {
            const selected = item === tab;
            const form = document.getElementById('form-' + item.dataset.mode);
            form.hidden = !selected;
            form.classList.toggle('active', selected);
            item.classList.toggle('active', selected);
            if (selected) {
                item.setAttribute('aria-current', 'page');
            } else {
                item.removeAttribute('aria-current');
            }
        }
        document.getElementById('login-mode-description').textContent = tab.dataset.description;
        document.querySelector('#form-' + tab.dataset.mode + ' input[name="username"]').focus();
    });
}
