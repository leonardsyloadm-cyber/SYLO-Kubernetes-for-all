/**
 * KyloDB Visual Constructor (Capacity Planner)
 * Logic for estimating database size based on schema design.
 */

const CONSTANTS = {
    PAGE_SIZE: 4096,      // 4KB Page
    PAGE_HEADER: 64,      // Header overhead
    FILL_FACTOR: 0.8,     // 80% fill factor
    ROW_OVERHEAD: 7,      // Header + Slot Directory
    RID_SIZE: 6,          // Record ID pointer
    LOG_OVERHEAD: 256 * 1024 * 1024 // 256MB WAL Base
};

// State
let constructorTables = []; // Each: { id, name, rows, columns, x: 0, y: 0 }
let visualIndices = [];     // Each: { id, tableId, colIndices: [0, 1], x: 0, y: 0, color: '#e67e22' }
let chartInstance = null;

// --- CORE MATH ---

function calculateTableWeight(rows, columns) {
    // 1. Calculate Row Width (Rho)
    // Null Map = ceil(columns / 8)
    let nullMap = Math.ceil(columns.length / 8);
    let dataWidth = 0;

    columns.forEach(col => {
        if (col.type === 'INT') dataWidth += 4;
        else if (col.type === 'BIGINT' || col.type === 'DATETIME') dataWidth += 8;
        else if (col.type === 'BOOL') dataWidth += 1;
        else if (col.type === 'UUID') dataWidth += 16;
        else if (col.type === 'TIMESTAMP' || col.type === 'DATE' || col.type === 'TIME') dataWidth += 8;
        else if (col.type === 'VARCHAR' || col.type === 'TEXT') {
            // Var length overhead (2 bytes) + avg length
            let avgLen = (col.maxLength || 100) * ((col.fillFactor || 50) / 100);
            dataWidth += 2 + avgLen;
        }
    });

    let rho = nullMap + dataWidth + CONSTANTS.ROW_OVERHEAD;

    // 2. Data Pages
    const numRows = parseInt(rows) || 0;
    let usablePageSpace = (CONSTANTS.PAGE_SIZE - CONSTANTS.PAGE_HEADER) * CONSTANTS.FILL_FACTOR;
    let rowsPerPage = Math.floor(usablePageSpace / rho);
    if (rowsPerPage < 1) rowsPerPage = 1;
    let totalDataPages = Math.ceil(numRows / rowsPerPage);

    // 3. Index Pages
    // Rules: Unique Key OR Foreign Key OR Primary Key -> Creates an Index
    // (Manual IDX checkbox removed)
    let totalIndexPages = 0;
    columns.forEach(col => {
        // Effective Index: Unique OR Foreign Key OR Primary Key
        if (col.isUnique || col.isFk || col.isPk) {
            let keySize = (col.type === 'VARCHAR' || col.type === 'TEXT') ? 20 : 4;
            let entrySize = keySize + CONSTANTS.RID_SIZE + 4;
            let keysPerPage = Math.floor(usablePageSpace / entrySize);
            totalIndexPages += Math.ceil(numRows / keysPerPage);
        }
    });

    // Add Visual Indices overhead
    // Find visual indices for this table (passed or context)
    // Note: This function is pure math, it doesn't access 'visualIndices' global directly usually, 
    // but for simplicity we will calculate visual indices overhead separately in recalcAll() or pass it in.
    // For now, let's keep this clean and process visual indices in the main loop.

    return {
        dataBytes: totalDataPages * CONSTANTS.PAGE_SIZE,
        indexBytes: totalIndexPages * CONSTANTS.PAGE_SIZE,
        totalBytes: (totalDataPages + totalIndexPages) * CONSTANTS.PAGE_SIZE
    };
}

// --- UI MANAGMENT ---

