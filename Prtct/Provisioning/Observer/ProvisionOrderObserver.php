<?php
namespace Prtct\Provisioning\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\App\ResourceConnection;

class ProvisionOrderObserver implements ObserverInterface
{
    private ScopeConfigInterface      $scopeConfig;
    private LoggerInterface           $logger;
    private OrderRepositoryInterface  $orderRepository;
    private ResourceConnection        $resourceConnection;

    public function __construct(
        ScopeConfigInterface         $scopeConfig,
        LoggerInterface              $logger,
        OrderRepositoryInterface     $orderRepository,
        ResourceConnection           $resourceConnection
    ) {
        $this->scopeConfig        = $scopeConfig;
        $this->logger             = $logger;
        $this->orderRepository    = $orderRepository;
        $this->resourceConnection = $resourceConnection;
    }

    public function execute(Observer $observer)
    {
        // 1) Haal de order op uit het event (sales_order_place_after)
        $order   = $observer->getEvent()->getOrder();
        if (! $order || ! $order->getId()) {
            $this->logger->error("ProvisionOrderObserver: geen order-gegevens gevonden.");
            return;
        }
        $orderId = $order->getIncrementId();
        $this->logger->info("ProvisionOrderObserver: start provisioning voor order #{$orderId}");

        // 2) Skip als al provisioned
        if ((int)$order->getData('provisioned') === 1) {
            $this->logger->info("ProvisionOrderObserver: skip order #{$orderId} (al provisioned)");
            return;
        }

        // 3) Lees API-URL en Key uit admin-config
        $apiUrl = (string)$this->scopeConfig->getValue('prtct_provisioning/general/api_url');
        $apiKey = (string)$this->scopeConfig->getValue('prtct_provisioning/general/api_key');
        if (! $apiUrl || ! $apiKey) {
            $this->logger->warning("ProvisionOrderObserver: API URL of API Key ontbreekt.");
            return;
        }

        // 4) Bouw payload: email, subscription_id, plan, status
        $email          = $order->getCustomerEmail();
        $subscriptionId = $orderId;
        // Bepaal plan op basis van SKU (hier een simpele map)
        $skuPlanMap = [
            'tier-1' => 'tier1',
            'tier-2' => 'tier2',
            'tier-3' => 'tier3',
        ];
        $plan = 'tier1'; // fallback
        foreach ($order->getAllVisibleItems() as $item) {
            $sku = $item->getSku();
            if (isset($skuPlanMap[$sku])) {
                $plan = $skuPlanMap[$sku];
                break;
            }
        }

        $payload = json_encode([
            'customer_email'  => $email,
            'subscription_id' => $subscriptionId,
            'plan'            => $plan,
            'status'          => 'active',
        ]);
        $this->logger->info("ProvisionOrderObserver: payload = {$payload}");

        // 5) Verstuur verzoek naar juiste PRTCT-endpoint
        $endpoint = rtrim($apiUrl, '/') . '/api/v1/apikey/create';
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$apiKey}",
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $this->logger->error('ProvisionOrderObserver: cURL error: ' . curl_error($ch));
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->logger->info("ProvisionOrderObserver: Response [HTTP {$httpCode}]: {$response}");

        // 6) Als HTTP 200, lees de client_key uit de respons en sla op
        if ($httpCode === 200) {
            $respData  = json_decode($response, true);
            $clientKey = $respData['data']['api_key'] ?? null;
            if (! $clientKey) {
                $this->logger->error("ProvisionOrderObserver: geen client API-key in response voor order #{$orderId}.");
                return;
            }

            $connection = $this->resourceConnection->getConnection();
            $connection->beginTransaction();
            try {
                $order->setData('client_api_key', $clientKey);
                $order->setData('provisioned', 1);
                $this->orderRepository->save($order);
                $connection->commit();
                $this->logger->info("ProvisionOrderObserver: order #{$orderId} succesvol provisioned (key = {$clientKey}).");
            } catch (\Throwable $e) {
                $connection->rollBack();
                $this->logger->error("ProvisionOrderObserver: provisioning mislukt voor order #{$orderId}: " . $e->getMessage());
                throw $e;
            }
        }
    }
}
