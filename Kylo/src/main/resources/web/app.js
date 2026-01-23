// Sylo Architect v4.1 (Stateful Patch)

let schema = {};
let builderState = 'MAIN'; // MAIN, TABLE_DEF, SELECT_DEF, USER_DEF
let chain = [];
let tablesList = [];

document.addEventListener('DOMContentLoaded', () => {
    loadSchema();
    initTabs();
    initAutocomplete();
    renderPalette();
});

// --- TABS ---
function initTabs() {
    window.switchTab = (id) => {
        document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
        document.querySelector(`.nav-tab[onclick="switchTab('${id}')"]`).classList.add('active');
        document.querySelectorAll('.workspace').forEach(w => w.classList.remove('active'));
        document.getElementById(`tab-${id}`).classList.add('active');
        if (id === 'visual') drawVisuals();
    }
}

// --- SCHEMA & TREE (phpMyAdmin style) ---
async function loadSchema() {
    const tree = document.getElementById('schema-tree');
    tree.innerHTML = '<div class="loading">Reading Core...</div>';

    try {
        const res = await fetch('/api/schema');
        const data = await res.json();
        schema = data.tables || {};
        tablesList = Object.keys(schema);
        renderTree();
    } catch (e) {
        tree.innerHTML = '<div class="error">OFFLINE</div>';
    }
}

function renderTree() {
    const tree = document.getElementById('schema-tree');
    tree.innerHTML = '';

    // Group by DB (Assuming table format 'db:table')
    const dbMap = {};
    tablesList.forEach(fullName => {
        const [db, tbl] = fullName.includes(':') ? fullName.split(':') : ['Default', fullName];
        if (!dbMap[db]) dbMap[db] = [];
        dbMap[db].push(tbl);
    });

    Object.keys(dbMap).forEach(db => {
        // DB Node
        const dbDiv = document.createElement('div');
        dbDiv.className = 'tree-db';
        dbDiv.innerHTML = `<i class="fas fa-database"></i> ${db}`;
        dbDiv.onclick = () => {
            currentDB = db; // Switch context on click
            log(`Context set to: ${db}`, true);
            const cont = document.getElementById(`grp-${db}`);
            cont.classList.toggle('open');
        };
        tree.appendChild(dbDiv);

        // Table Container
        const container = document.createElement('div');
        container.id = `grp-${db}`;
        container.className = 'tree-tbl-container';

        dbMap[db].forEach(tbl => {
            const tDiv = document.createElement('div');
            tDiv.className = 'tree-tbl';
            tDiv.innerHTML = `<i class="fas fa-table"></i> ${tbl}`;
            tDiv.onclick = () => {
                const fullName = `${db}:${tbl}`;
                // Auto-select in Builder if active
                if (chain.length > 0 && (chain[0].val === 'INSERT INTO' || chain[0].val === 'SELECT')) {
                    chain[0].extra = fullName;
                    renderChain();
                }

                // Populate SELECT *
                document.getElementById('sql-input').value = `SELECT * FROM ${db}:${tbl};`;
                executeQuery();
            };
            container.appendChild(tDiv);
        });
        tree.appendChild(container);
    });
}

// --- MODAL: NEW DB ---
window.promptNewDB = () => { document.getElementById('modal-overlay').style.display = 'flex'; }
window.closeModal = () => { document.getElementById('modal-overlay').style.display = 'none'; }
window.confirmModal = async () => {
    const name = document.getElementById('modal-input').value;
    if (name) {
        await execSilent(`CREATE DATABASE ${name}`);
        loadSchema();
        closeModal();
    }
}

