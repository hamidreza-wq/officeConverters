const { app, BrowserWindow, Menu, dialog, session, shell } = require('electron');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { availablePort, createEnvironment, isLocalUrl, runtimePaths, runManaged,
    startManaged, uniqueDownloadPath } = require('./runtime.cjs');

app.setName('Office Converters');
app.setPath('userData', path.join(app.getPath('appData'), 'OfficeConverters'));
const dataDirectory = app.getPath('userData');
const services = [];
let window;
let origin;
let log;
let shuttingDown = false;
let outputDirectory;
let stopPromise;

if (!app.requestSingleInstanceLock()) {
    app.quit();
} else {
    app.on('second-instance', () => {
        if (window) {
            if (window.isMinimized()) window.restore();
            window.show();
            window.focus();
        }
    });
    app.whenReady().then(start).catch(fail);
}

async function fail(error) {
    if (shuttingDown) return;
    log?.write(`${new Date().toISOString()} ${error.stack || error}\n`);
    dialog.showErrorBox('Office Converters could not continue',
        `${error.message}\n\nDiagnostics: ${path.join(dataDirectory, 'logs')}\nYour documents and history have not been deleted.`);
    app.quit();
}

async function stopServices() {
    shuttingDown = true;
    await Promise.all(services.map(child => new Promise(resolve => {
        if (child.exitCode !== null || child.signalCode !== null) return resolve();
        const timer = setTimeout(resolve, 5000);
        child.once('exit', () => { clearTimeout(timer); resolve(); });
        child.stdin.end();
    })));
    log?.end();
}

app.on('before-quit', event => {
    if (stopPromise) return;
    event.preventDefault();
    stopPromise = stopServices().finally(() => app.quit());
});
app.on('window-all-closed', () => app.quit());

function watchService(child, name) {
    services.push(child);
    child.once('error', error => fail(new Error(`${name} could not start: ${error.message}`)));
    child.once('exit', code => {
        if (!shuttingDown) fail(new Error(`${name} stopped unexpectedly (exit ${code}). Restart the application. Interrupted conversions can be retried.`));
    });
}

function saveSettings() {
    fs.writeFileSync(path.join(dataDirectory, 'settings.json'), JSON.stringify({ outputDirectory }, null, 2));
}

async function chooseOutputDirectory() {
    const result = await dialog.showOpenDialog(window, {
        title: 'Choose default output folder', defaultPath: outputDirectory,
        properties: ['openDirectory', 'createDirectory'],
    });
    if (!result.canceled) {
        outputDirectory = result.filePaths[0];
        saveSettings();
    }
}

function configureMenu() {
    Menu.setApplicationMenu(Menu.buildFromTemplate([
        { label: 'File', submenu: [
            { label: 'Workspace', accelerator: 'CmdOrCtrl+H', click: () => window.loadURL(`${origin}/dashboard`).catch(fail) },
            { label: 'Choose output folder...', click: () => chooseOutputDirectory().catch(fail) },
            { label: 'Open output folder', click: () => shell.openPath(outputDirectory).then(error => {
                if (error) dialog.showErrorBox('Could not open output folder', error);
            }).catch(fail) },
            { type: 'separator' }, { role: 'quit' },
        ] },
        { label: 'Edit', submenu: [{ role: 'undo' }, { role: 'redo' }, { type: 'separator' },
            { role: 'cut' }, { role: 'copy' }, { role: 'paste' }, { role: 'selectAll' }] },
        { label: 'View', submenu: [{ role: 'resetZoom' }, { role: 'zoomIn' }, { role: 'zoomOut' }, { role: 'togglefullscreen' }] },
        { label: 'Help', submenu: [
            { label: 'Conversion formats and limitations', click: () => window.loadURL(`${origin}/tools`).catch(fail) },
            { label: 'Open diagnostic logs', click: () => shell.openPath(path.join(dataDirectory, 'logs')).then(error => {
                if (error) dialog.showErrorBox('Could not open diagnostic logs', error);
            }).catch(fail) },
            { label: 'About Office Converters', click: () => dialog.showMessageBox(window, {
                type: 'info', title: 'Office Converters', message: `Office Converters ${app.getVersion()}`,
                detail: `Copyright (c) 2026 ${require('../package.json').author}\n\nOffline, single-user document conversion.\nPDF-to-Word and OCR are best-effort. Review all results.\nUpdates: install a newer trusted installer over this version. User data is retained.`,
            }) },
        ] },
    ]));
}

async function waitForBackend(token, deadline = Date.now() + 90000) {
    while (Date.now() < deadline && !shuttingDown) {
        try {
            const response = await fetch(`${origin}/desktop/ready`, {
                headers: { 'X-OfficeConverters-Desktop': token }, signal: AbortSignal.timeout(30000),
            });
            if (response.ok) {
                const result = await response.json();
                if (result.launch !== crypto.createHash('sha256').update(token).digest('hex')) {
                    throw new Error('The local application origin did not match this launch.');
                }
                if (!result.ready) throw new Error('Bundled conversion engines failed their startup check. Repair the installation.');
                return;
            }
            if (response.status !== 503) throw new Error(`Local application returned HTTP ${response.status}.`);
        } catch (error) {
            if (error.cause?.code !== 'ECONNREFUSED' && error.name !== 'TimeoutError') throw error;
        }
        await new Promise(resolve => setTimeout(resolve, 300));
    }
    throw new Error('The local application did not become ready. Restart or reinstall the application; user data will be preserved.');
}

