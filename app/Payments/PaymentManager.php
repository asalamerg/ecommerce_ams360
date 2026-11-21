<?php

namespace App\Payments;

use App\Payments\Gateways\FakeGateway;
use InvalidArgumentException;

/**
 * Resolves the configured payment gateway. Online payment is on only when
 * config('payments.default') names a gateway that exists, and the test
 * gateway can never be used in production.
 */
class PaymentManager
{
    /** @var array<string, class-string<PaymentGateway>> */
    private const array GATEWAYS = [
        'fake' => FakeGateway::class,
        // 'grow' => GrowGateway::class,
    ];

    public function enabled(): bool
    {
        return $this->defaultName() !== null;
    }

    public function defaultName(): ?string
    {
        $name = (string) config('payments.default');

        return $this->usable($name) ? $name : null;
    }

    public function default(): PaymentGateway
    {
        return $this->gateway((string) $this->defaultName());
    }

    public function gateway(string $name): PaymentGateway
    {
        if (! $this->usable($name)) {
            throw new InvalidArgumentException("Payment gateway [{$name}] is not available.");
        }

        return app(self::GATEWAYS[$name]);
    }

    public function usable(string $name): bool
    {
        if (! isset(self::GATEWAYS[$name])) {
            return false;
        }

        return ! (app()->isProduction() && self::GATEWAYS[$name]::forTestingOnly());
    }

    /**
     * @return list<string>
     */
    public function browserOrigins(): array
    {
        return $this->enabled() ? $this->default()->browserOrigins() : [];
    }
}
