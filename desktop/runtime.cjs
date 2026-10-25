const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const net = require('node:net');

function isLocalUrl(url, origin) {
    try {
        return new URL(url).origin === origin;
    } catch {
        return false;
    }
}

function uniqueDownloadPath(directory, filename) {
    const safeName = path.basename(filename).replace(/[<>:"/\\|?*\x00-\x1f]/g, '_');
    const parsed = path.parse(safeName || 'converted');
    let candidate = path.join(directory, parsed.base);
    for (let number = 1; fs.existsSync(candidate); number++) {
        candidate = path.join(directory, `${parsed.name} (${number})${parsed.ext}`);
    }
    return candidate;
}

function initializeData(directory) {
    for (const relative of ['logs', 'backups', 'temp', 'storage/app/private', 'storage/app/public',
        'storage/framework/cache/data', 'storage/framework/cache/locks', 'storage/framework/sessions',
        'storage/framework/views', 'storage/logs', 'cache']) {
        fs.mkdirSync(path.join(directory, relative), { recursive: true });
    }
    const keyFile = path.join(directory, 'application-key');
    if (!fs.existsSync(keyFile)) {
        fs.writeFileSync(keyFile, `base64:${crypto.randomBytes(32).toString('base64')}`, { flag: 'wx', mode: 0o600 });
    }
    const key = fs.readFileSync(keyFile, 'utf8').trim();
    if (!/^base64:[A-Za-z0-9+/]{43}=$/.test(key)) {
        throw new Error('The local application key is damaged. Restore your user-data backup; do not delete your database.');
    }
    const database = path.join(directory, 'office-converters.sqlite');
    if (!fs.existsSync(database)) {
        fs.closeSync(fs.openSync(database, 'wx', 0o600));
    }
    return { key, database };
}

function runtimePaths(payload) {
    return {
        app: path.join(payload, 'app'),
        php: path.join(payload, 'php', 'php.exe'),
        ini: path.join(payload, 'php', 'php.ini'),
        python: path.join(payload, 'python', 'python.exe'),
        libreoffice: path.join(payload, 'libreoffice', 'program', 'soffice.com'),
        tesseract: path.join(payload, 'tesseract', 'tesseract.exe'),
        supervisor: path.join(payload, 'supervisor.py'),
        router: path.join(payload, 'router.php'),
    };
}

function createEnvironment(payload, directory, token, port, inherited = process.env) {
    const paths = runtimePaths(payload);
    const { key, database } = initializeData(directory);
    const environment = {};
    for (const name of ['SystemRoot', 'WINDIR', 'COMSPEC', 'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH',
        'LOCALAPPDATA', 'APPDATA', 'PROGRAMDATA', 'ProgramFiles', 'ProgramFiles(x86)', 'NUMBER_OF_PROCESSORS']) {
        if (inherited[name]) environment[name] = inherited[name];
    }
    const temp = path.join(directory, 'temp');
    return {
        ...environment,
        PATH: [path.dirname(paths.php), path.dirname(paths.python),
            path.join(inherited.SystemRoot || 'C:\\Windows', 'System32')].join(path.delimiter),
        TEMP: temp, TMP: temp,
        APP_NAME: 'Office Converters', APP_ENV: 'production', APP_DEBUG: 'false',
        APP_KEY: key, APP_URL: `http://127.0.0.1:${port}`,
        APP_DESKTOP_MODE: 'true', APP_DESKTOP_TOKEN: token,
        DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_FOREIGN_KEYS: 'true',
        DB_QUEUE_CONNECTION: 'sqlite', DB_CACHE_CONNECTION: 'sqlite',
        QUEUE_CONNECTION: 'database', CACHE_STORE: 'file', SESSION_DRIVER: 'file',
        SESSION_COOKIE: 'office-converters-desktop', SESSION_SECURE_COOKIE: 'false',
        MAIL_MAILER: 'log', LOG_CHANNEL: 'single', LOG_LEVEL: 'warning',
        LARAVEL_STORAGE_PATH: path.join(directory, 'storage'),
        APP_CONFIG_CACHE: path.join(directory, 'cache', 'config.php'),
        APP_PACKAGES_CACHE: path.join(directory, 'cache', 'packages.php'),
        APP_SERVICES_CACHE: path.join(directory, 'cache', 'services.php'),
        APP_ROUTES_CACHE: path.join(directory, 'cache', 'routes.php'),
        APP_EVENTS_CACHE: path.join(directory, 'cache', 'events.php'),
        VIEW_COMPILED_PATH: path.join(directory, 'storage', 'framework', 'views'),
        CONVERSION_LOCAL_ENGINES: 'true', CONVERSION_PYTHON: paths.python,
        CONVERSION_LIBREOFFICE: paths.libreoffice, CONVERSION_TESSERACT: paths.tesseract,
        TESSDATA_PREFIX: path.join(payload, 'tesseract', 'tessdata'),
        OMP_THREAD_LIMIT: '1', PYTHONNOUSERSITE: '1',
    };
}

async function availablePort() {
    const server = net.createServer();
    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });
    const port = server.address().port;
    await new Promise((resolve, reject) => server.close(error => error ? reject(error) : resolve()));
    return port;
}

function startManaged(paths, args, environment, log) {
    const child = spawn(paths.python, ['-I', paths.supervisor, paths.php, '-c', paths.ini,
        '-d', `extension_dir=${path.join(path.dirname(paths.php), 'ext')}`,
        '-d', `sys_temp_dir=${environment.TEMP}`, '-d', `upload_tmp_dir=${environment.TEMP}`, ...args], {
        cwd: paths.app, env: environment, windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'],
    });
    child.stdout.on('data', chunk => log.write(chunk));
    child.stderr.on('data', chunk => log.write(chunk));
    return child;
}

function runManaged(paths, args, environment, log, timeout = 120000, onStart = () => {}) {
    const child = startManaged(paths, args, environment, log);
    onStart(child);
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
            child.stdin.end();
            reject(new Error(`Local setup timed out (${args[1] || 'runtime'}). See the application diagnostics.`));
        }, timeout);
        child.once('error', error => { clearTimeout(timer); reject(error); });
        child.once('exit', code => {
            clearTimeout(timer);
            child.stdin.end();
            if (code === 0) resolve();
            else reject(new Error(`Local setup failed (${args[1] || 'runtime'}, exit ${code}). See the application diagnostics.`));
        });
    });
}

module.exports = { availablePort, createEnvironment, initializeData, isLocalUrl, runtimePaths,
    runManaged, startManaged, uniqueDownloadPath };