function initConstructor() {
    // Initialize chart if needed
    const ctx = document.getElementById('cap-output-chart');
    if (ctx && !chartInstance && window.Chart) {
        chartInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Datos', 'Índices', 'Sistema (Logs)'],
                datasets: [{
                    data: [0, 0, CONSTANTS.LOG_OVERHEAD],
                    backgroundColor: ['#3498db', '#e67e22', '#95a5a6'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false // Hide default legend, using custom HTML one
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return ' ' + context.formattedValue + ' bytes';
                            }
                        }
                    }
                }
            }
        });
    }
    // Update Simulator dropdowns if they exist
    updateSimulatorTableSelect();

    // Canvas Listeners for Drag and Drop
    const canvas = document.getElementById('constructor-canvas');
    if (canvas) {
        canvas.addEventListener('mousemove', onCanvasMouseMove);
        canvas.addEventListener('mouseup', onCanvasMouseUp);
        canvas.addEventListener('mouseleave', onCanvasMouseUp);
    }
}

function addNewTableCard() {
    const id = Date.now();
    // Random position or cascaded
    const x = 50 + (constructorTables.length * 30);
    const y = 50 + (constructorTables.length * 30);

    const tableData = {
        id: id,
        name: 'Nueva Tabla ' + (constructorTables.length + 1),
        rows: 1000,
        x: x,
        y: y,
        columns: [
            { id: id + '_c1', name: 'col_1', type: 'INT', maxLength: 0, fillFactor: 0, hasIndex: false, isUnique: true, isFk: false, isPk: true } // PK default
        ]
    };
    constructorTables.push(tableData);
    renderTableCard(tableData);
    recalcAll();
}

function renderTableCard(data) {
    const container = document.getElementById('constructor-canvas');
    let div = document.getElementById('tc-' + data.id);

    // If it doesn't exist, create it
    if (!div) {
        div = document.createElement('div');
        div.className = 'table-card';
        div.id = 'tc-' + data.id;

        const fab = container.querySelector('.btn-fab');
        // If wrapper exists, insert before wrapper, else append
        const fabWrapper = container.querySelector('.fab-wrapper');
        if (fabWrapper) container.insertBefore(div, fabWrapper);
        else container.appendChild(div);

        makeDraggable(div);
    }

    // Update position
    div.style.left = (data.x || 0) + 'px';
    div.style.top = (data.y || 0) + 'px';

    div.innerHTML = `
        <div class="tc-header">
            <input type="text" class="tc-title-input" value="${data.name}" title="Editar Nombre" oninput="updateTableName(${data.id}, this.value)">
            <span class="tc-badge" id="badge-${data.id}">0 MB</span>
            <button class="btn-delete-card" onclick="removeTable(${data.id})"><i class="fas fa-trash"></i></button>
        </div>
        <div class="tc-body">
            <div class="control-group">
                <label style="color:#34495e; font-weight:600; margin-bottom:5px">Clientes Deseados (Filas)</label>
                <div style="display:flex; gap:10px; align-items:center">
                    <input type="number" class="kylo-input" value="${data.rows}" min="0" oninput="updateTableRows(${data.id}, this.value)" style="width:100%; font-weight:bold; font-size:1.1em; color:#2c3e50">
                </div>
            </div>
            
            <div class="cols-header-label">Columnas (Estructura)</div>
            <div class="tc-cols-list" id="cols-${data.id}"></div>
            <button class="btn-add-col" onclick="addColToTable(${data.id})">+ Añadir Columna</button>
        </div>
    `;

    renderCols(data);
    updateConnections(); // Update lines if moved/rendered
}

