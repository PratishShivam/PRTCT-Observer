<?php
namespace Prtct\Provisioning\Observer;

use Magento\Framework\Event\ObserverInterface;    // To run code on a specific event
use Magento\Framework\Event\Observer;             // Gives access to event data
use Magento\Framework\App\Config\ScopeConfigInterface;  // To read the API URL/key from settings
use Psr\Log\LoggerInterface;                      // To log what’s happening

class ProvisionSubscriptionObserver implements ObserverInterface
{
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface      $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        LoggerInterface      $logger
    ) {
        // Store config reader and logger for later use
        $this->scopeConfig = $scopeConfig;
        $this->logger      = $logger;
    }

    // Runs when a “renewal” webhook event arrives
    public function execute(Observer $observer)
    {
        // 1) Get the webhook data (customer email, subscription ID, etc.)
        $data = $observer->getEvent()->getData('webhook_payload');
        $this->logger->info('Start renewal, payload: ' . json_encode($data));

        // 2) Read API settings
        $apiUrl = $this->scopeConfig->getValue('prtct_provisioning/general/api_url');
        $apiKey = $this->scopeConfig->getValue('prtct_provisioning/general/api_key');
        if (!$apiUrl || !$apiKey) {
            $this->logger->warning('API URL or Key missing for renewal');
            return;
        }

        // 3) Prepare JSON: keep same plan, mark status “active”
        $payload = json_encode([
            'customer_email'  => $data['customer_email'] ?? '',
            'subscription_id' => $data['id']             ?? '',
            'plan'            => $data['plan']           ?? 'default',
            'status'          => 'active',
        ]);
        $this->logger->info("Renewal payload: {$payload}");

        // 4) Send to API via cURL 
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$apiKey}",
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $this->logger->error('cURL error on renewal: ' . curl_error($ch));
        }
        curl_close($ch);

        // 5) Log the API’s reply for troubleshooting
        $this->logger->info("Renewal response: {$response}");
    }
}
