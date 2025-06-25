<?php
namespace Prtct\Provisioning\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class ApiKeyService
{
    private Curl                 $curl;
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface      $logger;
    private string               $apiUrl;
    private string               $masterKey;

    public function __construct(
        Curl                 $curl,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface      $logger
    ) {
        $this->curl        = $curl;
        $this->scopeConfig = $scopeConfig;
        $this->logger      = $logger;

        // BASE-URL en Master-Api-Key worden gelezen uit de configuratie
        $this->apiUrl    = rtrim((string)$scopeConfig->getValue('prtct_provisioning/general/api_url'), '/');
        $this->masterKey = (string)$scopeConfig->getValue('prtct_provisioning/general/api_key');
    }

    /**
     * healthCheck(): GET /api/v1/health/check
     */
    public function healthCheck(): bool
    {
        if (empty($this->apiUrl)) {
            $this->logger->error("PRTCT healthCheck: API URL ontbreekt.");
            return false;
        }
        $url = "{$this->apiUrl}/api/v1/health/check";

        try {
            $this->curl->get($url);
            $status = $this->curl->getStatus();
            $this->logger->info("PRTCT healthCheck: HTTP {$status}");
            return ($status === 200);
        } catch (\Exception $e) {
            $this->logger->error("PRTCT healthCheck exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * createClientKey(): POST /api/v1/apikey/create
     *
     * @param array $abilities  ['health:check', 'pass:check', ...]
     * @return string|null      De accessToken (bv. "21|xxx…") of null bij fout
     */
    public function createClientKey(array $abilities): ?string
    {
        if (empty($this->apiUrl) || empty($this->masterKey)) {
            $this->logger->error("PRTCT createClientKey: API URL of masterKey ontbreekt.");
            return null;
        }

        $url  = "{$this->apiUrl}/api/v1/apikey/create";
        // Abilities zijn een JSON-string
        $body = json_encode([
            'abilities' => json_encode($abilities) // Omzetten naar JSON-string
        ]);

        // DEBUG: log URL & payload
        $this->logger->info("PRTCT:createClientKey → URL: {$url}");
        $this->logger->info("PRTCT:createClientKey → Body: {$body}");

        $this->curl->addHeader('Authorization', "Bearer {$this->masterKey}"); // Master API Key in de header
        $this->curl->addHeader('Content-Type', 'application/json'); // Content-Type is JSON
        try {
            $this->curl->post($url, $body); // POST request met JSON-body & payload
        } catch (\Exception $e) {
            $this->logger->error("PRTCT:createClientKey exception: " . $e->getMessage());
            return null;
        }

        $status = $this->curl->getStatus();
        $resp   = $this->curl->getBody();
        $this->logger->info("PRTCT:createClientKey → HTTP {$status}");
        $this->logger->debug("PRTCT:createClientKey → Response body: {$resp}");

        if ($status !== 200) {
            $this->logger->error("PRTCT:createClientKey failed: HTTP {$status}");
            return null;
        }

        $data = json_decode((string)$resp, true);
        return $data['accessToken'] ?? null;
    }

    /**
     * changeAbilities(): PUT /api/v1/apikey/change/abilities
     *
     * @param string $clientKey   De token ("21|…")
     * @param array  $abilities   ['health:check',…]
     * @return bool
     */
    public function changeAbilities(string $clientKey, array $abilities): bool
    {
        if (empty($this->apiUrl) || empty($this->masterKey)) {
            $this->logger->error("PRTCT changeAbilities: API URL of masterKey ontbreekt.");
            return false;
        }

        // parse de numeric ID vóór de pipe
        $apiKeyId = (int) strtok($clientKey, '|');
        $url      = "{$this->apiUrl}/api/v1/apikey/change/abilities";
        $body     = json_encode([
            'apiKeyId'  => $apiKeyId,
            'abilities' => $abilities
        ]);

        $this->logger->info("PRTCT:changeAbilities → URL: {$url}");
        $this->logger->info("PRTCT:changeAbilities → Body: {$body}");

        $this->curl->addHeader('Authorization', "Bearer {$this->masterKey}");
        $this->curl->addHeader('Content-Type', 'application/json');
        try {
            $this->curl->put($url, $body);
        } catch (\Exception $e) {
            $this->logger->error("PRTCT:changeAbilities exception: " . $e->getMessage());
            return false;
        }

        $status = $this->curl->getStatus();
        $this->logger->info("PRTCT:changeAbilities → HTTP {$status}");
        return ($status === 200);
    }
}
