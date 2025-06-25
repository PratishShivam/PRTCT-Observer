<?php
namespace Prtct\Provisioning\Cron;

use Mollie\Api\MollieApiClient;
use Prtct\Provisioning\Model\ApiKeyService;
use Prtct\Provisioning\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class SubscriptionCheck
{
    public function __construct(
        private MollieApiClient            $mollieClient,
        private ApiKeyService              $apiKeyService,
        private OrderCollectionFactory     $orderCollectionFactory,
        private OrderRepositoryInterface   $orderRepo,
        private LoggerInterface            $logger
    ) {}

    public function execute()
    {
        $this->logger->info('Cron: Mollie subscription check gestart');

        // 1) Haal alle orders met een token
        $collection = $this->orderCollectionFactory->create()
            ->addFieldToFilter('client_api_key', ['notnull' => true]);

        foreach ($collection as $order) { // Loop door alle orders met een client_api_key
            $subId     = $order->getIncrementId();  // Mollie subscription_id is opgeslagen als increment_id
            
            // 2) Doorloop door alle orders
            $this->logger->info("Cron: Verwerk order #{$subId}"); 

            try {
                $subscription = $this->mollieClient // Haal de Mollie subscription op
                                      ->subscriptions 
                                      ->get($subId); 
                $status = $subscription->status; // Status van de subscription
            $this->logger->info("Cron: Mollie subscription #{$subId} status={$status}");
            } catch (\Mollie\Api\Exceptions\ApiException $e) {
                $this->logger->error("Cron: Mollie subscription #{$subId} niet gevonden: {$e->getMessage()}");
                continue; // Ga door naar de volgende order
            } catch (\Exception $e) {
                $this->logger->error("Cron: Mollie subscription #{$subId} niet gevonden: {$e->getMessage()}");
                continue;
            }

            // 3) Als niet meer actief, intrekken en provisioned uitzetten
            if ($status !== 'active') {
                $clientKey = $order->getData('client_api_key'); 
                $this->apiKeyService->changeAbilities($clientKey, []);
                $order->setData('provisioned', 0);
                $this->orderRepo->save($order);
                $this->logger->info("Cron: order #{$subId} gedeprovisioneerd (status={$status}).");
            }
        }

        $this->logger->info('Cron: Mollie subscription check voltooid');
    }
}
