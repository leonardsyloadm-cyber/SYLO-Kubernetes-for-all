<?php
// view: sylo-web/panel/tickets.php
require_once 'php/data.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/index.php');
    exit;
}

$active_tab = "tickets";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Soporte - Sylo</title>
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../public/css/style.css">
    <!-- Custom CSS for tickets -->
    <style>
        .ticket-card {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .ticket-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
        }
        .chat-bubble {
            max-width: 80%;
            padding: 12px 16px;
            border-radius: 16px;
            margin-bottom: 12px;
        }
        .bubble-client {
            background-color: #2563eb;
            color: white;
            border-bottom-right-radius: 4px;
            align-self: flex-end;
        }
        .bubble-admin {
            background-color: #e2e8f0;
            color: #1e293b;
            border-bottom-left-radius: 4px;
            align-self: flex-start;
        }
    </style>
</head>
<body class="bg-light">

<div class="container-fluid p-0 d-flex h-100">
    
    <!-- SIDEBAR -->
    <?php include 'sidebar.php'; ?>

    <!-- MAIN CONTENT -->
    <div class="flex-grow-1 overflow-auto bg-light" style="height: 100vh;">
        
        <!-- TOP NAVBAR -->
        <?php include 'navbar.php'; ?>
        
        <!-- CONTENT PANE -->
        <div class="container-fluid py-4 px-lg-5">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold mb-0">Soporte Técnico</h3>
                <button class="btn btn-primary rounded-pill fw-bold" onclick="openNewTicketModal()">
                    <i class="fas fa-plus me-2"></i>Nuevo Ticket
                </button>
            </div>
            
            <div class="row">
                <!-- TICKET LIST -->
                <div class="col-md-5 col-lg-4 mb-4">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                            <h5 class="fw-bold mb-3">Mis Tickets</h5>
                            <div class="input-group mb-3">
                                <span class="input-group-text bg-light border-0"><i class="fas fa-search text-muted"></i></span>
                                <input type="text" class="form-control bg-light border-0" id="searchTickets" placeholder="Buscar...">
                            </div>
                        </div>
                        <div class="card-body p-0" id="ticketList" style="overflow-y: auto; max-height: calc(100vh - 280px);">
                            <!-- Tickets injected here -->
                            <div class="text-center p-4 text-muted">Cargando tickets...</div>
                        </div>
                    </div>
                </div>
                
                <!-- TICKET DETAIL (CHAT) -->
                <div class="col-md-7 col-lg-8 mb-4">
                    <div class="card border-0 shadow-sm rounded-4 h-100 d-flex flex-column" id="ticketViewContainer" style="display:none !important">
                        <div class="card-header bg-white border-bottom p-4">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="fw-bold mb-1" id="tvSubject">Selecciona un ticket</h5>
                                    <span class="badge bg-secondary" id="tvStatus">Estado</span>
                                    <small class="text-muted ms-2" id="tvDate">Fecha</small>
                                </div>
                                <span class="text-muted small">ID: #<span id="tvId">0</span></span>
                            </div>
                        </div>
                        <div class="card-body p-4 d-flex flex-column" style="overflow-y: auto; max-height: calc(100vh - 350px);" id="tvMessagesWindow">
                            <!-- Messages injected here -->
                            <div class="text-center mt-5 text-muted">
                                <i class="fas fa-comments fa-3x mb-3 text-light"></i>
                                <h5>Selecciona un ticket para ver los detalles</h5>
                            </div>
                        </div>
                        <div class="card-footer bg-light border-top p-3" id="tvReplyForm" style="display:none">
                            <form id="replyForm" class="d-flex gap-2" onsubmit="sendReply(event)">
                                <input type="hidden" id="replyTicketId">
                                <input type="text" id="replyText" class="form-control rounded-pill border-0 shadow-sm px-4" placeholder="Escribe una respuesta..." required autocomplete="off">
                                <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm" id="btnSendReply">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Modal Nuevo Ticket -->