function renderCols(data) {
    const list = document.getElementById('cols-' + data.id);
    list.innerHTML = '';
    data.columns.forEach((col, idx) => {
        const row = document.createElement('div');
        row.className = 'col-row';

        let extraControls = '';
        // VARCHAR/TEXT Controls
        if (col.type === 'VARCHAR' || col.type === 'TEXT') {
            extraControls += `
                <div class="col-extra">
                    <span>Len:</span> <input type="number" class="kylo-input-mini" value="${col.maxLength || 100}" onchange="updateColProp(${data.id}, ${idx}, 'maxLength', this.value)">
                    <span>Fill:</span> <input type="number" class="kylo-input-mini" value="${col.fillFactor || 50}" onchange="updateColProp(${data.id}, ${idx}, 'fillFactor', this.value)">%
                </div>
            `;
        }

        // FK Controls
        if (col.isFk) {
            let targetOpts = '<option value="">Sel. Tabla...</option>';
            constructorTables.forEach(t => {
                if (t.id !== data.id) { // Cannot ref self for simplicity in this MVP
                    targetOpts += `<option value="${t.id}" ${col.fkTableId == t.id ? 'selected' : ''}>${t.name}</option>`;
                }
            });

            let colOpts = '<option value="">Sel. Col...</option>';
            if (col.fkTableId) {
                const targetTable = constructorTables.find(t => t.id == col.fkTableId);
                if (targetTable) {
                    targetTable.columns.forEach((tc, tci) => {
                        // Default to trying to match name or ID, but here just list all
                        const cName = tc.name || `col_${tci + 1}`;
                        // If we saved index or name. Let's save index for simplicity or name? 
                        // Let's save index to be consistent with simulator, or name if preferred.
                        // Using Index for now.
                        colOpts += `<option value="${tci}" ${col.fkColIdx == tci ? 'selected' : ''}>${cName} (${tc.type})</option>`;
                    });
                }
            }

            extraControls += `
                <div class="col-extra" style="margin-top:4px; border-top:1px dashed #ddd; padding-top:4px">
                    <span style="color:#e74c3c; font-weight:bold; font-size:0.8em">FK -></span>
                    <select class="kylo-input-mini" style="width:100px" onchange="updateColProp(${data.id}, ${idx}, 'fkTableId', this.value)">${targetOpts}</select>
                    <select class="kylo-input-mini" style="width:80px" onchange="updateColProp(${data.id}, ${idx}, 'fkColIdx', this.value)">${colOpts}</select>
                </div>
            `;
        }

        row.innerHTML = `
            <div class="col-main-row">
                <input type="text" class="kylo-input" placeholder="nombre_col" style="flex:2; min-width:80px" value="${col.name || ('col_' + (idx + 1))}" oninput="updateColProp(${data.id}, ${idx}, 'name', this.value)">
                <select class="kylo-input" style="flex:1; min-width:80px" onchange="updateColProp(${data.id}, ${idx}, 'type', this.value)">
                    <option ${col.type === 'INT' ? 'selected' : ''}>INT</option>
                    <option ${col.type === 'BIGINT' ? 'selected' : ''}>BIGINT</option>
                    <option ${col.type === 'VARCHAR' ? 'selected' : ''}>VARCHAR</option>
                    <option ${col.type === 'TEXT' ? 'selected' : ''}>TEXT</option>
                    <option ${col.type === 'BOOL' ? 'selected' : ''}>BOOL</option>
                    <option ${col.type === 'DATE' ? 'selected' : ''}>DATE</option>
                    <option ${col.type === 'UUID' ? 'selected' : ''}>UUID</option>
                </select>
            </div>
            <div class="col-opts-row">
                <label class="chk-label" title="Primary Key"><input type="checkbox" ${col.isPk ? 'checked' : ''} onchange="updateColProp(${data.id}, ${idx}, 'isPk', this.checked)"> PK</label>
                <label class="chk-label"><input type="checkbox" ${col.isUnique ? 'checked' : ''} onchange="updateColProp(${data.id}, ${idx}, 'isUnique', this.checked)"> UK</label>
                <label class="chk-label"><input type="checkbox" ${col.isFk ? 'checked' : ''} onchange="updateColProp(${data.id}, ${idx}, 'isFk', this.checked)"> FK</label>
                <span class="del-col" onclick="removeCol(${data.id}, ${idx})"><i class="fas fa-times"></i></span>
            </div>
            ${extraControls}
        `;
        list.appendChild(row);
    });
}

function updateTableName(id, val) {
    const t = constructorTables.find(x => x.id === id);
    if (t) {
        t.name = val;
        updateSimulatorTableSelect();
    }
}

