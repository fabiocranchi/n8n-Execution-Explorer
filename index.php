<?php
// ==========================================
// CONFIGURAÇÕES
// ==========================================
$n8nUrl = 'https://ia-n8n.nvezru.easypanel.host'; // Sem barra no final
$apiKey = 'sua-chave-api-aqui';

// ==========================================
// FUNÇÕES DE BACKEND
// ==========================================

function n8nRequest($url, $apiKey, $endpoint) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-N8N-API-KEY: ' . $apiKey,
        'Accept: application/json'
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return [
            'error' => true, 
            'http_code' => $httpCode, 
            'response' => $result, 
            'curl_error' => curl_error($ch)
        ]; 
    }
    $decoded = json_decode($result, true);
    return $decoded ?: [];
}

// ==========================================
// AJAX HANDLER (Retorna JSON)
// ==========================================
if (isset($_GET['ajax_data'])) {
    header('Content-Type: application/json');
    
    $selectedWorkflowId = $_GET['workflowId'] ?? '';
    // Datas já vêm do JS no formato Y-m-d, convertemos para ISO
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 day'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d', strtotime('+1 day'));
    $status = $_GET['status'] ?? 'all';
    $cursor = $_GET['cursor'] ?? null;

    /* REMOVIDO: Permitir busca global sem workflow selecionado
    if (empty($selectedWorkflowId)) {
        echo json_encode(['data' => [], 'nextCursor' => null]);
        exit;
    }
    */

    $isoStart = date('Y-m-d\T00:00:00\Z', strtotime($startDate));
    $isoEnd = date('Y-m-d\T23:59:59\Z', strtotime($endDate));

    // Busca apenas UMA página de 250 itens
    $queryParams = [
        'limit' => 250,
        'includeData' => 'false'
    ];
    if (!empty($selectedWorkflowId)) {
        $queryParams['workflowId'] = $selectedWorkflowId;
    }
    if ($cursor) {
        $queryParams['cursor'] = $cursor;
    }

    $query = http_build_query($queryParams);
    $response = n8nRequest($n8nUrl, $apiKey, '/api/v1/executions?' . $query);

    if (isset($response['error'])) {
        http_response_code(400); // Bad Request para o JS pegar o erro
        echo json_encode($response);
        exit;
    }

    $rawExecutions = $response['data'] ?? [];
    $nextCursor = $response['nextCursor'] ?? null;

    // Filtrar localmente por Data e Status
    $filtered = array_values(array_filter($rawExecutions, function($exec) use ($isoStart, $isoEnd, $status) {
        if (!isset($exec['startedAt'])) return false;

        // 1. Filtro de Data
        if ($exec['startedAt'] < $isoStart || $exec['startedAt'] > $isoEnd) {
            return false;
        }

        // 2. Filtro de Status
        if ($status === 'success') {
            // Comparação solta para garantir
            if (!isset($exec['stoppedAt']) || $exec['finished'] != true) return false;
        } elseif ($status === 'error') {
            if (!isset($exec['stoppedAt']) || $exec['finished'] == true) return false;
        }

        return true;
    }));

    echo json_encode([
        'data' => $filtered, 
        'nextCursor' => $nextCursor
    ]);
    exit;
}

// ==========================================
// AJAX HANDLER: DETALHES DA EXECUÇÃO
// ==========================================
if (isset($_GET['execution_details'])) {
    header('Content-Type: application/json');
    $execId = $_GET['execution_id'] ?? '';
    
    if (empty($execId)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID da execução obrigatório']);
        exit;
    }

    // Busca detalhes com includeData=true para pegar o JSON
    $response = n8nRequest($n8nUrl, $apiKey, '/api/v1/executions/' . $execId . '?includeData=true');

    if (isset($response['error'])) {
        http_response_code(400);
        echo json_encode($response);
        exit;
    }

    echo json_encode($response);
    exit;
}

// ==========================================
// RENDERIZAÇÃO INICIAL DA PÁGINA (HTML)
// ==========================================

