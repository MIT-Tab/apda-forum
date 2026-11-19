import { readdir, readFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
async function scan(dir) {
    for (const entry of await readdir(dir, { withFileTypes: true })) {
        if (['.git', 'node_modules'].includes(entry.name)) continue;
        const path = `${dir}/${entry.name}`;
        if (entry.isDirectory()) await scan(path);
        else if (/\.(m?js)$/.test(path)) execFileSync(process.execPath, ['--check', path], { stdio: 'inherit' });
        else if (path.endsWith('.json')) JSON.parse(await readFile(path, 'utf8'));
    }
}
await scan('.');
console.log('JavaScript syntax and JSON checks passed; PHP executes in npm test');