function updateTableRows(id, val) {
    const t = constructorTables.find(x => x.id === id);
    if (t) {
        t.rows = parseInt(val);
        // Sync inputs
        const card = document.getElementById('tc-' + t.id);
        if (card) {
            card.querySelectorAll('input[type=number]')[0].value = t.rows;
        }
        recalcAll();
        // Update simulator if relevant
        if (document.getElementById('sim-tbl-sel') && document.getElementById('sim-tbl-sel').value == id) calcSimIndex();
    }
}

function addColToTable(id) {
    const t = constructorTables.find(x => x.id === id);
    if (t) {
        const nextIdx = t.columns.length + 1;
        t.columns.push({ name: 'col_' + nextIdx, type: 'INT', maxLength: 0, fillFactor: 0, hasIndex: false, isUnique: false, isFk: false, isPk: false });
        renderCols(t);
        recalcAll();
        if (document.getElementById('sim-tbl-sel').value == id) updateSimulatorCols();
    }
}

function removeCol(id, idx) {
    const t = constructorTables.find(x => x.id === id);
    if (t) {
        t.columns.splice(idx, 1);
        renderCols(t);
        recalcAll();
        if (document.getElementById('sim-tbl-sel').value == id) updateSimulatorCols();
    }
}

function updateColProp(id, idx, prop, val) {
    const t = constructorTables.find(x => x.id === id);
    if (t && t.columns[idx]) {
        if (prop === 'maxLength' || prop === 'fillFactor') val = parseInt(val);
        t.columns[idx][prop] = val;

        // If name changed, only update Simulator (don't re-render cols to keep focus)
        if (prop === 'name') {
            updateSimulatorCols();
        }

        // If type or FK state changed, re-render to show/hide extra controls
        if (prop === 'type' || prop === 'isFk' || prop === 'fkTableId') {
            renderCols(t);
            updateSimulatorCols();
        }

        // --- AUTOMATIC TYPE PROPAGATION FOR FOREIGN KEYS ---
        if (prop === 'type' || prop === 'maxLength' || prop === 'fillFactor') {
            // Find anyone referencing THIS column
            constructorTables.forEach(otherTable => {
                otherTable.columns.forEach((otherCol, otherColIdx) => {
                    // Check if otherCol is a FK pointing to us
                    if (otherCol.isFk && otherCol.fkTableId == id && otherCol.fkColIdx == idx) {
                        // Found a child! Update it.
                        console.log(`Syncing FK type for table ${otherTable.name} col ${otherCol.name}`);
                        otherCol.type = t.columns[idx].type;
                        if (prop === 'maxLength') otherCol.maxLength = t.columns[idx].maxLength;
                        if (prop === 'fillFactor') otherCol.fillFactor = t.columns[idx].fillFactor;

                        // Re-render the child table to reflect changes
                        renderCols(otherTable);
                    }
                });
            });
        }

        // If FK Table ID changed, we need to reset the Col Idx probably?
        if (prop === 'fkTableId') {
            t.columns[idx]['fkColIdx'] = ''; // reset
            renderCols(t); // re-render to update the logic
            updateConnections();
        }

        if (prop === 'fkColIdx') {
            updateConnections();
        }

        recalcAll();
    }
}

function removeTable(id) {
    constructorTables = constructorTables.filter(x => x.id !== id);
    // Also remove visual indices linked to this table
    visualIndices = visualIndices.filter(v => v.tableId != id); // loose for string/int safety
    const el = document.getElementById('tc-' + id);
    if (el) el.remove();
    recalcAll();
    updateSimulatorTableSelect();
    renderVisualIndices(); // to clear nodes
}

