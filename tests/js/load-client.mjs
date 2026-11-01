// public/rosanna.js はブラウザ用の普通のスクリプトなので、document のない文脈で評価して
// 純粋な部分だけを受け取る。package.json の "type" に左右されないように vm で読む。
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

export const scriptPath = new URL('../../public/rosanna.js', import.meta.url);

export function loadClient() {
    const module = { exports: {} };
    const source = readFileSync(scriptPath, 'utf8');
    vm.runInThisContext('(function (module) {' + source + '\n})', { filename: scriptPath.pathname })(module);
    return module.exports;
}

export function memoryStorage(initial = {}) {
    const entries = new Map(Object.entries(initial));
    return {
        get length() {
            return entries.size;
        },
        key: (index) => [...entries.keys()][index] ?? null,
        getItem: (key) => (entries.has(key) ? entries.get(key) : null),
        setItem: (key, value) => {
            entries.set(key, String(value));
        },
        removeItem: (key) => {
            entries.delete(key);
        },
        keys: () => [...entries.keys()],
    };
}
