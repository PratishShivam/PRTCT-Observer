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

        foreach ($collection as $order) {
            $subId     = $order->getIncrementId();
            $clientKey = $order->getData('client_api_key');

            try {
                $subscription = $this->mollieClient
                                      ->subscriptions
                                      ->get($subId);
                $status = $subscription->status;
            } catch (\Exception $e) {
                $this->logger->error("Cron: Mollie subscription #{$subId} niet gevonden: {$e->getMessage()}");
                continue;
            }

            // 2) Als niet meer actief, intrekken en provisioned uitzetten
            if ($status !== 'active') {
                $this->apiKeyService->changeAbilities($clientKey, []);
                $order->setData('provisioned', 0);
                $this->orderRepo->save($order);
                $this->logger->info("Cron: order #{$subId} gedeprovisioneerd (status={$status}).");
            }
        }

        $this->logger->info('Cron: Mollie subscription check voltooid');
    }
}