function recalcAll() {
    let totalData = 0;
    let totalIndex = 0;

    constructorTables.forEach(t => {
        const res = calculateTableWeight(t.rows, t.columns);
        const badge = document.getElementById('badge-' + t.id);
        if (badge) badge.innerText = formatBytes(res.totalBytes);

        totalData += (res.dataBytes || 0);
        totalIndex += (res.indexBytes || 0);
    });

    const totalSystem = CONSTANTS.LOG_OVERHEAD;
    const grandTotal = (totalData || 0) + (totalIndex || 0) + (totalSystem || 0);

    document.getElementById('val-total-size').innerText = formatBytes(grandTotal);
    const bottomLbl = document.getElementById('dashboard-total-size');
    if (bottomLbl) bottomLbl.innerText = formatBytes(grandTotal);

    if (chartInstance) {
        chartInstance.data.datasets[0].data = [totalData, totalIndex, totalSystem];
        chartInstance.update();
    }
}

// --- INDEX SIMULATOR LOGIC & VISUALS ---

function openSimModal() {
    document.getElementById('sim-modal-overlay').style.display = 'flex';
    updateSimulatorTableSelect();
}

function closeSimModal() {
    document.getElementById('sim-modal-overlay').style.display = 'none';
}

function updateSimulatorTableSelect() {
    const sel = document.getElementById('sim-tbl-sel');
    if (!sel) return;
    const current = sel.value;
    sel.innerHTML = '<option value="">Selecciona Tabla...</option>';
    constructorTables.forEach(t => {
        sel.innerHTML += `<option value="${t.id}">${t.name}</option>`;
    });
    // Restore selection if valid
    if (current && constructorTables.find(t => t.id == current)) sel.value = current;
    // If no selection but tables exist, select first for UX
    if (!sel.value && constructorTables.length > 0) sel.value = constructorTables[0].id;

    updateSimulatorCols();
}

function updateSimulatorCols() {
    const sel = document.getElementById('sim-tbl-sel');
    const box = document.getElementById('sim-cols-box');
    const out = document.getElementById('sim-modal-result');
    if (!sel || !box) return;

    const tid = sel.value;
    box.innerHTML = '';
    if (out) out.innerText = '0 KB';

    if (!tid) return;

    const t = constructorTables.find(x => x.id == tid);
    if (!t) return;

    let html = '';
    t.columns.forEach((col, idx) => {
        // Use stored name or fallback
        const realName = col.name || `col_${idx + 1}`;
        const name = `${realName} (${col.type})`;
        html += `
            <label style="display:block; font-size:0.85em; margin-bottom:6px; cursor:pointer; color:#34495e; display:flex; align-items:center; gap:6px;">
                <input type="checkbox" class="sim-chk" value="${idx}" onchange="calcSimIndex()"> ${name}
            </label>
        `;
    });
    box.innerHTML = html;
}

function calcSimIndex() {
    const sel = document.getElementById('sim-tbl-sel');
    if (!sel || !sel.value) return;
    const t = constructorTables.find(x => x.id == sel.value);
    if (!t) return;

    const chks = document.querySelectorAll('.sim-chk:checked');
    if (chks.length === 0) {
        document.getElementById('sim-modal-result').innerText = '0 KB';
        return;
    }

    const colIndices = Array.from(chks).map(c => parseInt(c.value));
    // Debug
    // console.log("Simulating index for table", t.name, "cols", colIndices);
    const { bytes } = calculateIndexWeight(t, colIndices);
    // Force update
    const el = document.getElementById('sim-modal-result');
    if (el) el.innerText = formatBytes(bytes);
}

function calculateIndexWeight(table, colIndices) {
    let keySize = 0;
    colIndices.forEach(idx => {
        const col = table.columns[idx];
        if (col.type === 'VARCHAR' || col.type === 'TEXT') keySize += 20;
        else if (col.type === 'UUID') keySize += 16;
        else if (col.type === 'BOOL') keySize += 1;
        else if (col.type === 'BIGINT' || col.type === 'DATETIME') keySize += 8;
        else keySize += 4;
    });

    const entrySize = keySize + CONSTANTS.RID_SIZE + 4;
    let usablePageSpace = (CONSTANTS.PAGE_SIZE - CONSTANTS.PAGE_HEADER) * CONSTANTS.FILL_FACTOR;
    let keysPerPage = Math.floor(usablePageSpace / entrySize);
    if (keysPerPage < 1) keysPerPage = 1;

    const rows = parseInt(table.rows) || 0;
    const pages = Math.ceil(rows / keysPerPage);
    const bytes = pages * CONSTANTS.PAGE_SIZE;
    return { bytes, pages };
}

