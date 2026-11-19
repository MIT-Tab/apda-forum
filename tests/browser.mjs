import { chromium, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
const base = process.env.FORUM_URL || 'http://127.0.0.1:9400';
const topic = `${base}/?page_id=4&view=topic&id=1`;
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
    await page.route('**/*', route => route.request().url().startsWith(base) ? route.continue() : route.abort());
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(topic, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.reaction-picker')).toHaveCount(0);
    await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await page.locator('#user_login').fill('admin');
    await page.locator('#user_pass').fill('password');
    await page.locator('#wp-submit').click();
    await page.waitForURL(/wp-admin/, { waitUntil: 'domcontentloaded' });
    await page.goto(topic, { waitUntil: 'domcontentloaded' });
    const area = page.locator('.post-reactions').first();
    while (await area.locator('.reaction-pill[aria-pressed="true"]').count()) {
        await area.locator('.reaction-pill[aria-pressed="true"]').first().click();
        await expect(area).not.toHaveAttribute('aria-busy', 'true');
    }
    async function choose(key) {
        await area.locator('summary').click();
        const response = page.waitForResponse(r => r.url().includes(`/reaction/1/${key}`));
        await area.locator(`.reaction-picker button[data-reaction="${key}"]`).click();
        const result = await response;
        expect((await result.json()).status).toBe(true);
        await expect(area).not.toHaveAttribute('aria-busy', 'true');
    }
    await choose('up');
    await choose('heart');
    await expect(area.locator('.reaction-pill[aria-pressed="true"]')).toHaveCount(2);
    await area.locator('.reaction-pill[data-reaction="heart"]').click();
    await expect(area.locator('.reaction-pill[data-reaction="heart"][aria-pressed="true"]')).toHaveCount(0);
    await expect(area.locator('.reaction-pill[data-reaction="up"]')).toHaveCount(1);
    await choose('heart');
    await choose('laugh');
    // Picker Escape handling and focus return.
    await area.locator('summary').click();
    await area.locator('.reaction-picker button').first().focus();
    await page.keyboard.press('Escape');
    await expect(area.locator('.reaction-picker')).not.toHaveAttribute('open');
    await expect(area.locator('summary')).toBeFocused();
    // Failed requests retain counts and re-enable controls.
    await page.route('**/asgaros-forum/v1/reaction/**', route => route.abort());
    await area.locator('.reaction-pill[data-reaction="up"]').click();
    await expect(area.locator('.reaction-feedback')).toContainText('Could not update');
    await expect(area.locator('.reaction-pill[data-reaction="up"]')).toBeEnabled();
    await page.unroute('**/asgaros-forum/v1/reaction/**');
    await choose('eyes');
    await area.locator('.reaction-pill[data-reaction="eyes"]').click();
    await expect(area).not.toHaveAttribute('aria-busy', 'true');
    await mkdir('docs/img/emoji-reactions', { recursive: true });
    await area.locator('summary').click();
    await page.locator('#af-wrapper').screenshot({ path: 'docs/img/emoji-reactions/desktop.png' });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(100);
    const picker = await area.locator('.reaction-picker-options').boundingBox();
    expect(picker.x).toBeGreaterThanOrEqual(0);
    expect(picker.x + picker.width).toBeLessThanOrEqual(390);
    await page.locator('#af-wrapper').screenshot({ path: 'docs/img/emoji-reactions/mobile.png' });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    expect(overflow).toBe(false);
    expect(errors).toEqual([]);
    console.log('Browser checks passed: multi-emoji toggle, keyboard, request failure, guests, desktop/mobile');
} finally { await browser.close(); }
