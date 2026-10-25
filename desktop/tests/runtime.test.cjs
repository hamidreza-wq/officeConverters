const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { createEnvironment, initializeData, isLocalUrl, uniqueDownloadPath } = require('../runtime.cjs');

test('only the exact loopback origin is allowed', () => {
    const origin = 'http://127.0.0.1:43123';
    assert.equal(isLocalUrl(`${origin}/dashboard`, origin), true);
    for (const value of ['https://example.com', 'http://127.0.0.1:43124', 'http://localhost:43123',
        'http://127.0.0.1.evil.test:43123', 'file:///C:/Windows/win.ini', 'javascript:alert(1)', 'bad URL']) {
        assert.equal(isLocalUrl(value, origin), false, value);
    }
});

test('first launch preserves the encryption key and existing database', t => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'oc-desktop-unit-'));
    t.after(() => fs.rmSync(directory, { recursive: true }));
    const first = initializeData(directory);
    fs.writeFileSync(first.database, 'existing metadata');
    const second = initializeData(directory);
    assert.equal(second.key, first.key);
    assert.equal(fs.readFileSync(second.database, 'utf8'), 'existing metadata');
    fs.writeFileSync(path.join(directory, 'application-key'), 'broken');
    assert.throws(() => initializeData(directory), /application key is damaged/);
});

test('runtime environment uses only packaged tools and isolated user storage', t => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'oc-desktop-env-'));
    t.after(() => fs.rmSync(directory, { recursive: true }));
    const payload = path.join(directory, 'payload');
    const environment = createEnvironment(payload, directory, 'test-token', 43123, {
        SystemRoot: 'C:\\Windows', PATH: 'C:\\DeveloperTools', APP_KEY: 'developer-secret',
        DB_PASSWORD: 'developer-password', ELECTRON_RUN_AS_NODE: '1', PYTHONPATH: 'C:\\Untrusted',
    });
    assert.equal(environment.DB_CONNECTION, 'sqlite');
    assert.equal(environment.APP_DEBUG, 'false');
    assert.equal(environment.DB_PASSWORD, undefined);
    assert.equal(environment.PYTHONPATH, undefined);
    assert.equal(environment.ELECTRON_RUN_AS_NODE, undefined);
    assert.equal(environment.PATH.includes('DeveloperTools'), false);
    assert.equal(environment.CONVERSION_PYTHON, path.join(payload, 'python', 'python.exe'));
    assert.equal(environment.APP_DESKTOP_TOKEN, 'test-token');
    assert.equal(environment.DB_DATABASE, path.join(directory, 'office-converters.sqlite'));
    assert.equal(environment.APP_PACKAGES_CACHE, path.join(directory, 'cache', 'packages.php'));
    assert.notEqual(environment.APP_KEY, 'developer-secret');
});

test('downloads do not silently reuse an existing filename', t => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'oc-desktop-download-'));
    t.after(() => fs.rmSync(directory, { recursive: true }));
    fs.writeFileSync(path.join(directory, 'result.pdf'), 'original');
    fs.writeFileSync(path.join(directory, 'result (1).pdf'), 'other');
    assert.equal(uniqueDownloadPath(directory, 'result.pdf'), path.join(directory, 'result (2).pdf'));
    assert.equal(path.dirname(uniqueDownloadPath(directory, '..\\unsafe.pdf')), directory);
});