// 1. Buscar Workflows para o Dropdown
$workflowsRaw = n8nRequest($n8nUrl, $apiKey, '/api/v1/workflows?active=true');
$workflowList = $workflowsRaw['data'] ?? [];
usort($workflowList, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
$jsWorkflows = json_encode(array_map(function($w) {
    return ['id' => $w['id'], 'name' => $w['name']];
}, $workflowList));

// Defaults
$defaultStartDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 day'));
$defaultEndDate = $_GET['end_date'] ?? date('Y-m-d', strtotime('+1 day'));
$defaultWorkflowId = $_GET['workflowId'] ?? '';
$defaultStatus = $_GET['status'] ?? 'all';
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>n8n Advanced Search (Dynamic)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>
    
    <style>
        body { background-color: #0f172a; color: #e2e8f0; }
        .input-dark { background-color: #1e293b; border-color: #334155; color: white; }
        .input-dark:focus { border-color: #f97316; ring: #f97316; outline: none; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: #1e293b; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #475569; border-radius: 4px; }
        .json-pre { white-space: pre-wrap; word-break: break-all; font-family: monospace; font-size: 0.75rem; }
    </style>
</head>
<body class="p-6 font-sans antialiased min-h-screen"
      x-data="app()" 
      x-init="initApp()" 
      @scroll.window.debounce.100ms="handleScroll">

    <div class="max-w-7xl mx-auto">
        
        <div class="mb-8 border-b border-slate-700 pb-4">
            <h1 class="text-3xl font-bold text-white">
                <span class="text-orange-500">n8n</span> Execution Explorer
            </h1>
            <p class="text-slate-400">Scroll infinito com carregamento dinâmico.</p>
        </div>

        <!-- Filtros -->
        <div class="bg-slate-800 p-6 rounded-xl shadow-lg mb-8 border border-slate-700">
            <form @submit.prevent="search(true)" class="grid grid-cols-1 md:grid-cols-12 gap-6 items-end">
                
                <!-- Dropdown Workflow -->
                <div class="md:col-span-4 relative" 
                     x-data="{ 
                        search: '', 
                        open: false, 
                        items: <?php echo htmlspecialchars($jsWorkflows, ENT_QUOTES, 'UTF-8'); ?>,
                        get filteredItems() {
                            return this.items.filter(i => i.name.toLowerCase().includes(this.search.toLowerCase()))
                        },
                        initItem() {
                            const found = this.items.find(i => i.id === params.workflowId);
                            if(found) { this.search = found.name; }
                        }
                     }"
                     x-init="initItem()">
                    
                    <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Workflow</label>
                    <input type="text" 
                           x-model="search"
                           @focus="open = true; search = ''" 
                           @click.outside="open = false"
                           placeholder="Digite para buscar..."
                           class="w-full px-4 py-3 rounded-lg border input-dark shadow-sm text-sm"
                           autocomplete="off">
                    <input type="hidden" x-model="params.workflowId">

                    <div x-show="open" 
                         x-transition.opacity
                         class="absolute z-50 w-full mt-1 bg-slate-900 border border-slate-600 rounded-lg shadow-2xl max-h-60 overflow-y-auto custom-scroll">
                        <template x-for="item in filteredItems" :key="item.id">
                            <div @click="params.workflowId = item.id; search = item.name; open = false"
                                 class="px-4 py-2 cursor-pointer hover:bg-orange-600 hover:text-white text-sm text-slate-300">
                                <span x-text="item.name"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Status -->
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Status</label>
                    <select x-model="params.status" class="w-full px-4 py-3 rounded-lg border input-dark text-sm">
                        <option value="all">Todos</option>
                        <option value="success">Sucesso</option>
                        <option value="error">Erro</option>
                    </select>
                </div>

                <!-- Datas -->
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-slate-400 uppercase mb-2">De</label>
                    <input type="date" x-model="params.startDate" class="w-full px-4 py-3 rounded-lg border input-dark text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Até</label>
                    <input type="date" x-model="params.endDate" class="w-full px-4 py-3 rounded-lg border input-dark text-sm">
                </div>

                <!-- Botão Buscar -->
                <div class="md:col-span-2">
                    <button type="submit" 
                            class="w-full bg-orange-600 hover:bg-orange-500 text-white font-bold py-3 px-4 rounded-lg shadow-lg flex justify-center items-center gap-2"
                            :disabled="loading">
                        <span x-show="loading" class="animate-spin h-5 w-5 border-2 border-white border-t-transparent rounded-full"></span>
                        <span x-show="!loading">Buscar</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Resultados -->
        <div class="bg-slate-800 rounded-xl overflow-hidden shadow-xl border border-slate-700 min-h-[200px]">
            <div class="px-6 py-4 border-b border-slate-700 bg-slate-900/50 flex justify-between items-center">
                <h3 class="font-bold text-white flex items-center gap-2">
                    Execuções Carregadas
                    <span class="bg-slate-700 text-slate-300 text-xs py-1 px-2 rounded-full" x-text="executions.length"></span>
                </h3>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-400">
                    <thead class="bg-slate-900 text-slate-200 uppercase text-xs font-bold tracking-wider">
                        <tr>
                            <th class="px-6 py-4">Workflow</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4">Início</th>
                            <th class="px-6 py-4">Fim / Duração</th>
                            <th class="px-6 py-4 text-right">Ação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                        <template x-for="exec in executions" :key="exec.id">
                            <tr class="hover:bg-slate-700/30 transition-colors">
                                <td class="px-6 py-4 text-white text-xs">
                                    <span x-text="getWorkflowName(exec.workflowId)" class="opacity-80"></span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-3 py-1 rounded-full text-xs font-bold"
                                          :class="getStatusClass(exec)">
                                        <span x-text="getStatusLabel(exec)"></span>
                                    </span>
                                </td>
                                <td class="px-6 py-4 font-mono text-white">
                                    <span x-text="formatDate(exec.startedAt)"></span>
                                </td>
                                <td class="px-6 py-4 font-mono">
                                    <span x-text="formatDuration(exec)"></span>
                                </td>
                                <td class="px-6 py-4 text-right flex justify-end gap-2">
                                    <button @click="viewExecution(exec.id)"
                                            class="bg-slate-700 hover:bg-blue-600 text-white px-3 py-1 rounded text-xs font-medium transition-colors"
                                            title="Ver dados brutos (JSON)">
                                        JSON
                                    </button>
                                    <a :href="'<?php echo $n8nUrl; ?>/workflow/' + exec.workflowId + '/executions/' + exec.id" 
                                       target="_blank"
                                       class="bg-slate-700 hover:bg-orange-600 text-white px-3 py-1 rounded text-xs font-medium transition-colors">
                                        Abrir &rarr;
                                    </a>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="executions.length === 0 && !loading">
                            <td colspan="4" class="px-6 py-12 text-center text-slate-500">
                                Nenhuma execução encontrada.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Loading indicator de fundo -->
            <div x-show="loading" class="p-4 text-center text-slate-400 text-sm italic">
                Carregando mais dados...
            </div>
             <div x-show="!hasMore && executions.length > 0" class="p-4 text-center text-slate-500 text-xs uppercase font-bold">
                Fim dos resultados
            </div>
        </div>
        
        </div>
        
        <!-- Modal JSON Detalhado -->
        <div x-show="modalOpen" style="display: none;" 
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
             x-transition.opacity>
            <div class="bg-slate-900 rounded-xl shadow-2xl w-full max-w-6xl max-h-[90vh] flex flex-col border border-slate-700" 
                 @click.outside="modalOpen = false">
                
                <!-- Cabeçalho Modal -->
                <div class="p-4 border-b border-slate-700 flex justify-between items-center bg-slate-800 rounded-t-xl">
                    <h3 class="font-bold text-white text-lg flex items-center gap-2">
                        Detalhes da Execução
                        <span x-show="modalId" class="text-sm font-light text-slate-400 font-mono" x-text="'#'+modalId"></span>
                    </h3>
                    <div class="flex gap-2">
                       <button @click="modalTab = 'nodes'" 
                               :class="modalTab === 'nodes' ? 'bg-orange-600 text-white' : 'bg-slate-700 text-slate-300 hover:bg-slate-600'"
                               class="px-3 py-1 rounded text-xs font-bold transition-colors">
                           Nodes
                       </button>
                       <button @click="modalTab = 'json'" 
                               :class="modalTab === 'json' ? 'bg-orange-600 text-white' : 'bg-slate-700 text-slate-300 hover:bg-slate-600'"
                               class="px-3 py-1 rounded text-xs font-bold transition-colors">
                           Raw JSON
                       </button>
                       <button @click="modalOpen = false" class="ml-4 text-slate-400 hover:text-white text-xl">&times;</button>
                    </div>
                </div>

                <!-- Conteúdo Modal -->
                <div class="p-0 overflow-hidden flex-1 flex flex-col bg-slate-900">
                    
                    <!-- Loading -->
                    <div x-show="modalLoading" class="flex-1 flex flex-col justify-center items-center py-12">
                        <span class="animate-spin h-10 w-10 border-4 border-orange-500 border-t-transparent rounded-full inline-block"></span>
                        <p class="mt-4 text-slate-400 font-medium">Carregando dados da execução...</p>
                    </div>

                    <!-- Conteúdo Principal -->
                    <div x-show="!modalLoading && modalData" class="flex-1 overflow-auto custom-scroll p-6">
                        
                        <!-- TAB: NODES VIEW -->
                        <div x-show="modalTab === 'nodes'" class="space-y-4">
                            
                            <!-- Resumo Topo (Reactive Fix) -->
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                                <div class="bg-slate-800 p-3 rounded border border-slate-700">
                                    <div class="text-xs text-slate-400 uppercase font-bold">Status</div>
                                    <div class="text-lg font-bold" :class="getExecutionInfo().statusClass" x-text="getExecutionInfo().status"></div>
                                </div>
                                <div class="bg-slate-800 p-3 rounded border border-slate-700">
                                    <div class="text-xs text-slate-400 uppercase font-bold">Duração Total</div>
                                    <div class="text-lg text-white font-mono" x-text="getExecutionInfo().duration"></div>
                                </div>
                                <div class="bg-slate-800 p-3 rounded border border-slate-700">
                                    <div class="text-xs text-slate-400 uppercase font-bold">Início</div>
                                    <div class="text-sm text-white font-mono mt-1" x-text="getExecutionInfo().startedAt"></div>
                                </div>
                                <div class="bg-slate-800 p-3 rounded border border-slate-700">
                                    <div class="text-xs text-slate-400 uppercase font-bold">Fim</div>
                                    <div class="text-sm text-white font-mono mt-1" x-text="getExecutionInfo().stoppedAt"></div>
                                </div>
                            </div>
                            
                            <h4 class="text-slate-300 font-bold mb-2 border-b border-slate-700 pb-2">Timeline de Execução (Nodes)</h4>

                            <!-- Container das Abas de Nodes -->
                            <div class="flex flex-col bg-slate-900 rounded-lg border border-slate-700 overflow-hidden" 
                                 x-effect="if (!activeNode && getFirstNodeName()) activeNode = getFirstNodeName()">
                                
                                <!-- Barra de Abas (Horizontal Scroll) -->
                                <div class="flex overflow-x-auto custom-scroll border-b border-slate-700 bg-slate-950/50 pt-2 px-2 gap-1 mb-0 shrink-0">
                                    <template x-for="(nodeRuns, nodeName) in getCleanRunData()" :key="nodeName">
                                        <button @click="activeNode = nodeName"
                                            class="flex items-center gap-2 px-4 py-2 text-xs font-bold border-t border-r border-l rounded-t transition-all whitespace-nowrap min-w-[120px] max-w-[250px] justify-center relative top-[1px]"
                                            :class="activeNode === nodeName 
                                                ? 'bg-slate-800 border-slate-700 text-white shadow-sm' 
                                                : 'bg-transparent border-transparent text-slate-500 hover:text-slate-300 hover:bg-slate-800/30'"
                                        >
                                            <!-- Status Dot -->
                                            <div class="w-2 h-2 shrink-0 rounded-full" 
                                                 :class="isNodeSuccess(nodeRuns) ? 'bg-green-500 shadow-[0_0_5px_rgba(34,197,94,0.5)]' : 'bg-red-500 shadow-[0_0_5px_rgba(239,68,68,0.5)]'"></div>
                                            <span x-text="nodeName" class="truncate"></span>
                                        </button>
                                    </template>
                                </div>

                                <!-- Área de Conteúdo do Nó Selecionado -->
                                <div class="p-0 bg-slate-800 min-h-[300px]">
                                    
                                    <template x-for="(nodeRuns, nodeName) in getCleanRunData()" :key="nodeName">
                                        <div x-show="activeNode === nodeName" class="p-4 animate-fadeIn">
                                            
                                            <!-- Header do Nó Individual -->
                                            <div class="flex justify-between items-start mb-4">
                                                <div>
                                                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                                                        <span x-text="nodeName"></span>
                                                        <span class="text-xs font-normal text-slate-400 px-2 py-0.5 rounded border border-slate-600" x-text="nodeRuns.length + ' execução(ões)'"></span>
                                                    </h3>
                                                    <div class="text-xs text-slate-400 mt-1">
                                                        Tempo total no nó: <span class="text-orange-400 font-mono" x-text="formatNodeDuration(nodeRuns) + 'ms'"></span>
                                                    </div>
                                                </div>
                                                
                                                <!-- Status Badge Grande -->
                                                <div class="px-3 py-1 rounded text-xs font-bold uppercase tracking-wider border"
                                                     :class="isNodeSuccess(nodeRuns) ? 'bg-green-900/40 text-green-400 border-green-800' : 'bg-red-900/40 text-red-400 border-red-800'">
                                                    <span x-text="isNodeSuccess(nodeRuns) ? 'Sucesso' : 'Erro'"></span>
                                                </div>
                                            </div>

                                            <!-- Erros do Nó -->
                                            <template x-if="getErrorFromRun(nodeRuns)">
                                                <div class="bg-red-950/50 border border-red-500/30 p-4 rounded-lg mb-6 shadow-inner">
                                                    <div class="text-red-400 font-bold text-xs uppercase mb-2 flex items-center gap-2">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                                        Erro na Execução
                                                    </div>
                                                    <pre class="text-red-200 text-xs whitespace-pre-wrap font-mono bg-black/30 p-3 rounded" x-text="getErrorFromRun(nodeRuns)"></pre>
                                                </div>
                                            </template>

                                            <!-- Lista de Execuções do Nó -->
                                            <div class="space-y-6">
                                                <template x-for="(run, idx) in nodeRuns" :key="idx">
                                                    <div class="bg-slate-900 rounded-lg border border-slate-700 shadow-sm overflow-hidden" 
                                                         x-data="{ activeItem: 0, outputData: getOutputData(run) }">
                                                        
                                                        <!-- Header da Execução Individual -->
                                                        <div class="bg-slate-950 px-4 py-2 border-b border-slate-800 flex justify-between items-center">
                                                            <div class="flex items-center gap-3">
                                                                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Run <span x-text="idx + 1"></span></span>
                                                                
                                                                <!-- Status da Run Específica -->
                                                                <span class="w-2 h-2 rounded-full" :class="(!run.error && run.executionStatus !== 'error') ? 'bg-green-500' : 'bg-red-500'"></span>
                                                            </div>
                                                            <span class="text-xs font-mono text-slate-500" x-text="run.executionTime + 'ms'"></span>
                                                        </div>

                                                        <!-- Tabs de Itens (OUTPUT DATA) -->
                                                        <div class="bg-[#1e293b] px-2 pt-2 border-b border-slate-700 flex flex-wrap gap-1" x-show="outputData.length > 0">
                                                            <template x-for="(item, i) in outputData" :key="i">
                                                                <button @click="activeItem = i" 
                                                                        class="px-3 py-1.5 rounded-t text-[10px] font-bold uppercase tracking-wider transition-all border-t border-l border-r relative top-[1px]"
                                                                        :class="activeItem === i 
                                                                            ? 'bg-slate-900 text-orange-400 border-slate-700 border-b-slate-900' 
                                                                            : 'bg-transparent text-slate-400 border-transparent hover:bg-slate-800 hover:text-slate-200'">
                                                                    Item <span x-text="i"></span>
                                                                </button>
                                                            </template>
                                                        </div>
                                                        
                                                        <!-- Conteúdo JSON -->
                                                        <div class="p-0 bg-slate-900 overflow-x-auto min-h-[100px]" style="max-height: 500px">
                                                            <div class="p-4 custom-scroll">
                                                                <template x-if="outputData.length > 0 && outputData[activeItem]">
                                                                    <div class="text-xs font-mono leading-relaxed text-slate-300 code-viewer">
                                                                         <div x-html="formatJsonVisual(outputData[activeItem].json)"></div>
                                                                    </div>
                                                                </template>
                                                                
                                                                <template x-if="outputData.length === 0">
                                                                    <div class="flex flex-col items-center justify-center py-8 text-slate-600">
                                                                        <span class="text-xs italic">Void Output (Nenhum dado retornado)</span>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </div>

                                                    </div>
                                                </template>
                                            </div>

                                        </div>
                                    </template>
                                    
                                    <!-- Fallback se não houver nodes -->
                                    <div x-show="!getCleanRunData() || Object.keys(getCleanRunData()).length === 0" class="flex flex-col items-center justify-center h-full min-h-[300px] text-slate-500">
                                        <svg class="w-12 h-12 mb-4 opacity-20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.384-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
                                        <p>Nenhum dado de execução de node disponível.</p>
                                    </div>

                                </div>
                            </div>


                        <!-- TAB: RAW JSON -->
                        <div x-show="modalTab === 'json'">
                            <div class="bg-slate-950 p-4 rounded-lg border border-slate-800">
                                 <pre class="json-pre text-green-400 text-xs" x-text="JSON.stringify(modalData, null, 2)"></pre>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </div>

    <script>
        function app() {
            return {
                workflows: <?php echo $jsWorkflows; ?>,
                params: {
                    workflowId: '<?php echo $defaultWorkflowId; ?>',
                    startDate: '<?php echo $defaultStartDate; ?>',
                    endDate: '<?php echo $defaultEndDate; ?>',
                    status: '<?php echo $defaultStatus; ?>'
                },
                executions: [],
                nextCursor: null,
                loading: false,
                hasMore: true,
                errorMsg: null,
                
                // Modal State
                modalOpen: false,
                modalLoading: false,
                modalData: null,
                modalId: null,
                modalTab: 'nodes',
                activeNode: null,

                initApp() {
                    // Carrega automaticamente ao iniciar, mesmo sem filtros
                    this.search(true);
                },

                async search(reset = false) {
                    if (reset) {
                        this.executions = [];
                        this.nextCursor = null;
                        this.hasMore = true;
                    }

                    // Se carregando ou sem mais dados, para. 
                    // REMOVIDO: || !this.params.workflowId (permite busca global)
                    if (!this.hasMore || this.loading) return;

                    this.loading = true;
                    this.errorMsg = null;

                    try {
                        // Monta params básicos
                        const paramsObj = {
                            ajax_data: '1',
                            start_date: this.params.startDate,
                            end_date: this.params.endDate,
                            status: this.params.status,
                        };
                        
                        // Adiciona opcionais
                        if (this.params.workflowId) paramsObj.workflowId = this.params.workflowId;
                        if (this.nextCursor) paramsObj.cursor = this.nextCursor;

                        const qs = new URLSearchParams(paramsObj);

                        const res = await fetch('?' + qs.toString());
                        const json = await res.json();

                        if (res.status !== 200) {
                            throw new Error(json.message || 'Erro na API');
                        }

                        if (json.data && json.data.length > 0) {
                            this.executions = [...this.executions, ...json.data];
                        } else {
                            // Se veio vazio mas tem cursor, talvez o filtro removeu tudo desta página.
                            // Para manter o infinite scroll simples, se não vier nada, paramos ou
                            // teríamos que fazer recursão. Vamos assumir que se o usuário rolar e não vier nada, ele tenta de novo.
                        }

                        this.nextCursor = json.nextCursor;
                        if (!this.nextCursor) {
                            this.hasMore = false;
                        } else if (json.data.length === 0) {
                            // Se retornou vazio mas TEM cursor, tenta buscar a próxima pagina automaticamente
                            // para não travar o scroll do usuário num "buraco" de dados filtrados
                            this.loading = false; 
                            this.search(false);
                            return; 
                        }

                    } catch (e) {
                        console.error(e);
                        this.errorMsg = e.message;
                    } finally {
                        this.loading = false;
                    }
                },

                async viewExecution(id) {
                    this.modalId = id;
                    this.modalOpen = true;
                    this.modalLoading = true;
                    this.modalData = null;
                    this.modalTab = 'nodes';
                    this.activeNode = null;

                    try {
                        const res = await fetch('?execution_details=1&execution_id=' + id);
                        const json = await res.json();
                        
                        // Tenta simplificar a visualização focando nos dados (resultData) se existirem
                        // Mas mostra tudo se preferir. Vamos mostrar a estrutura completa.
                        this.modalData = json;
                    } catch (e) {
                        this.modalData = { error: 'Falha ao buscar detalhes: ' + e.message };
                    } finally {
                        this.modalLoading = false;
                    }
                },

                // --- Helpers para o Modal de Detalhes ---

                getFirstNodeName() {
                    const data = this.getCleanRunData();
                    const keys = Object.keys(data);
                    return keys.length > 0 ? keys[0] : null;
                },

                getCleanRunData() {
                    if (!this.modalData || !this.modalData.data || !this.modalData.data.resultData || !this.modalData.data.resultData.runData) {
                        return {};
                    }
                    return this.modalData.data.resultData.runData;
                },
                
                getExecutionInfo() {
                    const d = this.modalData;
                    if(!d) return {};
                    
                    let status = 'Unknown';
                    let statusClass = 'text-slate-400';
                    
                    if(!d.finished) {
                        status = 'Running';
                        statusClass = 'text-blue-400';
                    } else if(d.status === 'success' || (!d.status && d.finished)) {
                        status = 'Success';
                        statusClass = 'text-green-400';
                    } else {
                        status = 'Error';
                        statusClass = 'text-red-400';
                    }
                    
                    let duration = '-';
                    if(d.startedAt && d.stoppedAt) {
                        const s = moment(d.startedAt);
                        const e = moment(d.stoppedAt);
                        duration = e.diff(s, 'seconds', true).toFixed(2) + 's';
                    }

                    return {
                        status,
                        statusClass,
                        duration,
                        startedAt: d.startedAt ? moment(d.startedAt).utcOffset('-03:00').format('DD/MM/YYYY HH:mm:ss') : '-',
                        stoppedAt: d.stoppedAt ? moment(d.stoppedAt).utcOffset('-03:00').format('DD/MM/YYYY HH:mm:ss') : '-'
                    };
                },
                
                isNodeSuccess(runs) {
                    return !runs.some(r => r.error || r.executionStatus === 'error');
                },
                
                getNodeStatusClass(runs) {
                    return this.isNodeSuccess(runs) 
                        ? 'text-green-400 bg-green-900/20 p-1 rounded-full' 
                        : 'text-red-400 bg-red-900/20 p-1 rounded-full';
                },
                
                formatNodeDuration(runs) {
                    const total = runs.reduce((acc, r) => acc + (r.executionTime || 0), 0);
                    return total.toFixed(0);
                },

                getErrorFromRun(runs) {
                    const errRun = runs.find(r => r.error);
                    if(errRun && errRun.error) {
                         if (errRun.error.message) return errRun.error.message;
                         return JSON.stringify(errRun.error, null, 2);
                    }
                    return null;
                },

                getOutputData(run) {
                    if (run.data && run.data.main && run.data.main.length > 0) {
                        return run.data.main[0] || [];
                    }
                    return [];
                },

                formatJsonVisual(data) {
                    if (data === null) return '<span class="text-slate-500 italic">null</span>';
                    if (typeof data === 'undefined') return '<span class="text-slate-500 italic">undefined</span>';
                    if (typeof data === 'boolean') return `<span class="text-purple-400 font-bold">${data}</span>`;
                    if (typeof data === 'number') return `<span class="text-orange-400">${data}</span>`;
                    if (typeof data === 'string') {
                        // Limita strings muito longas visualmente se desejar, mas por enquanto exibe inteira
                        return `<span class="text-emerald-300">"${data.replace(/"/g, '&quot;').replace(/</g, '&lt;')}"</span>`;
                    }
                    
                    if (Array.isArray(data)) {
                        if (data.length === 0) return '<span class="text-slate-600">[]</span>';
                        let html = '<div class="flex flex-col gap-1 mt-1 border-l border-slate-700/50 pl-2">';
                        data.forEach((item, index) => {
                            html += `<div class="flex items-start">
                                        <span class="text-slate-600 mr-2 text-[10px] py-[1px] select-none">[${index}]</span>
                                        <div class="flex-1">${this.formatJsonVisual(item)}</div>
                                     </div>`;
                        });
                        html += '</div>';
                        return html;
                    }
                    
                    if (typeof data === 'object') {
                        const keys = Object.keys(data);
                        if (keys.length === 0) return '<span class="text-slate-600">{}</span>';
                        
                        let html = '<div class="flex flex-col mt-0.5 border-l-2 border-slate-800 hover:border-slate-700 transition-colors pl-3 ml-0.5">';
                        keys.forEach(key => {
                            const val = data[key];
                            const isComplex = typeof val === 'object' && val !== null && (Object.keys(val).length > 0 || (Array.isArray(val) && val.length > 0));
                            
                            html += `<div class="group">`;
                            // Chave
                            html += `<span class="text-sky-300/90 font-semibold mb-0.5 inline-block text-[11px]">${key}</span>`;
                            
                            if (isComplex) {
                                html += `<div class="mb-1">${this.formatJsonVisual(val)}</div>`; 
                            } else {
                                html += `<span class="text-slate-500 mx-1">:</span> <span class="break-all">${this.formatJsonVisual(val)}</span>`;
                            }
                            html += `</div>`;
                        });
                        html += '</div>';
                        return html;
                    }
                    return String(data);
                },

                handleScroll() {
                    const scrollPosition = window.innerHeight + window.scrollY;
                    const threshold = document.body.offsetHeight - 1000; // ~20 linhas (50px cada)

                    if (scrollPosition >= threshold) {
                        this.search(false);
                    }
                },

                // Helpers de UI
                getWorkflowName(id) {
                    const w = this.workflows.find(w => w.id === id);
                    return w ? w.name : id;
                },
                getStatusClass(exec) {
                    if (!exec.stoppedAt) return 'bg-blue-900/50 text-blue-200 border border-blue-700 animate-pulse';
                    if (exec.finished === true) return 'bg-green-900/50 text-green-200 border border-green-700';
                    return 'bg-red-900/50 text-red-200 border border-red-700';
                },
                getStatusLabel(exec) {
                    if (!exec.stoppedAt) return 'Running';
                    if (exec.finished === true) return 'Success';
                    return 'Error';
                },
                formatDate(isoStr) {
                    return moment(isoStr).utcOffset('-03:00').format('DD/MM/YYYY HH:mm:ss');
                },
                formatDuration(exec) {
                    if (!exec.stoppedAt) return '-';
                    const start = moment(exec.startedAt);
                    const end = moment(exec.stoppedAt);
                    const diff = end.diff(start, 'seconds');
                    return moment(exec.stoppedAt).utcOffset('-03:00').format('DD/MM HH:mm') + ' (' + diff + 's)';
                }
            }
        }
    </script>
</body>
</html>