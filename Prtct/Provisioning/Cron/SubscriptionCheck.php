<?php
namespace Prtct\Provisioning\Cron;

use Mollie\Api\MollieApiClient;
use Prtct\Provisioning\Model\ApiKeyService;
use Prtct\Provisioning\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;

class SubscriptionCheck
{
    public function __construct(
        private MollieApiClient        $mollieClient,            // Mollie SDK voor subscription checks
        private ApiKeyService          $apiKeyService,           // PRTCT-service om abilities in te trekken
        private OrderCollectionFactory $orderCollectionFactory,  // Orders met een client_api_key
        private LoggerInterface        $logger                   // Loggen van succes/fouten
    ) {}

    public function execute()
    {
        $this->logger->info('Cron: Mollie subscription check gestart');

        // 1) Haal alle orders met een actieve client_api_key
        $collection = $this->orderCollectionFactory->create()
            ->addFieldToFilter('client_api_key', ['notnull' => true]);

        foreach ($collection as $order) {
            $subId     = $order->getIncrementId();               // Mollie subscription_id = increment_id
            $clientKey = $order->getData('client_api_key');      // PRTCT client key

            try {
                // 2) Vraag de subscription op bij Mollie
                $subscription = $this->mollieClient
                                      ->subscriptions
                                      ->get($subId);

                $status = $subscription->status; //'active', 'canceled', 'expired'

            } catch (\Exception $e) {
                // Mollie gaf geen geldige respons: log en ga verder met volgende order
                $this->logger->error("Cron: Mollie subscription #{$subId} niet gevonden: {$e->getMessage()}");
                continue;
            }

            // 3) Als de status niet 'active' is, intrekken in PRTCT en clearen in de order
            if ($status !== 'active') {
                // a) Revoke alle abilities bij PRTCT
                $this->apiKeyService->changeAbilities($clientKey, []);

                // b) Verwijder client_api_key en markeer provisioned = 0
                $order->setData('client_api_key', null);
                $order->setData('provisioned', 0);
                $order->save();

                $this->logger->info("Cron: order #{$subId} gedeactiveerd (Mollie-status = {$status}).");
            }
        }

        $this->logger->info('Cron: Mollie subscription check voltooid');
    }
}
