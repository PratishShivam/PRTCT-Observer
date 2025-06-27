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
        // 1) Pak de order uit het event
        $order = $observer->getEvent()->getOrder() 
               ?: current($observer->getEvent()->getOrders()); 
        if (! $order) {
            $this->logger->error('SubscriptionCreated: geen order in event.');
            return;
        }

        $incrementId = $order->getIncrementId();
        $this->logger->info("SubscriptionCreated: provisioning voor order #{$incrementId}");

        try {
            // 2) Abilities van PRTCT API
            $abilities = [
                'health:check',
                'pass:check',
                'userpass:check',
                'statistics:get'
            ];

            // 3) Vraag een nieuwe client key (accessToken) aan
            $token = $this->apiKeyService->createClientKey($abilities);
            if (! $token) {
                $this->logger->error("SubscriptionCreated: geen token ontvangen voor order #{$incrementId}");
                return;
            }

            // 4) Sla de token en provisioned-flag op in sales_order
            $extension = $this->extFactory->create(); // Maak een nieuwe extensie voor de order
            $extension->setClientApiKey($token); // Sla de token op in de extensie
            $extension->setProvisioned(true);

            $order->setExtensionAttributes($extension);
            $order->setData('client_api_key', $token);
            $order->setData('provisioned', 1);
            $this->orderRepo->save($order);

            $this->logger->info("SubscriptionCreated: token opgeslagen voor order #{$incrementId}");
        } catch (\Exception $e) {
            // Vang elke fout op zodat de checkout wél door kan
            $this->logger->error(
                "SubscriptionCreated: fout bij provisioning voor order #{$incrementId}: " 
                . $e->getMessage()
            );
        }
    }
}