async function start() {
    const payload = path.join(process.resourcesPath, 'payload');
    const paths = runtimePaths(payload);
    for (const [name, filename] of Object.entries(paths)) {
        if (!fs.existsSync(filename)) throw new Error(`The installed ${name} component is missing. Please repair the installation.`);
    }
    const port = await availablePort();
    origin = `http://127.0.0.1:${port}`;
    const token = crypto.randomBytes(32).toString('hex');
    const environment = createEnvironment(payload, dataDirectory, token, port);
    const logPath = path.join(dataDirectory, 'logs', 'desktop.log');
    if (fs.existsSync(logPath) && fs.statSync(logPath).size > 2 * 1024 * 1024) {
        if (fs.existsSync(`${logPath}.previous`)) fs.unlinkSync(`${logPath}.previous`);
        fs.renameSync(logPath, `${logPath}.previous`);
    }
    log = fs.createWriteStream(logPath, { flags: 'a' });
    outputDirectory = path.join(app.getPath('documents'), 'Office Converters');
    const settingsFile = path.join(dataDirectory, 'settings.json');
    if (fs.existsSync(settingsFile)) {
        const settings = JSON.parse(fs.readFileSync(settingsFile, 'utf8'));
        if (typeof settings.outputDirectory !== 'string' || !path.isAbsolute(settings.outputDirectory)) {
            throw new Error('The output folder setting is invalid. Correct settings.json in your user-data directory.');
        }
        outputDirectory = settings.outputDirectory;
    }
    fs.mkdirSync(outputDirectory, { recursive: true });
    saveSettings();

    const desktopSession = session.fromPartition('office-converters');
    const loadingUrl = 'data:text/html;charset=utf-8,' + encodeURIComponent(
        '<!doctype html><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'">' +
        '<title>Office Converters</title><style>body{font:20px Segoe UI,sans-serif;background:#f7f7fc;color:#242138;padding:80px}h1{color:#6854db}p{font-size:16px}</style>' +
        '<h1>Office Converters</h1><p>Preparing your private workspace and checking local conversion engines...</p><p>First launch may take a moment. No account or internet connection is needed.</p>');
    desktopSession.setPermissionRequestHandler((_contents, _permission, callback) => callback(false));
    desktopSession.setPermissionCheckHandler(() => false);
    desktopSession.webRequest.onBeforeRequest((details, callback) => {
        callback({ cancel: details.url !== loadingUrl && !isLocalUrl(details.url, origin) });
    });
    desktopSession.webRequest.onBeforeSendHeaders((details, callback) => {
        if (isLocalUrl(details.url, origin)) details.requestHeaders['X-OfficeConverters-Desktop'] = token;
        callback({ requestHeaders: details.requestHeaders });
    });
    desktopSession.on('will-download', (_event, item) => {
        item.setSaveDialogOptions({
            title: 'Save converted document',
            defaultPath: uniqueDownloadPath(outputDirectory, item.getFilename()),
            properties: ['showOverwriteConfirmation', 'createDirectory'],
        });
        item.once('done', (_doneEvent, state) => {
            if (state === 'interrupted') dialog.showErrorBox('Download failed', 'The converted document could not be saved. Check the destination and try again.');
        });
    });
    window = new BrowserWindow({
        width: 1280, height: 900, minWidth: 900, minHeight: 650,
        title: 'Office Converters', backgroundColor: '#f7f7fc',
        webPreferences: {
            session: desktopSession, nodeIntegration: false, contextIsolation: true,
            sandbox: true, webSecurity: true, allowRunningInsecureContent: false,
            devTools: !app.isPackaged,
        },
    });
    window.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
    window.webContents.on('will-navigate', (event, url) => {
        if (!isLocalUrl(url, origin)) event.preventDefault();
    });
    window.webContents.on('will-attach-webview', event => event.preventDefault());
    window.webContents.on('render-process-gone', (_event, details) => {
        if (!shuttingDown) fail(new Error(`The application window stopped (${details.reason}). Restart Office Converters. Your saved documents are preserved.`));
    });
    await window.loadURL(loadingUrl);
    const setup = args => runManaged(paths, args, environment, log, 120000, child => services.push(child));
    await setup(['artisan', 'package:discover', '--no-interaction']);
    if (shuttingDown) return;
    await setup(['artisan', 'converters:desktop-prepare', '--no-interaction']);
    if (shuttingDown) return;
    const backend = startManaged(paths, ['-S', `127.0.0.1:${port}`, '-t', path.join(paths.app, 'public'), paths.router], environment, log);
    watchService(backend, 'Local application service');
    const worker = startManaged(paths, ['artisan', 'queue:work', 'database', '--sleep=1', '--tries=3', '--timeout=150', '--memory=256', '--no-interaction'], environment, log);
    watchService(worker, 'Conversion worker');
    const scheduler = startManaged(paths, ['artisan', 'schedule:work', '--no-interaction'], environment, log);
    watchService(scheduler, 'Maintenance scheduler');
    await waitForBackend(token);
    if (shuttingDown) return;
    configureMenu();
    await window.loadURL(`${origin}/dashboard`);
    log.write(`${new Date().toISOString()} Desktop workspace ready; version ${app.getVersion()}.\n`);
}
