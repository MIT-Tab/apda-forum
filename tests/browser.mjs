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
    await expect(area.locator('summary')).toHaveText('+');
    await expect(area.locator('.reaction-vote')).toHaveCount(2);
    await expect(area.locator('.reaction-vote i.fa-thumbs-up')).toHaveCount(1);
    await expect(area.locator('.reaction-vote i.fa-thumbs-down')).toHaveCount(1);
    await expect(area.locator('.reaction-picker button[data-reaction="up"]')).toHaveCount(0);
    expect(await area.locator(':scope > *').evaluateAll(nodes => nodes.slice(0, 3).map(node => node.className))).toEqual([
        expect.stringContaining('reaction-down'), expect.stringContaining('reaction-up'), 'reaction-picker'
    ]);
    const requested = ['😆', '😭', '❤️', '💎', '🗣️', '🗳️', '✅', '❌', '😡', '💀'];
    for (const emoji of requested) {
        await expect(area.locator('.reaction-picker button').filter({ hasText: emoji })).toHaveCount(1);
    }
    while (await area.locator('.reaction-pill[aria-pressed="true"]').count()) {
        await area.locator('.reaction-pill[aria-pressed="true"]').first().click();
        await expect(area).not.toHaveAttribute('aria-busy', 'true');
    }
    async function choose(key) {
        const vote = ['up', 'down'].includes(key);
        if (!vote) await area.locator('summary').click();
        const response = page.waitForResponse(r => r.url().includes(`/reaction/1/${key}`));
        await area.locator(vote ? `.reaction-vote[data-reaction="${key}"]` : `.reaction-picker button[data-reaction="${key}"]`).click();
        const result = await response;
        expect((await result.json()).status).toBe(true);
        await expect(area).not.toHaveAttribute('aria-busy', 'true');
    }
    await choose('skull');
    await expect(area.locator('.reaction-pill[data-reaction="skull"]')).toHaveAttribute('aria-pressed', 'true');
    await area.locator('.reaction-pill[data-reaction="skull"]').click();
    await expect(area.locator('.reaction-pill[data-reaction="skull"]')).toHaveCount(0);
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
    await choose('down');
    await expect(area.locator('.reaction-vote[data-reaction="down"]')).toHaveAttribute('aria-pressed', 'true');
    await choose('down');
    await expect(area.locator('.reaction-vote[data-reaction="down"] .reaction-count')).toHaveText('0');
    await choose('eyes');
    await area.locator('.reaction-pill[data-reaction="eyes"]').click();
    await expect(area).not.toHaveAttribute('aria-busy', 'true');
    const screenshotDir = process.env.FORUM_SCREENSHOT_DIR || 'docs/img/emoji-reactions';
    await mkdir(screenshotDir, { recursive: true });
    await area.locator('summary').click();
    const desktopPicker = await area.locator('.reaction-picker-options').boundingBox();
    const postBounds = await page.locator('#postid-1').boundingBox();
    expect(desktopPicker.y).toBeGreaterThanOrEqual(postBounds.y);
    await page.keyboard.press('Escape');
    await page.locator('#af-wrapper').screenshot({ path: `${screenshotDir}/desktop.png` });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(100);
    await area.locator('summary').click();
    const picker = await area.locator('.reaction-picker-options').boundingBox();
    expect(picker.x).toBeGreaterThanOrEqual(0);
    expect(picker.x + picker.width).toBeLessThanOrEqual(390);
    await page.keyboard.press('Escape');
    await page.locator('#af-wrapper').screenshot({ path: `${screenshotDir}/mobile.png` });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    expect(overflow).toBe(false);
    expect(errors).toEqual([]);
    console.log('Browser checks passed: multi-emoji toggle, keyboard, request failure, guests, desktop/mobile');
} finally { await browser.close(); }