// --- VISUAL INDEX LOGIC ---

function addVisualIndex() {
    const sel = document.getElementById('sim-tbl-sel');
    if (!sel || !sel.value) return;
    const tid = sel.value;
    const t = constructorTables.find(x => x.id == tid);
    const chks = document.querySelectorAll('.sim-chk:checked');
    if (chks.length === 0) return;

    // Create Visual Index
    const colIndices = Array.from(chks).map(c => parseInt(c.value));
    const sizeRes = calculateIndexWeight(t, colIndices);

    // Position it to the right of the table
    const vx = (t.x || 0) + 350;
    const vy = (t.y || 0) + (visualIndices.filter(v => v.tableId == tid).length * 60);

    const vi = {
        id: Date.now(),
        tableId: tid,
        colIndices: colIndices,
        x: vx,
        y: vy,
        size: sizeRes.bytes
    };
    visualIndices.push(vi);
    renderVisualIndices();
    closeSimModal();
}

function renderVisualIndices() {
    const container = document.getElementById('constructor-canvas');
    // Clean old
    container.querySelectorAll('.visual-index-node').forEach(e => e.remove());

    visualIndices.forEach(vi => {
        const div = document.createElement('div');
        div.className = 'visual-index-node';
        div.id = 'vi-' + vi.id;
        div.style.left = vi.x + 'px';
        div.style.top = vi.y + 'px';
        // Get Column Names
        const t = constructorTables.find(x => x.id == vi.tableId);
        let colNames = "Sin columnas";
        if (t) {
            colNames = vi.colIndices.map(idx => t.columns[idx] ? t.columns[idx].name : '?').join(', ');
        }

        div.innerHTML = `<i class="fas fa-key"></i> <span style="margin-right:5px">${colNames}</span> <span style="background:rgba(0,0,0,0.2); padding:2px 6px; border-radius:10px; font-size:0.9em">${formatBytes(vi.size)}</span>`;

        // Delete button (right click or small 'x')
        const del = document.createElement('span');
        del.innerHTML = '&times;';
        del.style.marginLeft = '8px';
        del.style.cursor = 'pointer';
        del.onclick = (e) => { e.stopPropagation(); removeVisualIndex(vi.id); };
        div.appendChild(del);

        container.appendChild(div);

        // Drag logic for VI
        makeDraggable(div, (x, y) => {
            vi.x = x;
            vi.y = y;
            updateConnections();
        });
    });
    updateConnections();
}

function removeVisualIndex(id) {
    visualIndices = visualIndices.filter(v => v.id !== id);
    renderVisualIndices();
}

// --- DRAG & DROP & LINES ---

let activeDrag = null;
let dragOffset = { x: 0, y: 0 };
let onDragUpdate = null;

function makeDraggable(el, onMoveCallback = null) {
    el.addEventListener('mousedown', (e) => {
        const isTable = el.classList.contains('table-card');
        // Prevent dragging if clicking input/select/button
        if (['INPUT', 'SELECT', 'BUTTON', 'I', 'TEXTAREA'].includes(e.target.tagName)) return;

        activeDrag = el;
        // Since parent is relative/absolute, we need offset
        // We need to calculate offset relative to the element's current left/top
        const rect = el.getBoundingClientRect();

        // e.clientX is global. div.offsetLeft is local to parent.
        // We want to maintain the delta between mouse and top-left corner of div.
        // Simple: 
        // offset inside div = e.clientX - rect.left
        // When we move, we want div to be at mouseX - offset

        // But we are setting style.left (relative to parent).
        // So we need mouse relative to parent.
        const container = document.getElementById('constructor-canvas');
        const cRect = container.getBoundingClientRect();
        const mouseRelParentX = e.clientX - cRect.left;
        const mouseRelParentY = e.clientY - cRect.top;

        // Current Left
        const currentLeft = parseInt(el.style.left || 0);
        const currentTop = parseInt(el.style.top || 0);

        dragOffset.x = mouseRelParentX - currentLeft;
        dragOffset.y = mouseRelParentY - currentTop;

        el.classList.add('drag-active');

        // If it's a table, we update the data model on move
        if (isTable) {
            const tid = parseInt(el.id.replace('tc-', ''));
            const t = constructorTables.find(x => x.id === tid);
            onDragUpdate = (x, y) => {
                if (t) { t.x = x; t.y = y; }
                updateConnections();
            };
        } else if (onMoveCallback) {
            onDragUpdate = onMoveCallback;
        }
    });
}

