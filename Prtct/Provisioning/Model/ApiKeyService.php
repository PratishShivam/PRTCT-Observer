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
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->curl        = $curl;
        $this->scopeConfig = $scopeConfig;
        $this->logger      = $logger;

        // Lees de instellingen uit admin-config
        $this->apiUrl    = rtrim((string)$scopeConfig->getValue('prtct_provisioning/general/api_url'), '/');
        $this->masterKey = (string)$scopeConfig->getValue('prtct_provisioning/general/api_key');
    }

    /**
     * healthCheck(): doet een GET naar /api/v1/health/check en kijkt of HTTP 200 terugkomt.
     */
    public function healthCheck(): bool
    {
        if (empty($this->apiUrl)) {
            $this->logger->error("PRTCT: API URL is leeg in config.");
            return false;
        }
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
     * createClientKey(): maakt met de masterKey een client-API-key aan bij PRTCT.
     * Verwacht een payload array met:
     *   - customer_email
     *   - subscription_id
     *   - plan
     *   - status
     * Retourneert de string api_key, of null als mislukking.
     */
    public function createClientKey(array $payload): ?string
    {
        if (empty($this->apiUrl) || empty($this->masterKey)) {
            $this->logger->error("PRTCT createClientKey: API URL of masterKey ontbreekt.");
            return null;
        }

        $url  = "{$this->apiUrl}/api/v1/apikey/create";
        $json = json_encode($payload);

        $this->curl->addHeader('Authorization', "Bearer {$this->masterKey}");
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->post($url, $json);

        if ($this->curl->getStatus() !== 200) {
            $this->logger->error("Create client key failed: HTTP " . $this->curl->getStatus());
            return null;
        }

        $response = json_decode((string)$this->curl->getBody(), true);
        return $response['data']['api_key'] ?? null;
    }

    /**
     * changeAbilities(): past de lijst van abilities (rechten) aan voor een bestaande clientKey.
     * Stuurt een PUT naar /api/v1/apikey/change/abilities met:
     *   - client_api_key
     *   - abilities (array)
     */
    public function changeAbilities(string $clientKey, array $abilities): bool
    {
        if (empty($this->apiUrl) || empty($this->masterKey)) {
            $this->logger->error("PRTCT changeAbilities: API URL of masterKey ontbreekt.");
            return false;
        }
        $url = "{$this->apiUrl}/api/v1/apikey/change/abilities";
        $payload = json_encode([
            'client_api_key' => $clientKey,
            'abilities'      => $abilities,
        ]);

        $this->curl->addHeader('Authorization', "Bearer {$this->masterKey}");
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->put($url, $payload);

        if ($this->curl->getStatus() !== 200) {
            $this->logger->error("Change abilities failed: HTTP " . $this->curl->getStatus());
            return false;
        }
        return true;
    }

    /**
     * checkSubscriptionStatus(): roept GET aan op /api/v1/subscription/status/{subId}
     * Geeft de status terug (bijv. 'active', 'canceled') of null bij fout.
     */
    public function checkSubscriptionStatus(string $subId): ?string
    {
        if (empty($this->apiUrl) || empty($this->masterKey)) {
            $this->logger->error("PRTCT checkSubscriptionStatus: API URL of masterKey ontbreekt.");
            return null;
        }
        $url = "{$this->apiUrl}/api/v1/subscription/status/{$subId}";
        try {
            $this->curl->get($url);
            if ($this->curl->getStatus() !== 200) {
                $this->logger->error("checkSubscriptionStatus mislukt: HTTP " . $this->curl->getStatus());
                return null;
            }
            $resp = json_decode((string)$this->curl->getBody(), true);
            return $resp['data']['status'] ?? null; // b.v. 'active', 'canceled'
        } catch (\Exception $e) {
            $this->logger->error("Exception in checkSubscriptionStatus: " . $e->getMessage());
            return null;
        }
    }
}
