<?php
/**
 * - Wordt aangeroepen via Mollie webhook bij abonnement-renewal.
 * - Heractiveert alle abilities voor de bestaande client-API-key
 *   en laat de sleutel bewaard in sales_order.client_api_key.
 */
namespace Prtct\Provisioning\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Prtct\Provisioning\Model\ApiKeyService;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class ProvisionSubscriptionObserver implements ObserverInterface
{
    public function __construct(
        private ApiKeyService            $apiKeyService,  // Service om API-calls te maken
        private OrderRepositoryInterface $orderRepo,      // Repository om orders op te halen/op te slaan
        private LoggerInterface          $logger          // Logger om berichten in de log te zetten
    ) {}

    public function execute(Observer $observer)
    {
        // 1) Lees de webhook-payload uit het event
        $payload = $observer->getEvent()->getData('webhook_payload');
        // 2) Haal de subscription_id eruit (komt van Mollie)
        $subId = $payload['subscription_id'] ?? null;
        if (! $subId) {
            // Als er geen ID is, stoppen we hier
            return;
        }

        // 3) Probeer de order op te halen met increment_id = subscription_id
        try {
            $order = $this->orderRepo->get($subId);
        } catch (\Exception $e) {
            // Als de order niet bestaat, schrijven we een fout en stoppen we
            $this->logger->error("Order met ID {$subId} niet gevonden voor re-provisioning.");
            return;
        }

        // 4) Lees de bestaande client-API-key uit de order
        //    via extension attributes of direct kolom
        $clientKey = $order->getExtensionAttributes()
                           ? $order->getExtensionAttributes()->getClientApiKey()
                           : $order->getData('client_api_key');
        if (! $clientKey) {
            $this->logger->error("Geen client API-key gevonden op order #{$subId} voor reactivation.");
            return;
        }

        // 5) Roep de service aan om alle abilities weer in te schakelen
        $abilities = [
            'health:check',
            'pass:check',
            'userpass:check',
            'statistics:get'
        ];
        $success = $this->apiKeyService->changeAbilities($clientKey, $abilities);
        if (! $success) {
            $this->logger->error("Kon abilities niet reactivaten voor key {$clientKey}.");
            return;
        }

        // 6) Log een informatief bericht
        $this->logger->info("Client key {$clientKey} opnieuw geactiveerd voor order #{$subId}.");
        // 7) (Optioneel) je hoeft de order zelf niet opnieuw op te slaan als de kolom
        //    client_api_key ongewijzigd blijft. Maar je zou hier extra flags kunnen zetten.
    }
}