// --- CONTEXT AWARE BUILDER ---
const BLOCKS = {
    MAIN: [
        { l: 'CREATE TABLE', c: 'cat-main', act: () => setBuilderState('TABLE_DEF', 'CREATE TABLE') },
        { l: 'SELECT', c: 'cat-main', act: () => setBuilderState('SELECT_DEF', 'SELECT') },
        { l: 'INSERT', c: 'cat-main', act: () => setBuilderState('INSERT_DEF', 'INSERT INTO') },
        { l: 'USER MGMT', c: 'cat-user', act: () => setBuilderState('USER_DEF', '') }
    ],
    TABLE_DEF: [
        { l: 'INT', c: 'cat-type', act: () => addChainItem('col', 'INT') },
        { l: 'VARCHAR', c: 'cat-type', act: () => addChainItem('col', 'VARCHAR(255)') },
        { l: 'BOOLEAN', c: 'cat-type', act: () => addChainItem('col', 'BOOLEAN') },
        { l: 'DOUBLE', c: 'cat-type', act: () => addChainItem('col', 'DOUBLE') },
        { l: 'DATE', c: 'cat-type', act: () => addChainItem('col', 'DATE') },
        { l: 'PRIMARY KEY', c: 'cat-cond', act: () => addChainItem('pk', 'PRIMARY KEY') },
        { l: 'FOREIGN KEY', c: 'cat-cond', act: () => addChainItem('fk', 'FK') }
    ],
    INSERT_DEF: [
        { l: '+ FIELD=VAL', c: 'cat-type', act: () => addChainItem('set', '=') }
    ],
    SELECT_DEF: [
        { l: 'ALL (*)', c: 'cat-type', act: () => addChainItem('field', '*') },
        { l: 'WHERE', c: 'cat-cond', act: () => addChainItem('clause', 'WHERE') },
        { l: 'ORDER BY', c: 'cat-cond', act: () => addChainItem('clause', 'ORDER BY') },
        { l: 'LIMIT', c: 'cat-cond', act: () => addChainItem('clause', 'LIMIT') }
    ],
    USER_DEF: [
        { l: 'CREATE USER', c: 'cat-user', act: () => addChainItem('cmd', 'CREATE USER') },
        { l: 'DROP USER', c: 'cat-user', act: () => addChainItem('cmd', 'DROP USER') },
        { l: 'GRANT ALL', c: 'cat-user', act: () => addChainItem('cmd', 'GRANT ALL ON') }
    ]
};

function setBuilderState(state, initVal) {
    builderState = state;
    if (initVal) {
        chain = [{ type: 'head', val: initVal, extra: '' }]; // Reset chain
    } else {
        chain = [];
    }
    renderPalette();
    renderChain();
}

window.resetBuilder = () => { setBuilderState('MAIN'); }

function renderPalette() {
    const p = document.getElementById('scratch-palette');
    p.innerHTML = '';
    const items = BLOCKS[builderState] || [];
    items.forEach(b => {
        const div = document.createElement('div');
        div.className = `p-block ${b.c}`;
        div.innerText = b.l;
        div.onclick = b.act;
        p.appendChild(div);
    });
}

function addChainItem(type, label) {
    chain.push({ type, val: label, userVal: '' });
    renderChain();
}

