<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class SigeClient {
    private string $token;
    private string $user;
    private string $app;
    private string $baseUrl;

    public function __construct() {
        $this->token = getConfig('sige_token', DEFAULT_SIGE_TOKEN);
        $this->user = getConfig('sige_user', DEFAULT_SIGE_USER);
        $this->app = getConfig('sige_app', DEFAULT_SIGE_APP);
        $this->baseUrl = rtrim(SIGE_BASE_URL, '/');
    }

    public function request(string $endpoint, string $method = 'GET', array $params = [], $body = null, int $maxRetries = 2) {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $headers = [
            'authorization-token: ' . $this->token,
            'user: ' . $this->user,
            'app: ' . $this->app,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $attempt = 0;
        $lastError = '';
        $httpCode = 0;
        $response = false;

        while ($attempt <= $maxRetries) {
            $attempt++;
            $startTime = microtime(true);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if ($body !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
                }
            } elseif ($method === 'PUT') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($body !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
                }
            }

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            curl_close($ch);

            // Log de requisições lentas (> 3.5s)
            if ($durationMs > 3500) {
                wmsLog('WARNING', "SIGE Cloud API lenta: $endpoint ({$durationMs}ms)");
            }

            if ($curlError) {
                $lastError = 'Erro de conexão cURL: ' . $curlError;
                wmsLog('WARNING', "Falha na conexão cURL com SIGE Cloud (Tentativa $attempt/$maxRetries): $curlError");
            } elseif ($httpCode >= 500 && $httpCode <= 599) {
                $lastError = "SIGE Cloud retornou HTTP $httpCode";
                wmsLog('WARNING', "SIGE Cloud instável (Tentativa $attempt/$maxRetries): HTTP $httpCode");
            } else {
                // Requisição respondeu com status válido (2xx, 3xx, 4xx)
                $decoded = json_decode($response, true);
                return [
                    'success' => ($httpCode >= 200 && $httpCode < 300),
                    'status' => $httpCode,
                    'data' => $decoded !== null ? $decoded : $response,
                    'raw' => $response,
                    'latency_ms' => $durationMs
                ];
            }

            // Se for tentar novamente, aguardar backoff exponencial (300ms, 600ms)
            if ($attempt <= $maxRetries) {
                usleep($attempt * 300000);
            }
        }

        // Se esgotou as tentativas
        wmsLog('ERROR', "Esgotadas tentativas de conexão com SIGE Cloud ($endpoint): $lastError");
        return [
            'success' => false,
            'status' => $httpCode ?: 504,
            'error' => $lastError ?: 'Falha na comunicação com SIGE Cloud após múltiplas tentativas.'
        ];
    }

    public function pesquisarPedidos(array $filters = []): array {
        $params = [];
        if (!empty($filters['codigo'])) $params['codigo'] = (int)$filters['codigo'];
        if (!empty($filters['cliente'])) $params['cliente'] = $filters['cliente'];
        if (!empty($filters['status'])) $params['status'] = $filters['status'];
        if (!empty($filters['dataInicial'])) $params['dataInicial'] = $filters['dataInicial'];
        if (!empty($filters['dataFinal'])) $params['dataFinal'] = $filters['dataFinal'];
        if (!empty($filters['filtrarPor'])) $params['filtrarPor'] = $filters['filtrarPor'];
        
        $params['pageSize'] = isset($filters['pageSize']) ? (int)$filters['pageSize'] : 50;
        $params['skip'] = isset($filters['skip']) ? (int)$filters['skip'] : 0;

        $res = $this->request('request/pedidos/pesquisar', 'GET', $params);
        return $res;
    }

    public function obterPedidoPorCodigo(int $codigo): ?array {
        $res = $this->pesquisarPedidos(['codigo' => $codigo, 'pageSize' => 1]);
        if ($res['success'] && is_array($res['data']) && count($res['data']) > 0) {
            return $res['data'][0];
        }
        return null;
    }

    public function obterProduto(string $codigo = '', string $ean = ''): ?array {
        $params = [];
        if (!empty($codigo)) $params['codigo'] = $codigo;
        if (!empty($ean)) $params['ean'] = $ean;
        
        if (empty($params)) return null;

        $res = $this->request('request/produtos/get', 'GET', $params);
        if ($res['success'] && is_array($res['data'])) {
            return $res['data'];
        }
        return null;
    }

    public function testarConexao(): array {
        $res = $this->request('request/pedidos/pesquisar', 'GET', ['pageSize' => 1]);
        return [
            'success' => $res['success'],
            'status' => $res['status'],
            'message' => $res['success'] ? 'Conexão com SIGE Cloud estabelecida com sucesso!' : 'Falha na autenticação com SIGE Cloud. Verifique suas credenciais.'
        ];
    }
}
