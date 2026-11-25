const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { runInNewContext } = require('node:vm');

const loginScript = readFileSync(join(__dirname, '../../../public/assets/login.js'), 'utf8');

function renderMailbox() {
    const mailbox = new EventTarget();
    const classes = new Set();
    const warning = {
        classList: {
            add: name => classes.add(name),
            remove: name => classes.delete(name),
            toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name),
        },
    };
    const timers = [];
    runInNewContext(loginScript, {
        document: {
            getElementById: id => ({ 'bma-username': mailbox, 'email-warning': warning })[id],
            querySelectorAll: () => [],
        },
        window: { setTimeout: (callback, delay) => timers.push({ callback, delay }) },
    });

    function input(value) {
        mailbox.value = value;
        mailbox.dispatchEvent(new Event('input'));
        return mailbox.value;
    }
    return { input, classes, timers };
}

for (const value of ['staff@', 'staff@example.org', 'staff@group@example.org', 'staff@bangkok.go.th']) {
    test('normalizes mailbox input ' + value + ' and warns for four seconds', () => {
        const { input, classes, timers } = renderMailbox();
        assert.equal(input('staff'), 'staff');
        assert.equal(classes.has('show'), false);
        assert.equal(timers.length, 0);
        assert.equal(input(value), 'staff');
        assert.equal(classes.has('show'), true);
        assert.equal(timers.length, 1);
        assert.equal(timers[0].delay, 4000);
        assert.equal(input('staff2'), 'staff2');
        assert.equal(classes.has('show'), true);
        timers[0].callback();
        assert.equal(classes.has('show'), false);
    });
}
