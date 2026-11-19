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

const server = await runCLI({
    command: 'server', php: '8.3', wp: '6.8.3', port: Number(process.env.FORUM_PORT || (testing ? 9401 : 9400)),
    'mount-before-install': [{ hostPath: root, vfsPath: '/wordpress/wp-content/plugins/asgaros-forum' }],
    blueprint: { steps },
    login: false, workers: 1
});
try {
    if (testing) {
        const result = await server.playground.run({ code: await readFile(new URL('../tests/integration.php', import.meta.url), 'utf8') });
        const output = new TextDecoder().decode(result.bytes);
        console.log(output);
        if (result.exitCode !== 0 || !output.includes('FORUM_TESTS_PASSED')) throw new Error('WordPress integration checks failed: ' + result.errors);
    } else {
        const result = await server.playground.run({ code: "<?php require '/wordpress/wp-load.php'; echo get_option('asgarosforum_options')['location'];" });
        const page = new TextDecoder().decode(result.bytes).trim();
        console.log(`Local forum: ${server.serverUrl}/?page_id=${page}&view=topic&id=1 (admin / password)`);
    }
} finally {
    if (testing) await server[Symbol.asyncDispose]();
}
