<?php

namespace App\Domain\Client;

use App\Models\MarketSubscription;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ClientMarketAccess
{
    public function owned(User $user, string $subscriptionId): MarketSubscription
    {
        $subscription = MarketSubscription::query()->where('user_id', $user->user_id)
            ->with('market.exchange', 'market.feed', 'market.latestSignal', 'clientSetting')
            ->find($subscriptionId);

        if ($subscription === null) {
            throw new NotFoundHttpException('Unknown market subscription.');
        }

        return $subscription;
    }

    public function active(User $user, string $subscriptionId): MarketSubscription
    {
        $subscription = $this->owned($user, $subscriptionId);
        abort_unless($subscription->active, 409, 'This market subscription is inactive. New trade decisions fail closed.');

        return $subscription;
    }
}