function renderChain() {
    const c = document.getElementById('scratch-chain');
    if (chain.length === 0) {
        c.innerHTML = '<div class="empty-chain">Select a command...</div>';
        return;
    }
    c.innerHTML = '';

    chain.forEach((item, idx) => {
        const div = document.createElement('div');
        div.className = `c-block ${getItemColor(item.type)}`;

        let html = `<b>${item.val}</b>`;

        // Inputs based on type logic
        if (item.type === 'head' && item.val.includes('CREATE')) {
            html += `<input placeholder="Table Name" value="${item.extra || ''}" oninput="updateChain(${idx}, 'extra', this.value)">`;
        } else if (item.type === 'head' && item.val === 'INSERT INTO') {
            // Dropdown for tables
            html += `<select onchange="updateChain(${idx}, 'extra', this.value)" style="color:black">`;
            html += `<option value="">Select Table...</option>`;
            tablesList.forEach(t => {
                const sel = (item.extra === t) ? 'selected' : '';
                html += `<option value="${t}" ${sel}>${t}</option>`;
            });
            html += `</select>`;
        } else if (item.type === 'head' && item.val === 'SELECT') {
            // Dropdown for tables (FROM)
            html += `<select onchange="updateChain(${idx}, 'extra', this.value)" style="color:black">`;
            html += `<option value="">Select Table...</option>`;
            tablesList.forEach(t => {
                const sel = (item.extra === t) ? 'selected' : '';
                html += `<option value="${t}" ${sel}>${t}</option>`;
            });
            html += `</select>`;
        } else if (item.type === 'col') {
            html += `<input placeholder="Name" value="${item.userVal}" oninput="updateChain(${idx}, 'userVal', this.value)">`;
        } else if (item.type === 'clause') {
            html += `<input placeholder="Condition" value="${item.userVal}" oninput="updateChain(${idx}, 'userVal', this.value)">`;
        } else if (item.type === 'set') {
            html += `<input placeholder="Col" value="${item.userVal}" oninput="updateChain(${idx}, 'userVal', this.value)" style="width:80px"> = <input placeholder="Val" value="${item.extra || ''}" oninput="updateChain(${idx}, 'extra', this.value)" style="width:80px">`;
        } else if (item.type === 'cmd' && item.val.includes('USER')) {
            html += `<input placeholder="'user'@'host'" value="${item.userVal}" oninput="updateChain(${idx}, 'userVal', this.value)">`;
            if (item.val.includes('CREATE')) html += ` PASS <input placeholder="password" value="${item.extra || ''}" oninput="updateChain(${idx}, 'extra', this.value)">`;
        }

        // Delete button (except head)
        if (idx > 0) {
            html += `<button onclick="removeFromChain(${idx})">✖</button>`;
        }

        div.innerHTML = html;
        c.appendChild(div);
    });
}

window.updateChain = (idx, field, val) => { chain[idx][field] = val; }
window.removeFromChain = (idx) => { chain.splice(idx, 1); renderChain(); }

function getItemColor(t) {
    if (t === 'head' || t === 'cmd') return 'cat-main';
    if (t === 'col' || t === 'field') return 'cat-type';
    return 'cat-cond';
}

window.generateSQL = () => {
    if (!chain.length) return;
    let sql = "";

    const head = chain[0];

    if (head.val === 'CREATE TABLE') {
        const name = head.extra || 'MyTable';
        const cols = chain.slice(1).map(i => `${i.userVal} ${i.val}`).join(', ');
        sql = `CREATE TABLE ${name} ( ${cols} );`;
    } else if (head.val === 'INSERT INTO') {
        const table = head.extra || 'MyTable';
        const sets = chain.filter(i => i.type === 'set');
        if (sets.length > 0) {
            const cols = sets.map(i => i.userVal).join(', ');
            const vals = sets.map(i => `'${i.extra}'`).join(', ');
            sql = `INSERT INTO ${table} (${cols}) VALUES (${vals});`;
        } else {
            sql = `INSERT INTO ${table} VALUES ();`;
        }
    } else if (head.val === 'SELECT') {
        const from = head.extra || 'MyTable';
        if (!from) return;
        const what = chain.filter(i => i.type === 'field').map(i => i.val).join(', ') || '*';
        const where = chain.find(i => i.val === 'WHERE');
        const order = chain.find(i => i.val === 'ORDER BY');
        const limit = chain.find(i => i.val === 'LIMIT');

        sql = `SELECT ${what} FROM ${from}`;
        if (where) sql += ` WHERE ${where.userVal}`;
        if (order) sql += ` ORDER BY ${order.userVal}`;
        if (limit) sql += ` LIMIT ${limit.userVal}`;
        sql += ";";
    } else if (head.val === 'CREATE USER') {
        sql = `CREATE USER ${head.userVal} IDENTIFIED BY '${head.extra}';`;
    }

    document.getElementById('sql-input').value = sql;
    log(`Generated: ${sql}`, true);
}


// --- EXECUTION & LOGGING ---
let currentDB = 'Default';

// ... (existing code)