<div class="modal fade" id="newTicketModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 p-2">
            <div class="modal-header border-0">
                <h5 class="fw-bold text-primary mb-0"><i class="fas fa-life-ring me-2"></i>Abrir Ticket</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formNewTicket" onsubmit="createTicket(event)">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Asunto</label>
                        <input type="text" class="form-control rounded-3 bg-light border-0" id="ntSubject" required placeholder="Ej: Problema con balanceador de carga">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-bold small">Mensaje</label>
                        <textarea class="form-control rounded-3 bg-light border-0" id="ntMessage" rows="5" required placeholder="Describe tu problema con detalle..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 rounded-pill fw-bold py-2" id="btnCreateTicket">Enviar Ticket</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let tickets = [];
    let currentTicketId = null;

    document.addEventListener("DOMContentLoaded", () => {
        loadTickets();
        
        document.getElementById('searchTickets').addEventListener('input', function(e) {
            renderTicketList(e.target.value);
        });
    });

    async function loadTickets() {
        try {
            const res = await fetch("php/data.php?api_tickets=1");
            tickets = await res.json();
            renderTicketList();
        } catch(e) { console.error('Error loading tickets', e); }
    }

    function renderTicketList(filter = '') {
        const listDiv = document.getElementById('ticketList');
        listDiv.innerHTML = '';
        
        let filtered = tickets;
        if(filter) {
            const low = filter.toLowerCase();
            filtered = tickets.filter(t => t.subject.toLowerCase().includes(low) || t.id.toString().includes(low));
        }
        
        if(filtered.length === 0) {
            listDiv.innerHTML = '<div class="text-center p-4 text-muted small">No hay tickets.</div>';
            return;
        }

        filtered.forEach(t => {
            const date = new Date(t.updated_at).toLocaleDateString();
            let badgeClass = 'bg-secondary';
            if(t.status === 'open') badgeClass = 'bg-success';
            if(t.status === 'answered') badgeClass = 'bg-primary';
            
            const div = document.createElement('div');
            div.className = `p-3 border-bottom ticket-card bg-white ${t.id === currentTicketId ? 'bg-light border-start border-primary border-4' : ''}`;
            div.onclick = () => viewTicket(t.id);
            div.innerHTML = `
                <div class="d-flex justify-content-between mb-1">
                    <span class="fw-bold text-truncate" style="max-width: 70%;">${t.subject}</span>
                    <small class="text-muted">#${t.id}</small>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="badge ${badgeClass} small" style="font-size:0.7rem">${t.status.toUpperCase()}</span>
                    <small class="text-muted" style="font-size:0.75rem">${date}</small>
                </div>
            `;
            listDiv.appendChild(div);
        });
    }

    async function viewTicket(id) {
        currentTicketId = id;
        renderTicketList(document.getElementById('searchTickets').value); // Update active state
        
        const ticket = tickets.find(t => t.id === id);
        if(!ticket) return;

        document.getElementById('ticketViewContainer').style.display = 'flex';
        document.getElementById('tvSubject').innerText = ticket.subject;
        document.getElementById('tvId').innerText = ticket.id;
        document.getElementById('tvDate').innerText = new Date(ticket.created_at).toLocaleString();
        document.getElementById('replyTicketId').value = ticket.id;
        
        let badgeClass = 'bg-secondary';
        if(ticket.status === 'open') badgeClass = 'bg-success';
        if(ticket.status === 'answered') badgeClass = 'bg-primary';
        document.getElementById('tvStatus').className = `badge ${badgeClass}`;
        document.getElementById('tvStatus').innerText = ticket.status.toUpperCase();
        
        if(ticket.status === 'closed') {
            document.getElementById('tvReplyForm').style.display = 'none';
        } else {
            document.getElementById('tvReplyForm').style.display = 'block';
        }

        const msgWindow = document.getElementById('tvMessagesWindow');
        msgWindow.innerHTML = '<div class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm" role="status"></div></div>';

        try {
            const res = await fetch(\`php/data.php?api_ticket_messages=1&ticket_id=\${id}\`);
            const messages = await res.json();
            
            msgWindow.innerHTML = '';
            messages.forEach(m => {
                const isClient = m.sender === 'client';
                const time = new Date(m.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                const clazz = isClient ? 'bubble-client' : 'bubble-admin ms-auto';
                
                msgWindow.innerHTML += \`
                    <div class="d-flex flex-column mb-3" style="align-items: \${isClient ? 'flex-end' : 'flex-start'}">
                        <small class="text-muted mb-1 px-1" style="font-size:0.7rem">\${isClient ? 'Tú' : 'Sylo Support'} • \${time}</small>
                        <div class="chat-bubble \${clazz} shadow-sm">
                            \${m.message.replace(/\\n/g, '<br>')}
                        </div>
                    </div>
                \`;
            });
            msgWindow.scrollTop = msgWindow.scrollHeight;
        } catch(e) {
            msgWindow.innerHTML = '<div class="text-center text-danger py-3">Error cargando mensajes.</div>';
        }
    }

    function openNewTicketModal() {
        document.getElementById('formNewTicket').reset();
        new bootstrap.Modal(document.getElementById('newTicketModal')).show();
    }

    async function createTicket(e) {
        e.preventDefault();
        const btn = document.getElementById('btnCreateTicket');
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Enviando...';
        btn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'create_ticket');
        formData.append('subject', document.getElementById('ntSubject').value);
        formData.append('message', document.getElementById('ntMessage').value);

        try {
            const res = await fetch('php/data.php', { method: 'POST', body: formData });
            const data = await res.json();
            if(data.status === 'success') {
                bootstrap.Modal.getInstance(document.getElementById('newTicketModal')).hide();
                await loadTickets();
                if(data.ticket_id) viewTicket(data.ticket_id);
            } else {
                alert(data.message || 'Error creating ticket');
            }
        } catch(err) { console.error(err); alert('Error de red'); }
        btn.innerHTML = 'Enviar Ticket';
        btn.disabled = false;
    }

    async function sendReply(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSendReply');
        const input = document.getElementById('replyText');
        const ticketId = document.getElementById('replyTicketId').value;
        const msg = input.value.trim();
        
        if(!msg) return;

        btn.disabled = true;
        input.disabled = true;

        const formData = new FormData();
        formData.append('action', 'reply_ticket');
        formData.append('ticket_id', ticketId);
        formData.append('message', msg);

        try {
            const res = await fetch('php/data.php', { method: 'POST', body: formData });
            const data = await res.json();
            if(data.status === 'success') {
                input.value = '';
                await viewTicket(ticketId); // Reload chat
                await loadTickets(); // Refresh list to bump updated_at
                viewTicket(ticketId);
            } else {
                alert(data.message || 'Error sending reply');
            }
        } catch(err) { console.error(err); alert('Error de red'); }

        btn.disabled = false;
        input.disabled = false;
        input.focus();
    }
</script>
</body>
</html>
