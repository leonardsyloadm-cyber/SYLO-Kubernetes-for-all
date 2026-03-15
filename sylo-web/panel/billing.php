<?php
// view: sylo-web/panel/billing.php
require_once 'php/data.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/index.php');
    exit;
}

$active_tab = "billing";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facturación - Sylo</title>
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../public/css/style.css">
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
                <h3 class="fw-bold mb-0">Facturación</h3>
            </div>
            
            <div class="row">
                <div class="col-12">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                            <h5 class="fw-bold mb-3">Historial de Facturas</h5>
                            <p class="text-muted small">Tus gastos mensuales se calculan en base a los servicios activos el día 1 de cada mes.</p>
                        </div>
                        <div class="card-body p-4">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light text-muted">
                                        <tr>
                                            <th>ID Factura</th>
                                            <th>Período</th>
                                            <th>Estado</th>
                                            <th class="text-end">Importe</th>
                                            <th>Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody id="invoicesTableBody">
                                        <tr><td colspan="5" class="text-center py-4 text-muted">Cargando facturas...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", () => {
        loadInvoices();
    });

    async function loadInvoices() {
        try {
            const res = await fetch("php/data.php?api_invoices=1");
            const invoices = await res.json();
            const tbody = document.getElementById('invoicesTableBody');
            tbody.innerHTML = '';
            
            if(invoices.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No hay facturas disponibles por el momento.</td></tr>';
                return;
            }

            invoices.forEach(inv => {
                const dateObj = new Date(inv.issue_date);
                const monthYear = dateObj.toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });
                
                let badgeClass = 'bg-warning text-dark';
                let statusText = 'Pendiente';
                if(inv.status === 'paid') { badgeClass = 'bg-success'; statusText = 'Pagada'; }
                if(inv.status === 'cancelled') { badgeClass = 'bg-danger'; statusText = 'Cancelada'; }
                
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="fw-bold text-muted">#INV-${inv.id.toString().padStart(4, '0')}</td>
                    <td class="text-capitalize">${monthYear}</td>
                    <td><span class="badge ${badgeClass} rounded-pill">${statusText}</span></td>
                    <td class="text-end fw-bold">${parseFloat(inv.amount).toFixed(2)} €</td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary rounded-pill ${inv.status === 'pending' ? '' : 'disabled'}" onclick="payInvoice(${inv.id})">
                            Pagar
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        } catch(e) { console.error('Error loading invoices', e); }
    }

    function payInvoice(id) {
        alert("La pasarela de pago (Stripe) no está integrada en esta demo local.");
    }
</script>
</body>
</html>
