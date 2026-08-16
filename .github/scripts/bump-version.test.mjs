import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {bumpConfigVersion, nextPatchVersion} from './bump-version.mjs';

test('increments only the patch component', () => {
    assert.equal(nextPatchVersion('0.2.10'), '0.2.11');
    assert.equal(nextPatchVersion('12.0.99'), '12.0.100');
});

test('rejects non-semver plugin versions', () => {
    assert.throws(() => nextPatchVersion('0.2'), /x\.y\.z/);
    assert.throws(() => nextPatchVersion('v0.2.10'), /x\.y\.z/);
});

test('updates config and exposes GitHub Actions outputs', () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'xboard-statistic-version-'));
    const configPath = path.join(directory, 'config.json');
    const outputPath = path.join(directory, 'github-output');

    try {
        fs.writeFileSync(configPath, '{"name":"Xboard Statistics","version":"1.4.9"}\n');
        const result = bumpConfigVersion(configPath, outputPath);

        assert.deepEqual(result, {previousVersion: '1.4.9', version: '1.4.10', tag: 'v1.4.10'});
        assert.equal(JSON.parse(fs.readFileSync(configPath, 'utf8')).version, '1.4.10');
        assert.equal(
            fs.readFileSync(outputPath, 'utf8'),
            'previous_version=1.4.9\nversion=1.4.10\ntag=v1.4.10\n',
        );
    } finally {
        fs.rmSync(directory, {recursive: true, force: true});
    }
});
