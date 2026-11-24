const mailbox = document.getElementById('bma-username');
const warning = document.getElementById('email-warning');

mailbox.addEventListener('input', () => {
    const name = mailbox.value.replace(/@bangkok\.go\.th$/i, '');
    warning.classList.toggle('show', name !== mailbox.value);
    mailbox.value = name;
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
