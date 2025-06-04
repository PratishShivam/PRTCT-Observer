<?php
namespace Prtct\Provisioning\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Prtct\Provisioning\Model\ApiKeyService;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Psr\Log\LoggerInterface;

class SubscriptionCreated implements ObserverInterface
{
    public function __construct(
        private ApiKeyService            $apiKeyService,
        private OrderRepositoryInterface $orderRepo,
        private OrderExtensionFactory    $extFactory,
        private LoggerInterface          $logger
    ) {}

    public function execute(Observer $observer)
    {
        // 1) Haal de order uit het event (checkout_submit_all_after)
        $order = $observer->getEvent()->getOrder()
               ?: current($observer->getEvent()->getOrders());
        if (! $order) {
            $this->logger->error('SubscriptionCreated: geen order-gegevens in event.');
            return;
        }

        $incrementId = $order->getIncrementId();
        $this->logger->info("SubscriptionCreated: start provisioning voor order #{$incrementId}");

        // 2) Vraag client-API-key aan bij de externe PRTCT-API
        $clientKey = $this->apiKeyService->createClientKey([
            'customer_email'  => $order->getCustomerEmail(),
            'subscription_id' => $incrementId,
            'plan'            => 'tier1',
            'status'          => 'active'
        ]);
        if (! $clientKey) {
            $this->logger->error("SubscriptionCreated: geen client API-key ontvangen voor order #{$incrementId}");
            return;
        }

        // 3) Sla client_api_key en provisioned op in sales_order via extension attributes
        $extension = $this->extFactory->create();
        $extension->setClientApiKey($clientKey);
        $extension->setProvisioned(true);

        $order->setExtensionAttributes($extension);
        // Sluit ook de “onderliggende kolom” in sales_order aan
        $order->setData('client_api_key', $clientKey);
        $order->setData('provisioned', 1);

        // 4) Sla de order op
        $this->orderRepo->save($order);
        $this->logger->info("SubscriptionCreated: order #{$incrementId} provisioned en client key opgeslagen.");
    }
}
