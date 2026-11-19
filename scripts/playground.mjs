import { runCLI } from '@wp-playground/cli';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
const root = fileURLToPath(new URL('../', import.meta.url));
const testing = process.argv.includes('--test');
const steps = [
    { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/forum-playground.php', data: '<?php $_SERVER["SERVER_PROTOCOL"] = $_SERVER["SERVER_PROTOCOL"] ?? "HTTP/1.1";' },
    { step: 'activatePlugin', pluginPath: 'asgaros-forum/asgaros-forum.php' },
    { step: 'runPHP', code: await readFile(new URL('seed.php', import.meta.url), 'utf8') }
];

if (!testing && process.env.FORUM_PROFILE_DEMO === '1') {
    steps.push({ step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/results-demo.php', data: await readFile(new URL('../tests/results-demo.php', import.meta.url), 'utf8') });
}

const server = await runCLI({
    command: 'server', php: '8.3', wp: '6.8.3', port: Number(process.env.FORUM_PORT || (testing ? 9401 : 9400)),
    'mount-before-install': [{ hostPath: root, vfsPath: '/wordpress/wp-content/plugins/asgaros-forum' }],
    blueprint: { preferredVersions: { php: '8.3', wp: '6.8.3' }, steps },
    login: false, workers: 1
});
try {
    if (testing) {
        const result = await server.playground.run({ code: await readFile(new URL('../tests/integration.php', import.meta.url), 'utf8') });
        const output = new TextDecoder().decode(result.bytes);
        console.log(output);
        if (result.exitCode !== 0 || !output.includes('FORUM_TESTS_PASSED')) throw new Error('WordPress integration checks failed: ' + result.errors);
        if (process.env.FORUM_PROFILE_BROWSER_TESTS === '1') {
            await server.playground.writeFile('/wordpress/wp-content/mu-plugins/results-demo.php', await readFile(new URL('../tests/results-demo.php', import.meta.url)));
            process.env.FORUM_URL = server.serverUrl;
            await import('../tests/profile-browser.mjs');
        }
        if (process.env.FORUM_BROWSER_TESTS === '1') {
            process.env.FORUM_URL = server.serverUrl;
            await import('../tests/browser.mjs');
        }
    } else {
        const result = await server.playground.run({ code: "<?php require '/wordpress/wp-load.php'; echo get_option('asgarosforum_options')['location'];" });
        const page = new TextDecoder().decode(result.bytes).trim();
        console.log(`Local forum: ${server.serverUrl}/?page_id=${page}&view=topic&id=1 (admin / password)`);
    }
} finally {
    if (testing) await server[Symbol.asyncDispose]();
}
