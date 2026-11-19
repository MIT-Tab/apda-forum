import { chromium } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { dirname } from 'node:path';
const args = process.argv.slice(2);
const mobile = args[0] === '--mobile';
if (mobile) args.shift();
const [url, output] = args;
if (!url || !output) throw new Error('usage: shot [--mobile] URL OUTPUT.png');
await mkdir(dirname(output), { recursive: true });
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: mobile ? { width: 390, height: 844 } : { width: 1280, height: 900 } });
    await page.goto(url, { waitUntil: 'networkidle' });
    await page.screenshot({ path: output, fullPage: true });
    console.log(output);
} finally { await browser.close(); }
