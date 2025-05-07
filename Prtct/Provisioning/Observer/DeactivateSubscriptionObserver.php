<?php
namespace Prtct\Provisioning\Observer;

use Magento\Framework\Event\ObserverInterface;    // Run code on an event
use Magento\Framework\Event\Observer;             // Access event data
use Magento\Framework\App\Config\ScopeConfigInterface;  // Read API URL/key from settings
use Psr\Log\LoggerInterface;                      // Log messages

class DeactivateSubscriptionObserver implements ObserverInterface
{
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface      $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        LoggerInterface      $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger      = $logger;
    }

    // Runs when a “cancellation” webhook arrives
    public function execute(Observer $observer)
    {
        // 1) Grab the webhook’s payload (customer email, subscription ID, plan)
        $data = $observer->getEvent()->getData('webhook_payload');
        $this->logger->info('Start cancel, payload: ' . json_encode($data));

        // 2) Read API URL/key
        $apiUrl = $this->scopeConfig->getValue('prtct_provisioning/general/api_url');
        $apiKey = $this->scopeConfig->getValue('prtct_provisioning/general/api_key');
        if (!$apiUrl || !$apiKey) {
            $this->logger->warning('API URL or Key missing for cancellation');
            return;
        }

        // 3) Prepare JSON with “canceled” status
        $payload = json_encode([
            'customer_email'  => $data['customer_email'] ?? '',
            'subscription_id' => $data['id']              ?? '',
            'plan'            => $data['plan']            ?? 'default',
            'status'          => 'canceled',
        ]);
        $this->logger->info("Cancel payload: {$payload}");

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
            $this->logger->error('cURL error on cancel: ' . curl_error($ch));
        }
        curl_close($ch);

        // 5) Log the response so we can check later
        $this->logger->info("Cancel response: {$response}");
    }
}
