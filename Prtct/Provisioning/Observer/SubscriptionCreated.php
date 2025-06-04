<?php
/**
 * - Wordt aangeroepen na een succesvolle bestelling.
 * - Maakt een client-API-key aan en slaat deze op in sales_order.client_api_key.
 * - Markeert de order als “provisioned”.
 */
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
        private ApiKeyService            $apiKeyService,   // Om HTTP-calls te doen
        private OrderRepositoryInterface $orderRepo,       // Om de order op te slaan
        private OrderExtensionFactory    $extFactory,      // Om extension attributes te maken
        private LoggerInterface          $logger           // Voor logging
    ) {}

    public function execute(Observer $observer)
    {
        // 1) Haal de order op uit het event
        $order = $observer->getEvent()->getOrder()
               ?: current($observer->getEvent()->getOrders());
        $incrementId = $order->getIncrementId();
        $this->logger->info("Start provisioning for order #{$incrementId}");

        // 2) Vraag client-API-key aan
        $clientKey = $this->apiKeyService->createClientKey([
            'customer_email'  => $order->getCustomerEmail(),  
            'subscription_id' => $incrementId,                // Order ID als subscription ID
            'plan'            => 'tier1',                     // Plan, pas aan op basis van SKU
            'status'          => 'active'
        ]);
        if (! $clientKey) {
            $this->logger->error("Geen client API-key ontvangen voor order #{$incrementId}");
            return;  // Stop als er geen sleutel is aangemaakt
        }

        // 3) Extension attribute-object aanmaken
        $extension = $this->extFactory->create();            // a) Maak nieuw extension-obj
        $extension->setClientApiKey($clientKey);             // b) Zet de sleutel erin
        $order->setExtensionAttributes($extension);          // c) Koppel aan order

        // 4) Sla de order op, incl. nieuwe kolom in sales_order
        $this->orderRepo->save($order);
        $this->logger->info("Order #{$incrementId} provisioned; client key opgeslagen.");
    }
}
