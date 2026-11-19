import { chromium, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
const base = process.env.FORUM_URL;
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
    await page.route('**/*', route => route.request().url().startsWith(base) ? route.continue() : route.abort());
    await page.goto(`${base}/wp-login.php`);
    await page.locator('#user_login').fill('admin');
    await page.locator('#user_pass').fill('password');
    await page.locator('#wp-submit').click();
    await page.waitForURL(/wp-admin/);
    await page.goto(`${base}/?page_id=4&view=profile&id=1`);
    await expect(page.getByRole('link', { name: 'Alex Example (Example University)', exact: true })).toHaveAttribute('href', 'https://results.apda.online/core/debaters/101');
    await expect(page.getByRole('link', { name: 'Alex Example (Second University)', exact: true })).toHaveAttribute('href', 'https://results.apda.online/core/debaters/102');
    const screenshots = process.env.FORUM_PROFILE_SCREENSHOT_DIR;
    if (screenshots) {
        await mkdir(screenshots, { recursive: true });
        await page.screenshot({ path: `${screenshots}/desktop.png`, fullPage: true });
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByRole('link', { name: 'Alex Example (Second University)', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    if (screenshots) await page.screenshot({ path: `${screenshots}/mobile.png`, fullPage: true });
    console.log('Desktop/mobile linked profile browser checks passed');
} finally {
    await browser.close();
}