function onCanvasMouseMove(e) {
    if (!activeDrag) return;

    const container = document.getElementById('constructor-canvas');
    const rect = container.getBoundingClientRect();

    // Mouse Pos relative to container
    const mx = e.clientX - rect.left + container.scrollLeft;
    const my = e.clientY - rect.top + container.scrollTop;

    let x = mx - dragOffset.x;
    let y = my - dragOffset.y;

    activeDrag.style.left = x + 'px';
    activeDrag.style.top = y + 'px';

    if (onDragUpdate) onDragUpdate(x, y);
}

function onCanvasMouseUp(e) {
    if (activeDrag) {
        activeDrag.classList.remove('drag-active');
        activeDrag = null;
        onDragUpdate = null;
    }
}

function updateConnections() {
    const svg = document.getElementById('connections-layer');
    if (!svg) return;
    svg.innerHTML = '';

    // 1. Visual Indices (Orange, Dashed)
    visualIndices.forEach(vi => {
        const t = constructorTables.find(x => x.id == vi.tableId);
        if (!t) return;

        const card = document.getElementById('tc-' + t.id);
        const node = document.getElementById('vi-' + vi.id);
        if (!card || !node) return;

        const start = getAnchor(card, 'right');
        const end = getAnchor(node, 'left');
        drawCurve(svg, start, end, '#e67e22', false);
    });

    // 2. Foreign Keys (Blue/Grey, Solid)
    constructorTables.forEach(t => {
        const cardSource = document.getElementById('tc-' + t.id);
        if (!cardSource) return;

        t.columns.forEach(col => {
            if (col.isFk && col.fkTableId) {
                const tTarget = constructorTables.find(x => x.id == col.fkTableId);
                if (tTarget) {
                    const cardTarget = document.getElementById('tc-' + tTarget.id);
                    if (cardTarget) {
                        const start = getAnchor(cardSource, 'right');
                        const end = getAnchor(cardTarget, 'left');
                        drawCurve(svg, start, end, '#3498db', false);
                    }
                }
            }
        });
    });
}

function getAnchor(el, side) {
    const x = parseInt(el.style.left || 0);
    const y = parseInt(el.style.top || 0);
    const w = el.offsetWidth;
    const h = el.offsetHeight;

    if (side === 'right') return { x: x + w, y: y + (h / 2) };
    if (side === 'left') return { x: x, y: y + (h / 2) };
    return { x, y };
}

function drawCurve(svg, start, end, color, dashed) {
    const line = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    const dist = Math.abs(end.x - start.x) / 2;
    // Cubic Bezier
    const d = `M ${start.x} ${start.y} C ${start.x + dist} ${start.y}, ${end.x - dist} ${end.y}, ${end.x} ${end.y}`;

    line.setAttribute('d', d);
    line.setAttribute('stroke', color);
    line.setAttribute('stroke-width', '2');
    line.setAttribute('fill', 'none');
    if (dashed) line.setAttribute('stroke-dasharray', '5,5');

    svg.appendChild(line);
}

function formatBytes(bytes, decimals = 2) {
    if (!+bytes) return '0 B';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    const safeI = Math.min(Math.max(i, 0), sizes.length - 1);
    return `${parseFloat((bytes / Math.pow(k, safeI)).toFixed(dm))} ${sizes[safeI]}`;
}
