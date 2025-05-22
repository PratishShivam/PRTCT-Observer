<?php
/**
 */
namespace Prtct\Provisioning\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class ApiKeyService
{
    private Curl                 $curl;         // Magento’s HTTP-client
    private ScopeConfigInterface $scopeConfig; // Om admin-config te lezen
    private LoggerInterface      $logger;      // Voor log-uitvoer
    private string               $apiUrl;      // Basis-URL van PRTCT
    private string               $masterKey;   // Master API Key

    public function __construct(
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->curl        = $curl;                   // Slaat HTTP-client op
        $this->scopeConfig = $scopeConfig;            // Slaat config-reader op
        $this->logger      = $logger;                 // Slaat logger op

        // Lees basisgegevens uit admin-config (system.xml)
        $this->apiUrl    = rtrim($scopeConfig->getValue('prtct_provisioning/general/api_url'), '/');
        $this->masterKey = $scopeConfig->getValue('prtct_provisioning/general/api_key');
    }

    /**
     * healthCheck()
     * - Doet een GET naar /api/v1/health/check en kijkt of HTTP 200 terugkomt.
     */
    public function healthCheck(): bool
    {
        $url = "{$this->apiUrl}/api/v1/health/check";    // Endpoint samenstellen
        try {
            $this->curl->get($url);                       // Voer GET-request uit
            return ($this->curl->getStatus() === 200);    // True bij 200, anders false
        } catch (\Exception $e) {
            $this->logger->error("Health check failed: " . $e->getMessage());
            return false;                                 // Bij fout false teruggeven
        }
    }

    /**
     * createClientKey()
     * - Maakt met de masterKey een client-sleutel aan bij PRTCT.
     * - Verwacht een array met data (email, subId, plan, status).
     * - Retourneert de gegenereerde client-API-key of null bij fout.
     */
    public function createClientKey(array $payload): ?string
    {
        $url  = "{$this->apiUrl}/api/v1/apikey/create";   // API-endpoint
        $json = json_encode($payload);                    // Zet array om naar JSON

        // Voeg headers toe, o.a. de Authorization-header met masterKey
        $this->curl->addHeader('Authorization', "Bearer {$this->masterKey}");
        $this->curl->addHeader('Content-Type', 'application/json');

        $this->curl->post($url, $json);                   // Voer POST-request uit

        // Als het geen HTTP 200 is, log error en return null
        if ($this->curl->getStatus() !== 200) {
            $this->logger->error("Create client key failed: HTTP " . $this->curl->getStatus());
            return null;
        }

        // Lees response-body en haal de sleutel eruit
        $response = json_decode($this->curl->getBody(), true);
        return $response['data']['api_key'] ?? null;
    }

    /**
     * changeAbilities()
     * - Past de lijst van abilities (rechten) aan voor een bestaande clientKey.
     * - Stuurt een PUT naar /api/v1/apikey/change/abilities met een array abilities.
     */
    public function changeAbilities(string $clientKey, array $abilities): bool
    {
        $url = "{$this->apiUrl}/api/v1/apikey/change/abilities";
        $payload = json_encode([
            'client_api_key' => $clientKey,
            'abilities'      => $abilities,
        ]);

        // Stel dezelfde headers in
        $this->curl->addHeader('Authorization', "Bearer {$this->masterKey}");
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->put($url, $payload);                  // Voer PUT-request uit

        // Controleer HTTP-status en return true/false
        if ($this->curl->getStatus() !== 200) {
            $this->logger->error("Change abilities failed: HTTP " . $this->curl->getStatus());
            return false;
        }
        return true;
    }
}
