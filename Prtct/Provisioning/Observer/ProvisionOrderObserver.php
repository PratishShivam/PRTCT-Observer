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
        ScopeConfigInterface $scopeConfig,
        LoggerInterface      $logger,
        OrderRepositoryInterface $orderRepository,
        ResourceConnection   $resourceConnection
    ) {
        $this->scopeConfig        = $scopeConfig;
        $this->logger             = $logger;
        $this->orderRepository    = $orderRepository;
        $this->resourceConnection = $resourceConnection;
    }

    public function execute(Observer $observer)
    {
        // 1) Grab the order and its customer-visible ID
        $order   = $observer->getEvent()->getOrder();
        $orderId = $order->getIncrementId();

        $this->logger->info("Start provisioning for order #{$orderId}");    

        // 2) ONLY skip if we've already provisioned this order
        if ($order->getData('provisioned')) {
            $this->logger->info("Skip order #{$orderId} (already provisioned)");
            return;
        }

        // 3) Read API URL/key from Magento settings
        $apiUrl = $this->scopeConfig->getValue('prtct_provisioning/general/api_url');
        $apiKey = $this->scopeConfig->getValue('prtct_provisioning/general/api_key');
        if (!$apiUrl || !$apiKey) {
            $this->logger->warning('API URL or Key missing');
            return;
        }

        // 4) Build JSON payload: email, subscription ID, plan, and “active” status
        $email          = $order->getCustomerEmail();
        $subscriptionId = $orderId;
        // Grab the SKU from the order items
            $skuPlanMap = [
                'tier-1' => 'tier1',
                'tier-2' => 'tier2',
                'tier-3' => 'tier3',
            ];
            
            $plan = 'tier1'; // fallback
            foreach ($order->getAllVisibleItems() as $item) {
                $sku = $item->getSku();         // tier-1, tier-2, tier-3
                if (isset($skuPlanMap[$sku])) {
                    $plan = $skuPlanMap[$sku];  // maps 'tier-2' → 'tier2'
                    break;
                }
            }
    

        $payload = json_encode([
            'customer_email'  => $email,
            'subscription_id' => $subscriptionId,
            'plan'            => $plan,
            'status'          => 'active',
        ]);
        $this->logger->info("Payload: {$payload}");

        // 5) Send via cURL
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$apiKey}",
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $this->logger->error('cURL error: ' . curl_error($ch));
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->logger->info("Response [HTTP {$httpCode}]: {$response}");

        // 6) On HTTP 200, mark as provisioned inside a DB transaction
        if ($httpCode === 200) {
            $connection = $this->resourceConnection->getConnection();
            $connection->beginTransaction();
            try {
                $order->setData('provisioned', true);
                $this->orderRepository->save($order);
                $connection->commit();
                $this->logger->info("Order #{$orderId} provisioned successfully");
            } catch (\Throwable $e) {
                $connection->rollBack();
                $this->logger->error("Provisioning failed for order #{$orderId}: " . $e->getMessage());
                throw $e;
            }
        }
    }
}
