import fs from 'node:fs';
import {pathToFileURL} from 'node:url';

export function nextPatchVersion(version) {
    const match = /^(\d+)\.(\d+)\.(\d+)$/.exec(version);
    if (!match) {
        throw new Error(`config.json version must use x.y.z format, received: ${version}`);
    }

    const [, major, minor, patch] = match;
    return `${major}.${minor}.${Number(patch) + 1}`;
}

export function bumpConfigVersion(configPath, outputPath = '') {
    const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
    const previousVersion = String(config.version ?? '');
    const version = nextPatchVersion(previousVersion);
    config.version = version;
    fs.writeFileSync(configPath, `${JSON.stringify(config, null, 4)}\n`);

    if (outputPath) {
        fs.appendFileSync(outputPath, `previous_version=${previousVersion}\nversion=${version}\ntag=v${version}\n`);
    }

    return {previousVersion, version, tag: `v${version}`};
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const configPath = process.argv[2] ?? 'config.json';
    const result = bumpConfigVersion(configPath, process.env.GITHUB_OUTPUT ?? '');
    process.stdout.write(`${result.previousVersion} -> ${result.version}\n`);
}