// --- EXECUTION & LOGGING ---
async function executeQuery() {
    let sql = document.getElementById('sql-input').value.trim();
    if (!sql) return;

    // Client-side session tracking (Mocking stateful connection)
    if (sql.toUpperCase().startsWith("USE ")) {
        const parts = sql.split(/\s+/);
        if (parts.length > 1) {
            currentDB = parts[1].replace(";", "").trim();
            log(`Switched to DB: ${currentDB}`, true);
        }
    }

    // Prepend USE <DB> if not switching context
    let finalSql = sql;
    if (!sql.toUpperCase().startsWith("USE ") && !sql.toUpperCase().startsWith("CREATE DATABASE")) {
        finalSql = `USE ${currentDB};\n${sql}`;
    }

    log(`> ${sql}`, true);
    document.querySelector('#results-table tbody').innerHTML = '<tr><td>Executing...</td></tr>';

    try {
        const res = await fetch('/api/query', { method: 'POST', body: finalSql });
        const json = await res.json();

        if (json.status === 'success') {
            log(`OK: ${json.message}`, true);
            renderResults(json);
            if (sql.match(/(CREATE|DROP|ALTER)/i)) {
                await loadSchema(); // Refresh tree
                // Re-open current DB in tree
                setTimeout(() => {
                    const cont = document.getElementById(`grp-${currentDB}`);
                    if (cont) cont.classList.add('open');
                }, 500);
            }
        } else {
            log(`ERR: ${json.message}`, false);
            document.querySelector('#results-table tbody').innerHTML = `<tr><td style="color:#ff5555">${json.message}</td></tr>`;
        }
    } catch (e) { log(`NET ERR: ${e.message}`, false); }
}

async function execSilent(sql) {
    // Also prepend DB for silent ops
    const finalSql = `USE ${currentDB};\n${sql}`;
    await fetch('/api/query', { method: 'POST', body: finalSql });
}

function renderResults(json) {
    const t = document.getElementById('results-table');
    const tbody = t.querySelector('tbody');
    const thead = t.querySelector('thead tr');

    if (json.columns) {
        thead.innerHTML = '<th>#</th>' + json.columns.map(c => `<th>${c}</th>`).join('');
    }
    tbody.innerHTML = '';
    if (!json.rows || !json.rows.length) { tbody.innerHTML = '<tr><td>No Data</td></tr>'; return; }

    json.rows.forEach((r, i) => {
        let h = `<td>${i + 1}</td>`;
        r.forEach(c => h += `<td>${c === null ? 'NULL' : c}</td>`);
        const tr = document.createElement('tr');
        tr.innerHTML = h;
        tbody.appendChild(tr);
    });
}

function log(msg, ok) {
    const c = document.getElementById('console-log');
    c.innerHTML += `<div class="log-line ${ok ? 's' : 'e'}">[${new Date().toLocaleTimeString()}] ${msg}</div>`;
    c.scrollTop = c.scrollHeight;
}

// --- AUTOCOMPLETE (PRESERVED) ---
function initAutocomplete() {
    // ... exact previous logic ...
    const input = document.getElementById('sql-input');
    const box = document.getElementById('sug-box');
    input.addEventListener('input', function () {
        const val = this.value;
        const last = val.split(/\s+/).pop();
        if (val.length > 2 && tablesList.some(t => t.includes(last))) {
            const m = tablesList.filter(t => t.includes(last));
            if (m.length) {
                box.innerHTML = m.map(t => `<div onclick="insSug('${t}')">${t}</div>`).join('');
                box.style.display = 'block';
                return;
            }
        }
        box.style.display = 'none';
    });
    window.insSug = (t) => {
        input.value = input.value.replace(/\S+$/, t);
        box.style.display = 'none';
        input.focus();
    }
}

// --- VISUAL MAP (Placeholder) ---
function drawVisuals() {
    const c = document.getElementById('visual-canvas');
    if (!c) return;
    const ctx = c.getContext('2d');
    c.width = c.parentElement.offsetWidth;
    c.height = c.parentElement.offsetHeight;
    ctx.clearRect(0, 0, c.width, c.height);

    // Simple Network
    ctx.strokeStyle = '#00ff88';
    ctx.fillStyle = '#fff';
    tablesList.forEach((t, i) => {
        const x = 100 + (i * 150), y = 100 + (i % 2 * 50);
        ctx.strokeRect(x, y, 120, 60);
        ctx.fillText(t, x + 10, y + 30);
    });
}
