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
        if (empty($this->apiUrl)) { // Controleer of de API URL is ingesteld
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
    public function createClientKey(array $payload): ?string // Verwacht een array met de benodigde gegevens
    {
        if (empty($this->apiUrl) || empty($this->masterKey)) { // Controleer of de API URL en masterKey zijn ingesteld
            $this->logger->error("PRTCT createClientKey: API URL of masterKey ontbreekt.");
            return null;
        }

        $url  = "{$this->apiUrl}/api/v1/apikey/create"; // Endpoint voor het aanmaken van een client key
        $json = json_encode($payload); // Zet de payload om naar JSON

        $this->curl->addHeader('Authorization', "Bearer {$this->masterKey}"); // Voeg de masterKey toe als Authorization header
        $this->curl->addHeader('Content-Type', 'application/json'); // Zet de Content-Type header op application/json
        $this->curl->post($url, $json); // Stuur een POST-verzoek naar de API met de JSON payload

        if ($this->curl->getStatus() !== 200) { 
            $this->logger->error("Create client key failed: HTTP " . $this->curl->getStatus());
            return null;
        }

        $response = json_decode((string)$this->curl->getBody(), true); // Decode de JSON response van de API
        return $response['data']['api_key'] ?? null; // Retourneer de api_key uit de response, of null als deze niet bestaat
    }

    /**
     * changeAbilities(): past de lijst van abilities (rechten) aan voor een bestaande clientKey.
     * Stuurt een PUT naar /api/v1/apikey/change/abilities met:
     *   - client_api_key
     *   - abilities (array)
     */
    public function changeAbilities(string $clientKey, array $abilities): bool // Verwacht een client API key en een array van abilities
    {
        if (empty($this->apiUrl) || empty($this->masterKey)) { // Controleer of de API URL en masterKey zijn ingesteld
            $this->logger->error("PRTCT changeAbilities: API URL of masterKey ontbreekt.");
            return false;
        }
        $url = "{$this->apiUrl}/api/v1/apikey/change/abilities"; // Endpoint voor het aanpassen van abilities
        $payload = json_encode([ // Maak de payload aan als JSON
            'client_api_key' => $clientKey,
            'abilities'      => $abilities,
        ]);

        $this->curl->addHeader('Authorization', "Bearer {$this->masterKey}"); // Voeg de masterKey toe als Authorization header
        $this->curl->addHeader('Content-Type', 'application/json'); // Zet de Content-Type header op application/json
        $this->curl->put($url, $payload); // Stuur een PUT-verzoek naar de API met de JSON payload

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
    public function checkSubscriptionStatus(string $subId): ?string // Verwacht een subscription ID als string
    {
        if (empty($this->apiUrl) || empty($this->masterKey)) { // Controleer of de API URL en masterKey zijn ingesteld
            $this->logger->error("PRTCT checkSubscriptionStatus: API URL of masterKey ontbreekt.");
            return null;
        }
        $url = "{$this->apiUrl}/api/v1/subscription/status/{$subId}"; // Endpoint voor het controleren van de abonnementsstatus
        try {
            $this->curl->get($url); // Stuur een GET-verzoek naar de API
            if ($this->curl->getStatus() !== 200) {
                $this->logger->error("checkSubscriptionStatus mislukt: HTTP " . $this->curl->getStatus());
                return null;
            }
            $resp = json_decode((string)$this->curl->getBody(), true); // Decode de JSON response van de API
            return $resp['data']['status'] ?? null; // b.v. 'active', 'canceled' / 'expired'
        } catch (\Exception $e) { 
            $this->logger->error("Exception in checkSubscriptionStatus: " . $e->getMessage());
            return null;
        }
    }
}
