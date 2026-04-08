<div class="container">
    <div class="page-header">
        <h1>
            <i class="fas fa-home"></i> 
            XMLConcilia
        </h1>
        <p class="subtitle">Sistema de Conciliación de Facturas XML vs Gastos</p>
    </div>

    <!-- Estadísticas rápidas -->
    <div class="stats-grid">
        <div class="stat-card stat-primary">
            <div class="stat-icon">
                <i class="fas fa-file-invoice"></i>
            </div>
            <div class="stat-content">
                <h3><?= number_format($stats['total_facturas']) ?></h3>
                <p>Facturas XML</p>
            </div>
        </div>

        <div class="stat-card stat-success">
            <div class="stat-icon">
                <i class="fas fa-receipt"></i>
            </div>
            <div class="stat-content">
                <h3><?= number_format($stats['total_gastos']) ?></h3>
                <p>Gastos Registrados</p>
            </div>
        </div>

        <div class="stat-card stat-info">
            <div class="stat-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-content">
                <h3><?= number_format($stats['total_conciliadas']) ?></h3>
                <p>Conciliadas</p>
            </div>
        </div>

        <div class="stat-card stat-warning">
            <div class="stat-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-content">
                <h3><?= number_format($stats['pendientes_revision']) ?></h3>
                <p>Pendientes Revisión</p>
            </div>
        </div>
    </div>

    <!-- Acciones rápidas -->
    <div class="quick-actions">
        <h2><i class="fas fa-bolt"></i> Acciones Rápidas</h2>
        
        <div class="action-grid">
            <a href="<?= $this->url('conciliacion') ?>" class="action-card">
                <i class="fas fa-table-columns"></i>
                <h3>Panel Único</h3>
                <p>Importar XML, gastos y conciliar en la misma vista</p>
            </a>

            <a href="<?= $this->url('conciliacion') ?>" class="action-card">
                <i class="fas fa-file-circle-check"></i>
                <h3>Ver Cargas</h3>
                <p>Revisar facturas y gastos en ventanas flotantes</p>
            </a>

            <a href="<?= $this->url('conciliacion') ?>" class="action-card">
                <i class="fas fa-sync"></i>
                <h3>Ejecutar Conciliación</h3>
                <p>Lanzar el proceso de conciliación automática</p>
            </a>

            <a href="<?= $this->url('reportes') ?>" class="action-card">
                <i class="fas fa-chart-bar"></i>
                <h3>Ver Reportes</h3>
                <p>Consultar reportes y estadísticas</p>
            </a>
        </div>
    </div>

    <!-- Información -->
    <div class="info-section">
        <div class="info-card">
            <h3><i class="fas fa-info-circle"></i> Acerca del Sistema</h3>
            <p>
                XMLConcilia es un sistema de verificación y conciliación entre facturas XML (CFDI) 
                y registros de gastos. Utiliza algoritmos de coincidencia difusa (fuzzy matching) 
                para identificar correspondencias entre documentos.
            </p>
            <ul>
                <li>✓ Importación masiva de archivos XML</li>
                <li>✓ Carga de gastos desde Excel/CSV</li>
                <li>✓ Conciliación automática con scoring</li>
                <li>✓ Sistema de revisión manual</li>
                <li>✓ Reportes y exportación de resultados</li>
            </ul>
        </div>

        <div class="info-card">
            <h3><i class="fas fa-traffic-light"></i> Sistema de Estados</h3>
            <div class="estado-list">
                <div class="estado-item">
                    <span class="badge badge-conciliada">🟢 Conciliada</span>
                    <span>Match 100% - Datos coinciden exactamente</span>
                </div>
                <div class="estado-item">
                    <span class="badge badge-requiere-revision">🟠 Requiere Revisión</span>
                    <span>Match parcial - Requiere validación manual</span>
                </div>
                <div class="estado-item">
                    <span class="badge badge-con-diferencias">🟡 Con Diferencias</span>
                    <span>Match encontrado con diferencias en monto</span>
                </div>
                <div class="estado-item">
                    <span class="badge badge-pendiente">🔵 Pendiente</span>
                    <span>No se encontró coincidencia</span>
                </div>
                <div class="estado-item">
                    <span class="badge badge-sin-xml">🔴 Sin XML</span>
                    <span>Gasto sin factura XML correspondiente</span>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    text-align: center;
    margin-bottom: 40px;
}

.page-header h1 {
    color: #2c3e50;
    font-size: 2.5em;
    margin-bottom: 10px;
}

.subtitle {
    color: #7f8c8d;
    font-size: 1.1em;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
}

.stat-card {
    background: white;
    border-radius: 8px;
    padding: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 20px;
}

.stat-icon {
    font-size: 3em;
    opacity: 0.8;
}

.stat-primary .stat-icon { color: #3498db; }
.stat-success .stat-icon { color: #2ecc71; }
.stat-info .stat-icon { color: #9b59b6; }
.stat-warning .stat-icon { color: #f39c12; }

.stat-content h3 {
    font-size: 2em;
    margin: 0;
    color: #2c3e50;
}

.stat-content p {
    margin: 5px 0 0 0;
    color: #7f8c8d;
}

.quick-actions {
    margin-bottom: 40px;
}

.quick-actions h2 {
    color: #2c3e50;
    margin-bottom: 20px;
}

.action-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.action-card {
    background: white;
    border-radius: 8px;
    padding: 30px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    text-decoration: none;
    color: inherit;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.action-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
    border-color: #3498db;
}

.action-card i {
    font-size: 3em;
    color: #3498db;
    margin-bottom: 15px;
}

.action-card h3 {
    color: #2c3e50;
    margin: 10px 0;
}

.action-card p {
    color: #7f8c8d;
    font-size: 0.9em;
}

.info-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
}

.info-card {
    background: white;
    border-radius: 8px;
    padding: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.info-card h3 {
    color: #2c3e50;
    margin-bottom: 15px;
}

.info-card ul {
    list-style: none;
    padding: 0;
}

.info-card ul li {
    padding: 5px 0;
    color: #555;
}

.estado-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.estado-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 4px;
}

.badge {
    padding: 5px 12px;
    border-radius: 4px;
    font-weight: 500;
    white-space: nowrap;
}

.badge-conciliada { background: #d4edda; color: #155724; }
.badge-requiere-revision { background: #fff3cd; color: #856404; }
.badge-con-diferencias { background: #fff3cd; color: #856404; }
.badge-pendiente { background: #cce5ff; color: #004085; }
.badge-sin-xml { background: #f8d7da; color: #721c24; }

@media (max-width: 768px) {
    .info-section {
        grid-template-columns: 1fr;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
}
</style>

