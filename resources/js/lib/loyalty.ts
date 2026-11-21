/** Built by App\Support\Loyalty::forCart(); null when the programme is off. */
export type LoyaltyInfo = {
    balance: number | null;
    spend_per_point: string;
    point_value: string;
    min_redeem_points: number;
    max_redeem_percent: number;
};

/**
 * A preview of what the server will decide at checkout (App\Support\Loyalty and
 * PlaceOrder). The server checks everything again; this only shows numbers.
 */
export function pointsPreview(
    loyalty: LoyaltyInfo,
    payable: number,
    requested: number,
): { max: number; used: number; discount: number; earn: number } {
    const value = Number(loyalty.point_value);
    const spend = Number(loyalty.spend_per_point);
    const balance = loyalty.balance ?? 0;
    const byShare =
        value > 0
            ? Math.floor((payable * loyalty.max_redeem_percent) / 100 / value)
            : 0;
    const max = Math.max(0, Math.min(balance, byShare));
    const used = Math.max(0, Math.min(Math.floor(requested) || 0, max));
    const discount = Math.min(used * value, payable);
    const earn =
        spend > 0 ? Math.floor(Math.max(payable - discount, 0) / spend) : 0;

    return { max, used, discount, earn };
}
