<?php
// app/code/Prtct/Provisioning/Model/ApiKeyService.php
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
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->curl        = $curl;
        $this->scopeConfig = $scopeConfig;
        $this->logger      = $logger;

        // Lees base-URL en master-sleutel uit admin-config
        $this->apiUrl    = rtrim($scopeConfig->getValue('prtct_provisioning/general/api_url'), '/');
        $this->masterKey = $scopeConfig->getValue('prtct_provisioning/general/api_key');
    }

    /**
     * Health-check: GET /api/v1/health/check
     */
    public function healthCheck(): bool
    {
        $url = "{$this->apiUrl}/api/v1/health/check";
        try {
            $this->curl->get($url);
            return ($this->curl->getStatus() === 200);
        } catch (\Exception $e) {
            $this->logger->error("Health check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Creëer een client-sleutel: POST /api/v1/apikey/create
     *
     * @param array $payload
     * @return string|null
     */
    public function createClientKey(array $payload): ?string
    {
        $url  = "{$this->apiUrl}/api/v1/apikey/create";
        $json = json_encode($payload);

        // Stel headers in
        $this->curl->addHeader('Authorization', "Bearer {$this->masterKey}");
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->post($url, $json);

        if ($this->curl->getStatus() !== 200) {
            $this->logger->error("Failed to create client key, HTTP " . $this->curl->getStatus());
            return null;
        }

        $response = json_decode($this->curl->getBody(), true);
        return $response['data']['api_key'] ?? null;
    }

    /**
     * Wijzig abilities: PUT /api/v1/apikey/change/abilities
     *
     * @param string $clientKey
     * @param array  $abilities
     * @return bool
     */
    public function changeAbilities(string $clientKey, array $abilities): bool
    {
        $url = "{$this->apiUrl}/api/v1/apikey/change/abilities";
        $payload = json_encode([
            'client_api_key' => $clientKey,
            'abilities'      => $abilities,
        ]);

        $this->curl->addHeader('Authorization', "Bearer {$this->masterKey}");
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->put($url, $payload);

        if ($this->curl->getStatus() !== 200) {
            $this->logger->error("Failed to change abilities for {$clientKey}, HTTP " . $this->curl->getStatus());
            return false;
        }
        return true;
    }
}
