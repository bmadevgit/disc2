import assert from 'node:assert/strict';

// The rehearsal supplies an authenticated Playwright page on the development or packaged IIS mount.
export async function checkOfflineCalendar(page, base) {
    const origin = new URL(base).origin;
    const outbound = [];
    const assets = new Set();
    const blockOutbound = route => {
        const url = route.request().url();
        if (new URL(url).origin === origin) return route.continue();
        outbound.push(url);
        return route.abort();
    };
    const recordAsset = response => {
        const url = new URL(response.url());
        if (url.origin === origin && response.status() === 200) assets.add(url.pathname);
    };
    await page.route('**/*', blockOutbound);
    page.on('response', recordAsset);
    try {
        await page.goto(base + '/case.php?id=1&page=2');
        const form = page.locator('form[data-deadline=fact]');
        const field = form.locator('[name=acknowledged_date]');
        const visibleField = form.locator('[name=acknowledged_date] + input');
        await visibleField.click();
        await field.evaluate(input => input._flatpickr.jumpToDate('2024-02-01', true));
        const calendar = page.locator('.flatpickr-calendar.open');
        const buddhistYear = calendar.locator('output[aria-label="ปี พ.ศ."]');
        assert.equal(await buddhistYear.innerText(), '2567');
        await calendar.locator('.numInputWrapper .arrowUp').click();
        assert.equal(await buddhistYear.innerText(), '2568');
        await calendar.locator('.numInputWrapper .arrowDown').click();
        assert.equal(await buddhistYear.innerText(), '2567');
        assert.equal(await calendar.locator('.flatpickr-monthDropdown-months option:checked').textContent(), 'กุมภาพันธ์');
        await calendar.locator('.flatpickr-day:not(.prevMonthDay):not(.nextMonthDay)').filter({hasText: /^29$/}).click();
        assert.equal(await visibleField.inputValue(), '29 กุมภาพันธ์ 2567');
        assert.equal(await field.inputValue(), '2024-02-29');
        assert.equal(await form.evaluate(node => new FormData(node).get('acknowledged_date')), '2024-02-29');
        const mount = new URL(base).pathname.replace(/\/$/, '');
        for (const file of ['flatpickr-4.6.13.min.css','flatpickr-4.6.13.min.js','flatpickr-4.6.13-th.js']) {
            assert.ok(assets.has(mount + '/assets/' + file), file);
        }
        assert.deepEqual(outbound, []);
    } finally {
        page.off('response', recordAsset);
        await page.unroute('**/*', blockOutbound);
    }
}
