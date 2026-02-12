    </main>
    
    <footer style="background: #2c3e50; color: white; padding: 30px 0; margin-top: 40px;">
        <div style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 30px; margin-bottom: 20px;">
                <div>
                    <h3 style="margin-bottom: 15px;">
                        <i class="fas fa-file-invoice"></i>
                        XMLConcilia
                    </h3>
                    <p style="color: #bdc3c7; font-size: 0.9em;">
                        Sistema de conciliación entre facturas XML (CFDI) y registros de gastos con algoritmo de fuzzy matching.
                    </p>
                </div>
                
                <div>
                    <h4 style="margin-bottom: 15px;">Enlaces Rápidos</h4>
                    <ul style="list-style: none; padding: 0;">
                        <li style="margin-bottom: 8px;">
                            <a href="<?= defined('APP_URL') ? APP_URL : '/xmlconcilia/public' ?>/facturas/importar" style="color: #3498db; text-decoration: none;">
                                <i class="fas fa-file-upload"></i> Importar Facturas
                            </a>
                        </li>
                        <li style="margin-bottom: 8px;">
                            <a href="<?= defined('APP_URL') ? APP_URL : '/xmlconcilia/public' ?>/gastos/importar" style="color: #3498db; text-decoration: none;">
                                <i class="fas fa-upload"></i> Importar Gastos
                            </a>
                        </li>
                        <li style="margin-bottom: 8px;">
                            <a href="<?= defined('APP_URL') ? APP_URL : '/xmlconcilia/public' ?>/conciliacion/ejecutar" style="color: #3498db; text-decoration: none;">
                                <i class="fas fa-sync"></i> Ejecutar Conciliación
                            </a>
                        </li>
                    </ul>
                </div>
                
                <div>
                    <h4 style="margin-bottom: 15px;">Información</h4>
                    <p style="color: #bdc3c7; font-size: 0.9em; margin-bottom: 8px;">
                        <i class="fas fa-code"></i> Versión: 1.0.0
                    </p>
                    <p style="color: #bdc3c7; font-size: 0.9em; margin-bottom: 8px;">
                        <i class="fas fa-calendar"></i> <?= date('Y') ?>
                    </p>
                    <p style="color: #bdc3c7; font-size: 0.9em;">
                        <i class="fas fa-server"></i> PHP <?= PHP_VERSION ?>
                    </p>
                </div>
            </div>
            
            <div style="border-top: 1px solid #34495e; padding-top: 20px; text-align: center; color: #95a5a6; font-size: 0.9em;">
                <p>
                    &copy; <?= date('Y') ?> XMLConcilia - Sistema de Conciliación de Facturas XML
                </p>
            </div>
        </div>
    </footer>
    
    <!-- Scripts -->
    <script src="<?= defined('APP_URL') ? APP_URL : '/xmlconcilia/public' ?>/assets/js/app.js"></script>
</body>
</html>

