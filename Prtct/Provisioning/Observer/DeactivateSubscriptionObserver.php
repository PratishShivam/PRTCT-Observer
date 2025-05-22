<?php
/**
 * - Wordt aangeroepen via Mollie webhook bij abonnementscancel.
 * - Neemt alle abilities weg en verwijdert client_api_key uit order.
 */
namespace Prtct\Provisioning\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Prtct\Provisioning\Model\ApiKeyService;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class DeactivateSubscriptionObserver implements ObserverInterface
{
    public function __construct(
        private ApiKeyService            $apiKeyService, // HTTP-service
        private OrderRepositoryInterface $orderRepo,      // Om order op te halen/op te slaan
        private LoggerInterface          $logger         // Voor logging
    ) {}

    public function execute(Observer $observer)
    {
        // 1) Lees subscription_id uit webhook payload
        $payload   = $observer->getEvent()->getData('webhook_payload');
        $subId     = $payload['subscription_id'] ?? null;
        if (! $subId) {
            return;  // Stop als subId niet aanwezig
        }

        // 2) Haal order op via increment_id = subscription_id
        try {
            $order = $this->orderRepo->get($subId);
        } catch (\Exception $e) {
            $this->logger->error("Order #{$subId} niet gevonden.");
            return;
        }

        // 3) Revoke alle abilities
        $clientKey = $order->getExtensionAttributes()->getClientApiKey();
        $this->apiKeyService->changeAbilities($clientKey, []);  // lege array = alles uit

        // 4) Verwijder client_api_key uit de order
        $extension = $order->getExtensionAttributes();
        $extension->setClientApiKey(null);
        $order->setExtensionAttributes($extension);

        // 5) Sla bijgewerkte order op
        $this->orderRepo->save($order);
        $this->logger->info("Order #{$subId}: client key gedeactiveerd en verwijderd.");
    }
}
