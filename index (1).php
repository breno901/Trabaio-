<?php
require_once 'db.php';

// 1. Endpoint AJAX para salvar conversão no MySQL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_conversion') {
    header('Content-Type: application/json');
    
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $from = htmlspecialchars($_POST['from'] ?? '');
    $to = htmlspecialchars($_POST['to'] ?? '');
    $converted = filter_input(INPUT_POST, 'converted', FILTER_VALIDATE_FLOAT);
    $rate = filter_input(INPUT_POST, 'rate', FILTER_VALIDATE_FLOAT);

    if ($amount && $from && $to && $converted !== false && $rate) {
        $stmt = $pdo->prepare("INSERT INTO conversion_history (amount, from_currency, to_currency, converted_amount, exchange_rate) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$amount, $from, $to, $converted, $rate]);
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// 2. Busca das taxas (Com Cache de 1 hora no MySQL)
$baseCurrency = isset($_GET['base']) ? strtoupper(preg_replace('/[^A-Za-z]/', '', $_GET['base'])) : 'USD';
$rates = [];
$sourceInfo = "Banco de Dados (Cache)";

// Verifica se existe cache válido (menos de 60 minutos)
$stmt = $pdo->prepare("SELECT rates_json, updated_at FROM exchange_rates_cache WHERE base_currency = ? AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$stmt->execute([$baseCurrency]);
$cached = $stmt->fetch();

if ($cached) {
    $rates = json_decode($cached['rates_json'], true);
    $lastUpdate = date('H:i - d/m/Y', strtotime($cached['updated_at']));
} else {
    // Busca na API externa
    $apiUrl = "https://open.er-api.com/v6/latest/{$baseCurrency}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['result']) && $data['result'] === 'success') {
        $rates = $data['rates'];
        $ratesJson = json_encode($rates);
        $sourceInfo = "API ao Vivo (Salvo no MySQL)";
        $lastUpdate = date('H:i - d/m/Y');

        // Atualiza ou insere o cache no MySQL
        $upsert = $pdo->prepare("INSERT INTO exchange_rates_cache (base_currency, rates_json, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE rates_json = VALUES(rates_json), updated_at = NOW()");
        $upsert->execute([$baseCurrency, $ratesJson]);
    } else {
        // Fallback caso a API falhe e não haja cache
        $rates = ['USD' => 1, 'EUR' => 0.92, 'BRL' => 5.15, 'GBP' => 0.79, 'JPY' => 155.20, 'CAD' => 1.37, 'AUD' => 1.51, 'CHF' => 0.91, 'CNY' => 7.23, 'ARS' => 880.50];
        $sourceInfo = "Modo Offline";
        $lastUpdate = date('H:i - d/m/Y');
    }
}

// 3. Busca as últimas 10 conversões do histórico
$historyStmt = $pdo->query("SELECT * FROM conversion_history ORDER BY created_at DESC LIMIT 10");
$history = $historyStmt->fetchAll();

$featuredCurrencies = ['USD', 'EUR', 'BRL', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'CNY', 'ARS'];
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor & Conversor de Moedas + MySQL</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen font-sans">

    <!-- Header -->
    <header class="bg-slate-800 border-b border-slate-700 py-4 px-6 shadow-lg">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-3">
                <i data-lucide="coins" class="text-emerald-400 w-8 h-8"></i>
                <h1 class="text-2xl font-bold tracking-wide">GlobalExchange <span class="text-xs font-normal text-emerald-400 bg-emerald-950/80 border border-emerald-800 px-2 py-0.5 rounded-full">PHP + MySQL</span></h1>
            </div>
            <div class="text-xs text-slate-400 flex items-center gap-3">
                <span class="bg-slate-700/70 px-2.5 py-1 rounded-md text-slate-300">Fonte: <?php echo $sourceInfo; ?></span>
                <span>Atualizado: <strong class="text-slate-200"><?php echo $lastUpdate; ?></strong></span>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8 space-y-8">

        <!-- Seção 1: Conversor de Moedas -->
        <section class="bg-slate-800 rounded-2xl p-6 border border-slate-700 shadow-xl">
            <h2 class="text-xl font-semibold mb-6 flex items-center gap-2 text-emerald-400">
                <i data-lucide="arrow-right-left" class="w-5 h-5"></i> Conversor de Moedas (X para Y)
            </h2>

            <form id="converter-form" onsubmit="event.preventDefault(); convertAndSave();" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-2">VALOR</label>
                    <input type="number" id="amount" value="100" step="any" min="0.01" required
                        class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-emerald-500 transition">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-2">DE (MOEDA ORIGEM)</label>
                    <select id="from-currency" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-emerald-500 transition">
                        <?php foreach ($rates as $code => $rate): ?>
                            <option value="<?php echo $code; ?>" <?php echo $code === 'USD' ? 'selected' : ''; ?>><?php echo $code; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-2">PARA (MOEDA DESTINO)</label>
                    <select id="to-currency" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-emerald-500 transition">
                        <?php foreach ($rates as $code => $rate): ?>
                            <option value="<?php echo $code; ?>" <?php echo $code === 'BRL' ? 'selected' : ''; ?>><?php echo $code; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-600 text-slate-950 font-bold py-3 px-6 rounded-xl transition duration-200 flex items-center justify-center gap-2 shadow-lg shadow-emerald-500/20">
                        <i data-lucide="calculator" class="w-5 h-5"></i> Converter e Salvar
                    </button>
                </div>
            </form>

            <!-- Resultado -->
            <div id="result-box" class="mt-6 p-4 bg-slate-900/60 rounded-xl border border-slate-700/50 hidden">
                <div class="text-sm text-slate-400">Resultado estimado:</div>
                <div class="text-3xl font-extrabold text-emerald-400 mt-1" id="conversion-result">0.00</div>
                <div class="text-xs text-slate-500 mt-1" id="conversion-rate-text">1 USD = 0 BRL</div>
            </div>
        </section>

        <!-- Seção 2: Gráfico Comparativo -->
        <section class="bg-slate-800 rounded-2xl p-6 border border-slate-700 shadow-xl">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                <div>
                    <h2 class="text-xl font-semibold flex items-center gap-2 text-emerald-400">
                        <i data-lucide="bar-chart-3" class="w-5 h-5"></i> Cotações Internacionais em Gráfico
                    </h2>
                    <p class="text-xs text-slate-400 mt-1">Valor de 1 <span class="font-bold text-white"><?php echo $baseCurrency; ?></span> em relação a outras moedas globais.</p>
                </div>

                <form method="GET" class="flex items-center gap-2">
                    <label for="base" class="text-xs text-slate-400">Moeda Base:</label>
                    <select name="base" onchange="this.form.submit()" class="bg-slate-900 border border-slate-700 rounded-lg px-3 py-1.5 text-sm text-white focus:outline-none focus:border-emerald-500">
                        <?php foreach ($featuredCurrencies as $code): ?>
                            <option value="<?php echo $code; ?>" <?php echo $code === $baseCurrency ? 'selected' : ''; ?>><?php echo $code; ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <div class="relative w-full h-[350px]">
                <canvas id="currencyChart"></canvas>
            </div>
        </section>

        <!-- Seção 3: Histórico Salvo no MySQL -->
        <section class="bg-slate-800 rounded-2xl p-6 border border-slate-700 shadow-xl">
            <h2 class="text-xl font-semibold mb-4 flex items-center gap-2 text-emerald-400">
                <i data-lucide="history" class="w-5 h-5"></i> Últimas Conversões (MySQL)
            </h2>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-slate-700 text-xs uppercase text-slate-400">
                            <th class="py-3 px-4">Data / Hora</th>
                            <th class="py-3 px-4">Valor Original</th>
                            <th class="py-3 px-4">Conversão</th>
                            <th class="py-3 px-4">Taxa Aplicada</th>
                        </tr>
                    </thead>
                    <tbody id="history-table-body" class="divide-y divide-slate-700/50 text-sm">
                        <?php if (empty($history)): ?>
                            <tr id="empty-history-row">
                                <td colspan="4" class="py-6 text-center text-slate-500">Nenhuma conversão registrada ainda.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($history as $item): ?>
                                <tr class="hover:bg-slate-700/30 transition">
                                    <td class="py-3 px-4 text-slate-400"><?php echo date('d/m/Y H:i', strtotime($item['created_at'])); ?></td>
                                    <td class="py-3 px-4 font-medium"><?php echo number_format($item['amount'], 2, ',', '.') . ' ' . $item['from_currency']; ?></td>
                                    <td class="py-3 px-4 font-bold text-emerald-400"><?php echo number_format($item['converted_amount'], 2, ',', '.') . ' ' . $item['to_currency']; ?></td>
                                    <td class="py-3 px-4 text-slate-400">1 <?php echo $item['from_currency']; ?> = <?php echo number_format($item['exchange_rate'], 4, ',', '.'); ?> <?php echo $item['to_currency']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>

    <script>
        const allRates = <?php echo json_encode($rates); ?>;
        const featuredList = <?php echo json_encode($featuredCurrencies); ?>;
        const currentBase = "<?php echo $baseCurrency; ?>";

        // Configuração do Gráfico
        const chartLabels = [];
        const chartData = [];
        featuredList.forEach(code => {
            if (code !== currentBase && allRates[code]) {
                chartLabels.push(code);
                chartData.push(allRates[code]);
            }
        });

        const ctx = document.getElementById('currencyChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: `Valor de 1 ${currentBase}`,
                    data: chartData,
                    backgroundColor: 'rgba(16, 185, 129, 0.4)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 2,
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#94a3b8' } } },
                scales: {
                    x: { ticks: { color: '#94a3b8' }, grid: { color: '#334155' } },
                    y: { ticks: { color: '#94a3b8' }, grid: { color: '#334155' } }
                }
            }
        });

        // Função de Cálculo
        function calculate(saveToDb = false) {
            const amount = parseFloat(document.getElementById('amount').value);
            const from = document.getElementById('from-currency').value;
            const to = document.getElementById('to-currency').value;

            if (isNaN(amount) || amount <= 0) return;

            const rateFrom = allRates[from];
            const rateTo = allRates[to];
            if (!rateFrom || !rateTo) return;

            const convertedValue = (amount / rateFrom) * rateTo;
            const singleUnitRate = (1 / rateFrom) * rateTo;

            const formattedResult = new Intl.NumberFormat('pt-BR', {
                style: 'currency',
                currency: to
            }).format(convertedValue);

            document.getElementById('conversion-result').innerText = formattedResult;
            document.getElementById('conversion-rate-text').innerText = `Taxa atual: 1 ${from} = ${singleUnitRate.toFixed(4)} ${to}`;
            document.getElementById('result-box').classList.remove('hidden');

            if (saveToDb) {
                saveConversionToMySQL(amount, from, to, convertedValue, singleUnitRate);
            }
        }

        function convertAndSave() {
            calculate(true);
        }

        // Envia os dados via Fetch (AJAX) para salvar no MySQL sem recarregar a página
        function saveConversionToMySQL(amount, from, to, converted, rate) {
            const formData = new FormData();
            formData.append('action', 'save_conversion');
            formData.append('amount', amount);
            formData.append('from', from);
            formData.append('to', to);
            formData.append('converted', converted);
            formData.append('rate', rate);

            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    appendHistoryRow(amount, from, to, converted, rate);
                }
            });
        }

        // Adiciona a linha na tabela visualmente na hora
        function appendHistoryRow(amount, from, to, converted, rate) {
            const emptyRow = document.getElementById('empty-history-row');
            if (emptyRow) emptyRow.remove();

            const tbody = document.getElementById('history-table-body');
            const now = new Date();
            const dateStr = now.toLocaleDateString('pt-BR') + ' ' + now.toLocaleTimeString('pt-BR', {hour: '2-digit', minute:'2-digit'});

            const row = document.createElement('tr');
            row.className = 'hover:bg-slate-700/30 transition bg-emerald-950/20';
            row.innerHTML = `
                <td class="py-3 px-4 text-slate-400">${dateStr}</td>
                <td class="py-3 px-4 font-medium">${amount.toLocaleString('pt-BR', {minimumFractionDigits: 2})} ${from}</td>
                <td class="py-3 px-4 font-bold text-emerald-400">${converted.toLocaleString('pt-BR', {minimumFractionDigits: 2})} ${to}</td>
                <td class="py-3 px-4 text-slate-400">1 ${from} = ${rate.toLocaleString('pt-BR', {minimumFractionDigits: 4})} ${to}</td>
            `;
            tbody.prepend(row);
        }

        lucide.createIcons();
        calculate(false); // Calcula o valor inicial sem salvar no banco
    </script>
</body>
</html>
